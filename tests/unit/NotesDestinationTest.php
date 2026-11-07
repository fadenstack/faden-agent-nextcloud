<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\ContentAuthority;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

final class NotesDestinationTest extends TestCase {
	private function authority(bool $writable): ContentAuthority {
		$notes = $this->createMock(Folder::class);
		$notes->method('getId')->willReturn(17);
		$notes->method('isReadable')->willReturn(true);
		$notes->method('isCreatable')->willReturn($writable);
		$notes->method('getPermissions')->willReturn($writable ? 31 : 1);
		$notes->method('nodeExists')->willReturn(false);
		$notes->expects(self::never())->method('newFolder');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('isReadable')->willReturn(true);
		$userFolder->method('nodeExists')->willReturnCallback(static fn ($name) => $name === 'Notes');
		$userFolder->method('get')->willReturn($notes);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$reflection = new \ReflectionClass(ContentAuthority::class);
		$authority = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('root')->setValue($authority, $root);
		return $authority;
	}
	public function testReadonlyNativeNotesFolderDoesNotAdvertiseCreation(): void {
		self::assertFalse($this->authority(false)->notesDestination(['actor' => 'alice'], ['notesPath' => 'Notes'])['canWrite']);
		self::assertFalse($this->authority(false)->notesDestination(['actor' => 'alice'], ['notesPath' => 'Notes'], 'New category')['canWrite']);
	}
	public function testCategoryResolutionCreatesNothingAndBindsNativePermissionChanges(): void {
		$writable = $this->authority(true)->notesDestination(['actor' => 'alice'], ['notesPath' => 'Notes'], 'Research');
		$readonly = $this->authority(false)->notesDestination(['actor' => 'alice'], ['notesPath' => 'Notes'], 'Research');
		self::assertTrue($writable['canWrite']);
		self::assertNotSame($writable['binding'], $readonly['binding']);
	}
	public function testTraversalCannotEscapeTheNativeNotesDestination(): void {
		$this->expectException(Problem::class);
		$this->authority(true)->notesDestination(['actor' => 'alice'], ['notesPath' => 'Notes'], '../Shared');
	}
}
