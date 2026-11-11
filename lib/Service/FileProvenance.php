<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

use OCA\FadenstackAgent\Identity\CloudApi;
use OCA\FadenstackAgent\Providers\EngineJson;
use OCA\FadenstackAgent\Providers\EngineProvenance;

/** Prepare the final native bytes before approval; sign only hashes, never native text. */
class FileProvenance {
	public function __construct(
		private CloudApi $cloud,
		private EngineProvenance $signer,
		private ProvenanceService $metadata,
	) {
	}

	public function prepare(string $content, string $filename, string $kind, #[\SensitiveParameter] string $token, ?string $readerSha256 = null): array {
		$media = ProvenanceService::mediaType($filename);
		if ($readerSha256 !== null && !preg_match('/^[a-f0-9]{64}$/D', $readerSha256)) {
			throw new Problem('Invalid reader-text digest.');
		}
		$policy = $this->cloud->request($token, 'provenance/policy')['value'];
		if (!in_array($policy['on_missing'] ?? 'record', ['record', 'refuse'], true)
			|| !is_array($policy['exempt_edit_kinds'] ?? []) || !is_bool($policy['internal_technical'] ?? false)
			|| !is_bool($policy['visible_label'] ?? false) || !is_int($policy['short_text_tokens'] ?? 200)) {
			throw new Problem('The file marking policy is unavailable. No proposal was approved.', 503);
		}
		if (($policy['internal_technical'] ?? false) || in_array($kind, $policy['exempt_edit_kinds'] ?? [], true)) {
			return ['content' => $content, 'record' => null, 'editKind' => $kind, 'exempt' => 'edit-kind', 'policy' => $policy];
		}
		// This native path does not attest a generation watermark. A stricter policy
		// must not be silently weakened; visible labels also require a supported recipe.
		$long = (int)ceil(mb_strlen($content, 'UTF-8') / 4) >= ($policy['short_text_tokens'] ?? 200);
		if (($policy['visible_label'] ?? false) || (($policy['on_missing'] ?? 'record') === 'refuse' && $long)) {
			throw new Problem('The native file marking path cannot satisfy this label or watermark policy. No file was changed.', 422);
		}
		$signed = $this->signer->signContent($media, self::normalizeText($content), $kind, $token, $readerSha256);
		$json = EngineJson::encode(array_filter(['contract' => 'provenance/v1', ...$signed], static fn ($value): bool => $value !== null));
		$record = ProvenanceService::parse($json, $media);
		$marked = $media === 'text/markdown' ? FrontMatter::withMark($content, $record->id) : $content;
		if (strlen($marked) > FileService::MAX_WRITE_BYTES) {
			throw new Problem('The complete marked replacement exceeds 256 KiB. Shorten it and review a new proposal.', 413);
		}
		return ['content' => $marked, 'record' => $json, 'editKind' => $kind, 'exempt' => null, 'policy' => $policy];
	}

	public function assertCurrent(array $plan, #[\SensitiveParameter] string $token): void {
		$current = $this->cloud->request($token, 'provenance/policy')['value'];
		if (EngineJson::encode($current) !== EngineJson::encode($plan['policy'])) {
			throw new Problem('The file marking policy changed. Review a new proposal.', 409);
		}
	}

	public function notWritten(array $plan, #[\SensitiveParameter] string $token): void {
		if (!is_string($plan['record'] ?? null) || $token === '') {
			return;
		}
		try {
			$id = ProvenanceService::parse($plan['record'], ProvenanceService::mediaType($plan['filename']))->id;
			$this->cloud->request($token, 'provenance/placements', ['record_id' => $id, 'tool' => $plan['tool'],
				'sink_kind' => 'nextcloud.file', 'media_type' => ProvenanceService::mediaType($plan['filename']),
				'edit_kind' => $plan['editKind'], 'length_class' => 'short', 'layers' => [], 'written' => false, 'failure' => 'NativeApprovalCancelled']);
		} catch (\Throwable) {
			// Cancellation already removed authority. Reporting cannot restore it.
		}
	}

	/** Same text-hash normalization as provenance/v1 and both SDKs; FEFF is not whitespace. */
	public static function normalizeText(string $text): string {
		$normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
		if (!is_string($normalized)) {
			throw new Problem('The file content could not be normalized.', 422);
		}
		$normalized = str_replace(["\x07", "\x01"], '', $normalized);
		return trim(preg_replace('/[\x{0009}-\x{000D}\x{001C}-\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', ' ', $normalized), ' ');
	}

	/** Only a verified native save may claim a stored mark. Metadata failure never resends the save. */
	public function saved(array $plan, int $fileId, string $content, #[\SensitiveParameter] string $token = ''): array {
		$record = $plan['record'] ?? null;
		$kept = is_string($record) && $this->metadata->store($fileId, $record);
		$stored = $this->metadata->stored($fileId);
		$id = is_string($record) ? ProvenanceService::parse($record, ProvenanceService::mediaType($plan['filename']))->id : null;
		if ($id !== null && $token !== '') {
			try {
				$this->cloud->request($token, 'provenance/placements', ['record_id' => $id, 'tool' => $plan['tool'],
					'sink_kind' => 'nextcloud.file', 'media_type' => ProvenanceService::mediaType($plan['filename']),
					'edit_kind' => $plan['editKind'], 'length_class' => strlen($content) < 800 ? 'short' : 'long',
					'layers' => [['layer' => 'metadata', 'method' => 'nextcloud-metadata', 'applied' => $kept, 'verified' => $kept]], 'written' => true]);
			} catch (\Throwable) {
				// The file is committed. Reporting failure must never request another save.
			}
		}
		return ['key' => ProvenanceService::KEY, 'metadata' => $stored,
			'frontMatter' => FrontMatter::head($content), 'exempt' => $plan['exempt'] ?? null, 'recordId' => $id];
	}
}
