<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

require_once __DIR__ . '/MetadataStore.php';

use OCA\FadenstackAgent\Listener\CopyListener;
use OCA\FadenstackAgent\Service\FrontMatter;
use OCA\FadenstackAgent\Service\ProvenanceService;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\Exceptions\FilesMetadataException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProvenanceServiceTest extends TestCase {
	use MetadataStore;

	private const ID = '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1';

	/** The SDK's sidecar document (`JSON.stringify(sidecarDocument(record))`), with overrides. */
	public static function document(array $changes = [], string $mediaType = 'text/markdown'): string {
		$document = array_merge([
			'contract' => 'provenance/v1',
			'record' => ['contract' => 'provenance/v1', 'id' => self::ID, 'digital_source_type' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
				'edit_kind' => 'summarised', 'system' => ['name' => 'Fadenstack', 'version' => '0.4.5'], 'provider' => 'Example organisation',
				'model' => ['id' => 'qwen3.8-27b-fp8', 'version' => null], 'created_at' => '2026-10-19T08:00:00Z', 'receipt_id' => null,
				'media_type' => $mediaType, 'content_hash' => str_repeat('ab', 32), 'hash_key_version' => 1],
			'signature' => 'eyJhbGciOiJFUzI1NiJ9.eyJpZCI6IjVhMGUifQ.c2lnbmF0dXJl',
		], $changes);
		return json_encode(array_filter($document, fn ($value) => $value !== null), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	private function service(?LoggerInterface $logger = null): ProvenanceService {
		return new ProvenanceService($this->metadataManager(), $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testReadsTheSdkSidecarDocument(): void {
		$json = self::document(['timestamp' => 'MIIBAQ==']);
		$record = ProvenanceService::parse($json, 'text/markdown');
		self::assertSame([self::ID, $json], [$record->id, $record->json]);
		self::assertSame('text/plain', ProvenanceService::mediaType('Reply.txt'));
		self::assertSame('text/markdown', ProvenanceService::mediaType('Summary.MD'));
	}

	/** @return array<string, array{string, string}> */
	public static function notRecords(): array {
		$noId = json_decode(self::document(), true);
		unset($noId['record']['id']);
		$badId = json_decode(self::document(), true);
		$badId['record']['id'] = 'rec-1';
		return [
			'not JSON' => ['{"contract":', 'not JSON'],
			'a list' => ['[1,2]', 'not a JSON object'],
			'another contract' => [self::document(['contract' => 'provenance/v2']), 'not provenance/v1'],
			'no record id' => [json_encode($noId), 'no record with an id'],
			'not the server\'s kind of id' => [json_encode($badId), 'no record with an id'],
			'another media type' => [self::document([], 'text/plain'), 'a record of another media type'],
			'no signature' => [self::document(['signature' => '']), 'no signature'],
			'a signature that is no JWS' => [self::document(['signature' => '<script>']), 'no signature'],
			'another field' => [self::document(['content' => 'the text']), 'fields besides'],
			'a time stamp that is no token' => [self::document(['timestamp' => 5]), 'a time stamp'],
			'too large' => [self::document(['facts' => ['com.example.note' => str_repeat('x', 40000)]]), 'longer than'],
		];
	}

	#[DataProvider('notRecords')]
	public function testRefusesWhatIsNoSignedRecord(string $json, string $why): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($why);
		ProvenanceService::parse($json, 'text/markdown');
	}

	public function testAWriteWithoutAUsableRecordGoesOnUnmarked(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('was not kept: not provenance/v1'));
		$service = $this->service($logger);
		self::assertNull($service->record('', 'Notes.md'));
		self::assertNull($service->record(self::document(['contract' => 'other']), 'Notes.md'));
		self::assertSame(self::ID, $service->record(self::document([], 'text/plain'), 'Reply.txt')?->id);
	}

	public function testWritesOnlyTheApprovedTextAndItsMark(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$service = $this->service($logger);
		$record = ProvenanceService::parse(self::document(), 'text/markdown');
		$approved = "# Summary\n\nThree agreements.\n";
		$marked = FrontMatter::withMark($approved, self::ID);
		self::assertSame($marked, $service->content($approved, $marked, $record, 'Summary.md'));
		// Anything else than the mark falls back to the approved text: the user approved that.
		self::assertSame($approved, $service->content($approved, $marked . "Added after the approval.\n", $record, 'Summary.md'));
		// A .txt carries nothing in its bytes; without a record nothing is marked.
		self::assertSame($approved, $service->content($approved, $marked, $record, 'Summary.txt'));
		self::assertSame($approved, $service->content($approved, $marked, null, 'Summary.md'));
	}

	public function testKeepsTheRecordAsFileMetadataThatOnlyTheServerMayChange(): void {
		$service = $this->service();
		$json = self::document();
		self::assertTrue($service->store(42, $json));
		self::assertSame([ProvenanceService::KEY => $json], $this->stored[42]);
		self::assertSame(IMetadataValueWrapper::EDIT_FORBIDDEN, $this->editPermissions[42][ProvenanceService::KEY]);
		self::assertSame($json, $service->stored(42));
		self::assertNull($service->stored(43));
	}

	public function testTriesOnceMoreWhenARefreshWonTheWrite(): void {
		$service = $this->service();
		$this->lostSaves = 1;
		self::assertTrue($service->store(42, self::document()));
		self::assertSame(2, $this->saves);
		$this->lostSaves = 2;
		self::assertFalse($service->store(43, self::document()));
	}

	public function testAMetadataFailureNeverStopsTheWrite(): void {
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->willThrowException(new FilesMetadataException('database gone'));
		$service = new ProvenanceService($manager, $this->createMock(LoggerInterface::class));
		self::assertFalse($service->store(42, self::document()));
		self::assertNull($service->stored(42));
	}

	private function file(int $id, string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		return $file;
	}

	public function testTheCopyListenerCopiesTheRecordToACopiedFile(): void {
		$service = $this->service();
		$this->stored[7] = [ProvenanceService::KEY => self::document(), 'photos-size' => '{}'];
		$listener = new CopyListener($service);
		$listener->handle(new NodeCopiedEvent($this->file(7, '/alice/files/Summary.md'), $this->file(8, '/alice/files/Summary (copy).md')));
		self::assertSame(self::document(), $this->stored[8][ProvenanceService::KEY]);
		// Only copies: a rename keeps the file and its metadata, and a file without a record gives its copy none.
		$listener->handle(new NodeRenamedEvent($this->file(7, '/alice/files/A.md'), $this->file(9, '/alice/files/B.md')));
		$listener->handle(new NodeCopiedEvent($this->file(10, '/alice/files/Mine.md'), $this->file(11, '/alice/files/Mine (copy).md')));
		self::assertArrayNotHasKey(9, $this->stored);
		self::assertArrayNotHasKey(11, $this->stored);
	}

	public function testACopiedFolderGivesEachCopyItsRecord(): void {
		$service = $this->service();
		$this->stored[21] = [ProvenanceService::KEY => self::document()];
		$this->stored[22] = ['photos-size' => '{}'];
		$sub = $this->createMock(Folder::class);
		$sub->method('getPath')->willReturn('/alice/files/Notes/2026');
		$sub->method('getDirectoryListing')->willReturn([$this->file(21, '/alice/files/Notes/2026/Summary.md'), $this->file(22, '/alice/files/Notes/2026/Photo.md')]);
		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/alice/files/Notes');
		$source->method('getDirectoryListing')->willReturn([$sub, $this->file(23, '/alice/files/Notes/Own.md')]);
		$target = $this->createMock(Folder::class);
		$target->method('get')->willReturnCallback(fn (string $path) => match ($path) {
			'2026/Summary.md' => $this->file(31, '/alice/files/Notes (copy)/2026/Summary.md'),
			default => throw new NotFoundException($path),
		});
		$service->copy($source, $target);
		self::assertSame(self::document(), $this->stored[31][ProvenanceService::KEY]);
		self::assertSame([21, 22, 31], array_keys($this->stored));
	}
}
