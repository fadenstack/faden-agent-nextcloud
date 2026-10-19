<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use Psr\Log\LoggerInterface;

/**
 * Marks the files the agent writes as made with AI (EU AI Act Art. 50(2)): the placement's signed record as Nextcloud
 * file metadata, which stays with the file through renames, moves, shares, new versions, Nextcloud Text's saves and
 * the trash, and shows over WebDAV as `nc:metadata-fadenstack-provenance`. Nextcloud does not copy metadata to a
 * copy; CopyListener does. A record that cannot be kept never undoes a write: the read-back says what the file holds.
 */
class ProvenanceService {
	/** The file metadata key, in the app's namespace. */
	public const KEY = 'fadenstack-provenance';
	public const CONTRACT = 'provenance/v1';
	/** A record is a few kilobytes; Nextcloud keeps at most 100 000 characters of metadata per file. */
	public const MAX_RECORD_BYTES = 32768;
	/** Entries of a copied folder walked for files whose record goes to their copy. */
	public const MAX_COPIED_ENTRIES = 5000;
	private const RECORD_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
	/** A JWS (base64url and dots); the SDK's test backends sign in base64. */
	private const SIGNATURE = '/^[A-Za-z0-9_\-.+\/=]{1,24576}$/D';
	private const FIELDS = ['contract', 'record', 'signature', 'timestamp', 'facts'];

	public function __construct(
		private IFilesMetadataManager $metadata,
		private LoggerInterface $logger,
	) {
	}

	/** The media type the agent's writes have: Markdown for `.md`, plain text for `.txt`. */
	public static function mediaType(string $filename): string {
		return preg_match('/\.md$/iD', $filename) === 1 ? 'text/markdown' : 'text/plain';
	}

