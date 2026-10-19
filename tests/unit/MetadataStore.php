<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;

/**
 * Nextcloud's file metadata in memory, as IFilesMetadataManager behaves: getMetadata reads what was saved (or a new,
 * empty set with $generate), set* changes only that copy, saveMetadata writes it. `$lostSaves` drops saves, as when
 * a concurrent refresh wins the sync token.
 */
trait MetadataStore {
	/** @var array<int, array<string, string>> what the database holds, per file id */
	private array $stored = [];
	/** @var array<int, array<string, int>> */
	private array $editPermissions = [];
	private int $saves = 0;
	private int $lostSaves = 0;

	private function metadataManager(): IFilesMetadataManager {
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->willReturnCallback(function (int $fileId, bool $generate = false): IFilesMetadata {
			if (!isset($this->stored[$fileId]) && !$generate) {
				throw new FilesMetadataNotFoundException('no metadata');
			}
			return $this->metadataOf($fileId);
		});
		$manager->method('getMetadataForFiles')->willReturnCallback(function (array $fileIds): array {
			$found = [];
			foreach ($fileIds as $id) {
				if (isset($this->stored[$id])) {
					$found[$id] = $this->metadataOf($id);
				}
			}
			return $found;
		});
		$manager->method('saveMetadata')->willReturnCallback(function (IFilesMetadata $metadata): void {
			$this->saves++;
			if ($this->lostSaves > 0) {
				$this->lostSaves--;
				return;
			}
			$this->stored[$metadata->getFileId()] = $metadata->asArray();
		});
		return $manager;
	}

	private function metadataOf(int $fileId): IFilesMetadata {
		$values = new \ArrayObject($this->stored[$fileId] ?? []);
		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('getFileId')->willReturn($fileId);
		$metadata->method('hasKey')->willReturnCallback(fn (string $key): bool => $values->offsetExists($key));
		$metadata->method('getString')->willReturnCallback(fn (string $key): string => (string)$values[$key]);
		$metadata->method('asArray')->willReturnCallback(fn (): array => $values->getArrayCopy());
		$metadata->method('setString')->willReturnCallback(function (string $key, string $value) use ($values, $metadata): IFilesMetadata {
			$values[$key] = $value;
			return $metadata;
		});
		$metadata->method('setEditPermission')->willReturnCallback(function (string $key, int $permission) use ($fileId): void {
			$this->editPermissions[$fileId][$key] = $permission;
		});
		return $metadata;
	}
}
