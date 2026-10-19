<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

require_once __DIR__ . '/MetadataStore.php';

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\FrontMatter;
use OCA\FadenstackAgent\Service\ProvenanceService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The file service's writes with an AI mark: an update or a finished creation keeps the signed record as the
 * file's metadata, a Markdown file gets the front matter mark, and the read-back says what the file holds. Files are
 * temporary files behind mocked nodes.
 */
class FileServiceProvenanceTest extends TestCase {
	use MetadataStore;

	private const ID = '5a0e2b5c-7f3e-4a51-9a40-6a1c55e1d3f1';
	/** @var array<string, mixed> */
	private array $session = [];
	/** @var array<int, File|Folder> */
	private array $nodes = [];
	/** @var list<string> */
	private array $temporary = [];
	private Folder $folder;
	private ?File $created = null;

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			@unlink($path);
		}
	}

	private function service(): FileService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(fn (string $key): mixed => $this->session[$key] ?? null);
		$session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
			$this->session[$key] = $value;
		});
		$home = $this->createMock(Folder::class);
		$home->method('getById')->willReturnCallback(fn (int $id): array => isset($this->nodes[$id]) ? [$this->nodes[$id]] : []);
		$home->method('getRelativePath')->willReturnCallback(fn (string $path): string => substr($path, strlen('/alice/files')) ?: '/');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.test/f/1');
		$provenance = new ProvenanceService($this->metadataManager(), $this->createMock(LoggerInterface::class));
		return new FileService($root, $users, $session, $urls, $this->createMock(Configuration::class), $this->createMock(IMountManager::class), $provenance);
	}

	/** A file in the user's root folder, kept in a temporary file. */
	private function file(int $id, string $name, string $content): File {
		$path = tempnam(sys_get_temp_dir(), 'fdn');
		$this->temporary[] = $path;
		file_put_contents($path, $content);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/alice/files/' . $name);
		$file->method('getMimetype')->willReturn(ProvenanceService::mediaType($name));
		$file->method('getSize')->willReturnCallback(function () use ($path): int {
			clearstatcache(true, $path);
			return (int)filesize($path);
		});
		$file->method('getEtag')->willReturn('etag-' . $id);
		$file->method('isReadable')->willReturn(true);
		$file->method('isUpdateable')->willReturn(true);
		$file->method('getParent')->willReturnCallback(fn (): Folder => $this->folder);
		$file->method('fopen')->willReturnCallback(fn (string $mode) => fopen($path, $mode === 'r+' ? 'r+' : 'rb'));
		$storage = $this->createMock(IStorage::class);
		$storage->method('getETag')->willReturn('etag-' . $id);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		$this->nodes[$id] = $file;
		return $file;
	}

	private function contents(File $file): string {
		$stream = $file->fopen('rb');
		$text = stream_get_contents($stream);
		fclose($stream);
		return $text;
	}

	protected function setUp(): void {
		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('getId')->willReturn(3);
		$this->folder->method('getPath')->willReturn('/alice/files');
		$this->folder->method('isReadable')->willReturn(true);
		$this->folder->method('isCreatable')->willReturn(true);
		$this->folder->method('nodeExists')->willReturnCallback(fn (): bool => $this->created !== null);
		$this->folder->method('get')->willReturnCallback(fn (string $name): File => $this->created ?? throw new NotFoundException($name));
		$this->nodes[3] = $this->folder;
	}

	private function approvedUpdate(FileService $files, File $file, string $content): string {
		$context = $files->select($file->getId());
		$files->read($context['id']);
		$proposal = $files->propose($context['id'], 'update', '', $content, $file->getEtag());
		$files->approve($proposal['id']);
		return $proposal['id'];
	}

	public function testAnUpdateKeepsTheRecordAndTheFrontMatterMark(): void {
		$files = $this->service();
		$file = $this->file(5, 'Notes.md', "---\ntitle: Notes\n---\nOld text.\n");
		$approved = "---\ntitle: Notes\n---\n# Notes\n\nThe new text.\n";
		$marked = FrontMatter::withMark($approved, self::ID);
		$document = ProvenanceServiceTest::document();
		$result = $files->commit($this->approvedUpdate($files, $file, $approved), 0, $marked, $document);
		self::assertSame($marked, $this->contents($file));
		self::assertSame($document, $this->stored[5][ProvenanceService::KEY]);
		self::assertSame(['key' => ProvenanceService::KEY, 'metadata' => $document,
			'frontMatter' => "---\ntitle: Notes\nai-generated: true\nai-record-id: \"" . self::ID . "\"\n---\n"], $result['provenance']);
	}

	public function testAnUpdateWritesTheApprovedTextWhenTheMarkedTextIsAnyOther(): void {
		$files = $this->service();
		$file = $this->file(5, 'Notes.md', "Old text.\n");
		$approved = "# Notes\n";
		$result = $files->commit($this->approvedUpdate($files, $file, $approved), 0, FrontMatter::withMark($approved, self::ID) . "More.\n", ProvenanceServiceTest::document());
		self::assertSame($approved, $this->contents($file));
		// The record is still kept: the file is the agent's text, the front matter mark is what is missing.
		self::assertSame(ProvenanceServiceTest::document(), $result['provenance']['metadata']);
		self::assertNull($result['provenance']['frontMatter']);
	}

	public function testAnUpdateWithoutARecordIsWrittenUnmarked(): void {
		$files = $this->service();
		$file = $this->file(5, 'Reply.txt', "Old text.\n");
		$result = $files->commit($this->approvedUpdate($files, $file, "New reply.\n"), 0, "New reply.\n", '');
		self::assertSame("New reply.\n", $this->contents($file));
		self::assertSame([], $this->stored);
		self::assertSame(['key' => ProvenanceService::KEY, 'metadata' => null, 'frontMatter' => null], $result['provenance']);
	}

	public function testACreationKeepsTheRecordOnceTheNewFileIsThere(): void {
		$files = $this->service();
		$source = $this->file(5, 'Agreement.txt', "Supply agreement.\n");
		$context = $files->select($source->getId());
		$approved = "# Summary\n\nThree agreements, three months' notice.\n";
		$proposal = $files->propose($context['id'], 'create', 'Summary.md', $approved, '');
		$files->approve($proposal['id']);
		$document = ProvenanceServiceTest::document();
		$plan = $files->commit($proposal['id'], 1, FrontMatter::withMark($approved, self::ID), $document);
		self::assertSame('dav-create', $plan['kind']);
		self::assertSame(FrontMatter::withMark($approved, self::ID), $plan['content']);
		self::assertSame([], $this->stored); // nothing before the file exists
		$this->created = $this->file(6, 'Summary.md', $plan['content']); // the client's WebDAV PUT
		$result = $files->finishCreate($plan['receiptId']);
		self::assertSame($document, $this->stored[6][ProvenanceService::KEY]);
		self::assertSame($document, $result['provenance']['metadata']);
		self::assertSame(self::ID, $this->recordIdIn((string)$result['provenance']['frontMatter']));
	}

	public function testACreationOfATextFileCarriesTheRecordInItsMetadataOnly(): void {
		$files = $this->service();
		$context = $files->select($this->file(5, 'Agreement.txt', "Supply agreement.\n")->getId());
		$proposal = $files->propose($context['id'], 'create', 'Reply.txt', "Dear Ms Weber,\n", '');
		$files->approve($proposal['id']);
		$document = ProvenanceServiceTest::document([], 'text/plain');
		$plan = $files->commit($proposal['id'], 1, "Dear Ms Weber,\n", $document);
		self::assertSame("Dear Ms Weber,\n", $plan['content']);
		$this->created = $this->file(6, 'Reply.txt', $plan['content']);
		$result = $files->finishCreate($plan['receiptId']);
		self::assertSame(['key' => ProvenanceService::KEY, 'metadata' => $document, 'frontMatter' => null], $result['provenance']);
	}

	private function recordIdIn(string $frontMatter): ?string {
		return preg_match('/^ai-record-id: "([^"]+)"$/m', $frontMatter, $match) === 1 ? $match[1] : null;
	}
}
