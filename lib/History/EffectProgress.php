<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\History;

/** Bounded internal checkpoints; never a recipe executor or permission grant. */
final class EffectProgress {
	private const STEPS = [
		'nc_mail_save_draft' => ['save_local_draft', 'persist_imap', 'retire_source'],
		'nc_mail_send_draft' => ['stage_outbox', 'send_smtp', 'retire_source'],
	];

	public static function advance(string $operation, array $effects, string $step, string $phase, array $facts = []): array {
		$steps = self::STEPS[$operation] ?? throw new JournalException('invalid_effect', 400);
		$index = array_search($step, $steps, true);
		if ($index === false || !in_array($phase, ['dispatched', 'observed'], true)) {
			throw new JournalException('invalid_effect', 400);
		}
		// The writer owns this bounded structure. Corruption never revives a step.
		if (!array_is_list($effects) || count($effects) > count($steps)) {
			throw new JournalException('invalid_effect', 400);
		}
		foreach ($effects as $i => $effect) {
			if (!is_array($effect) || ($effect['step'] ?? null) !== $steps[$i]
				|| !in_array($effect['phase'] ?? null, ['dispatched', 'observed'], true)
				|| ($i < count($effects) - 1 && $effect['phase'] !== 'observed')) {
				throw new JournalException('invalid_effect', 400);
			}
		}
		if ($phase === 'dispatched') {
			if ($facts !== [] || count($effects) !== $index || ($index > 0 && $effects[$index - 1]['phase'] !== 'observed')) {
				throw new JournalException('effect_already_used');
			}
			$effects[] = ['step' => $step, 'phase' => 'dispatched', 'facts' => []];
		} else {
			if (count($effects) !== $index + 1 || $effects[$index]['phase'] !== 'dispatched') {
				throw new JournalException('effect_already_used');
			}
			if ($facts === [] || array_diff(array_keys($facts), ['nativeId', 'revision', 'status', 'count'])) {
				throw new JournalException('invalid_effect', 400);
			}
			foreach ($facts as $key => $value) {
				if ($key === 'revision' ? !is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)
					: !is_int($value) || $value < ($key === 'nativeId' ? 1 : 0) || $value > 999999999999999) {
					throw new JournalException('invalid_effect', 400);
				}
			}
			$effects[$index] = ['step' => $step, 'phase' => 'observed', 'facts' => $facts];
		}
		return $effects;
	}
}