	/**
	 * The signed record as the SDK's sidecar document, checked: the contract, a record with the server's kind of id
	 * and the file's media type, a signature, and no other fields. Throws why it is not one.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $json, string $mediaType): SignedRecord {
		if (strlen($json) > self::MAX_RECORD_BYTES) {
			throw new \InvalidArgumentException('longer than ' . self::MAX_RECORD_BYTES . ' bytes');
		}
		try {
			$document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('not JSON');
		}
		if (!is_array($document) || $document === [] || array_is_list($document)) {
			throw new \InvalidArgumentException('not a JSON object');
		}
		if (array_diff(array_keys($document), self::FIELDS) !== []) {
			throw new \InvalidArgumentException('fields besides ' . implode(', ', self::FIELDS));
		}
		if (($document['contract'] ?? null) !== self::CONTRACT) {
			throw new \InvalidArgumentException('not ' . self::CONTRACT);
		}
		$record = $document['record'] ?? null;
		if (!is_array($record) || !isset($record['id']) || !is_string($record['id']) || preg_match(self::RECORD_ID, $record['id']) !== 1) {
			throw new \InvalidArgumentException('no record with an id');
		}
		if (($record['media_type'] ?? null) !== $mediaType) {
			throw new \InvalidArgumentException('a record of another media type');
		}
		$signature = $document['signature'] ?? null;
		if (!is_string($signature) || preg_match(self::SIGNATURE, $signature) !== 1) {
			throw new \InvalidArgumentException('no signature');
		}
		if (array_key_exists('timestamp', $document) && !is_string($document['timestamp'])) {
			throw new \InvalidArgumentException('a time stamp that is not a token');
		}
		if (array_key_exists('facts', $document) && (!is_array($document['facts']) || array_is_list($document['facts']))) {
			throw new \InvalidArgumentException('facts that are not an object');
		}
		return new SignedRecord($record['id'], $json);
	}

	/**
	 * The record a client handed over with a write of `$filename`; null without one, or when it is not one (logged):
	 * the file is then written unmarked rather than not at all.
	 */
	public function record(string $json, string $filename): ?SignedRecord {
		if ($json === '') {
			return null;
		}
		try {
			return self::parse($json, self::mediaType($filename));
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning('A provenance record handed over with a file write was not kept: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * What a write puts into the file: the approved text, or for Markdown with a record the client's text when it is
	 * exactly the approved text with the mark in its front matter (FrontMatter::withMark), never anything else.
	 */
	public function content(string $approved, string $offered, ?SignedRecord $record, string $filename): string {
		if ($record === null || self::mediaType($filename) !== 'text/markdown' || $offered === $approved) {
			return $approved;
		}
		if ($offered === FrontMatter::withMark($approved, $record->id)) {
			return $offered;
		}
		$this->logger->warning('A marked Markdown text was not the approved text with its front matter mark: the approved text is written without it.');
		return $approved;
	}

	/**
	 * Keeps a signed record (the sidecar document's JSON) as the file's metadata, which only the server may change,
	 * and reads it back; true when the metadata holds it. A metadata refresh racing for the same file can win the
	 * write: then once more.
	 */
	public function store(int $fileId, string $json): bool {
		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$metadata = $this->metadata->getMetadata($fileId, true);
				$metadata->setString(self::KEY, $json);
				$metadata->setEditPermission(self::KEY, IMetadataValueWrapper::EDIT_FORBIDDEN);
				$this->metadata->saveMetadata($metadata);
			} catch (\Throwable $e) {
				$this->logger->warning('A provenance record could not be kept as file metadata.', ['exception' => $e]);
				return false;
			}
			if ($this->stored($fileId) === $json) {
				return true;
			}
		}
		$this->logger->warning('Nextcloud did not keep a provenance record as file metadata.');
		return false;
	}

	/** The record the file's metadata holds now, read from the database; null without one. */
	public function stored(int $fileId): ?string {
		try {
			$metadata = $this->metadata->getMetadata($fileId);
			return $metadata->hasKey(self::KEY) ? $metadata->getString(self::KEY) : null;
		} catch (FilesMetadataNotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('The file metadata could not be read.', ['exception' => $e]);
			return null;
		}
	}

	/**
	 * After a copy: the copy of a file the agent wrote gets its record, and so do the copies of such files in a copied
	 * folder (up to MAX_COPIED_ENTRIES entries walked). A failure is logged; the copy stays.
	 */
	public function copy(Node $source, Node $target): void {
		try {
			if ($source instanceof File && $target instanceof File) {
				$json = $source->getId() === $target->getId() ? null : $this->stored($source->getId());
				if ($json !== null) {
					$this->store($target->getId(), $json);
				}
			} elseif ($source instanceof Folder && $target instanceof Folder) {
				$this->copyFolder($source, $target);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Provenance records could not be copied to a copy.', ['exception' => $e]);
		}
	}

	private function copyFolder(Folder $source, Folder $target): void {
		$root = rtrim($source->getPath(), '/') . '/';
		/** @var array<int, string> $files the file ids in the source, and their paths below it */
		$files = [];
		$pending = [$source];
		$seen = 0;
		while (($folder = array_pop($pending)) !== null) {
			foreach ($folder->getDirectoryListing() as $node) {
				if (++$seen > self::MAX_COPIED_ENTRIES) {
					$this->logger->warning('A copied folder has more than ' . self::MAX_COPIED_ENTRIES . ' entries: provenance records were copied for the first only.');
					break 2;
				}
				if ($node instanceof Folder) {
					$pending[] = $node;
				} elseif ($node instanceof File && str_starts_with($node->getPath(), $root)) {
					$files[$node->getId()] = substr($node->getPath(), strlen($root));
				}
			}
		}
		foreach (array_chunk(array_keys($files), 200) as $ids) {
			foreach ($this->metadata->getMetadataForFiles($ids) as $id => $metadata) {
				if (!$metadata->hasKey(self::KEY) || !isset($files[$id])) {
					continue;
				}
				try {
					$copy = $target->get($files[$id]);
				} catch (NotFoundException) {
					continue;
				}
				if ($copy instanceof File) {
					$this->store($copy->getId(), $metadata->getString(self::KEY));
				}
			}
		}
	}
}
