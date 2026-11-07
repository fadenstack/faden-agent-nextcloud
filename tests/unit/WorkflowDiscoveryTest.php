<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCA\FadenstackAgent\Providers\WorkflowAuthority;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

final class WorkflowDiscoveryTest extends TestCase {
	private function flags(?Node $node, ?callable $grant = null): array {
		$home = $this->createMock(Folder::class);
		$home->method('getById')->with(17)->willReturn($node === null ? [] : [$node]);
		$home->expects(self::never())->method('getDirectoryListing');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($home);
		$scope = $this->createMock(ScopePolicy::class);
		$scope->method('describeNode')->willReturnCallback($grant ?? static fn () => ['sourceClass' => 'standard', 'audience' => 'private']);
		$reflection = new \ReflectionClass(WorkflowAuthority::class);
		$authority = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('root')->setValue($authority, $root);
		$reflection->getProperty('files')->setValue($authority, $scope);
		$reflection->getProperty('policies')->setValue($authority, (new \ReflectionClass(ProviderPolicy::class))->newInstanceWithoutConstructor());
		$reference = [['kind' => 'resource', 'resourceId' => 'nextcloud:files:17']];
		return $authority->boardScopeFlags(['actor' => 'alice', 'processing' => ['sources' => $reference, 'destinations' => $reference,
			'sourceClasses' => ['standard'], 'downstreamPolicy' => 'unverified', 'destinationAudiences' => ['private']]]);
	}
	private function folder(bool $creatable = true, bool $local = true): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('isReadable')->willReturn(true);
		$folder->method('isCreatable')->willReturn($creatable);
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn($local);
		$folder->method('getStorage')->willReturn($storage);
		return $folder;
	}
	public function testStaleAndRevokedReferencesAdvertiseNothing(): void {
		self::assertSame(['read' => false, 'create' => false], $this->flags(null));
		$unreadable = $this->createMock(Folder::class);
		$unreadable->method('isReadable')->willReturn(false);
		self::assertSame(['read' => false, 'create' => false], $this->flags($unreadable));
		self::assertSame(['read' => false, 'create' => false], $this->flags($this->folder(), static fn () => throw new Problem('Revoked.', 403)));
	}
	public function testOnlyWritableLocalFoldersAdvertiseCreation(): void {
		self::assertSame(['read' => true, 'create' => true], $this->flags($this->folder()));
		self::assertSame(['read' => true, 'create' => false], $this->flags($this->folder(false)));
		self::assertSame(['read' => true, 'create' => false], $this->flags($this->folder(true, false)));
	}
	public function testFileScopeNeverGrantsFolderCreationOrReadsBytesDuringDiscovery(): void {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getName')->willReturn('Saved.whiteboard');
		$file->expects(self::never())->method('fopen');
		$file->expects(self::never())->method('getContent');
		self::assertSame(['read' => true, 'create' => false], $this->flags($file));
		self::assertSame(['read' => false, 'create' => false], $this->flags($file, static fn () => ['sourceClass' => 'protected', 'audience' => 'private']));
	}
	public function testDestinationAudienceAndNativeScopeFailuresAreRespected(): void {
		self::assertSame(['read' => true, 'create' => false], $this->flags($this->folder(), static fn () => ['sourceClass' => 'standard', 'audience' => 'shared']));
		self::assertSame(['read' => false, 'create' => false], $this->flags($this->folder(), static fn () => throw new Problem('Outside current scope.', 404)));
	}
	public function testNativeFailureIsNotReportedAsAnAuthorizedCapability(): void {
		$this->expectException(Problem::class);
		$this->flags($this->folder(), static fn () => throw new Problem('Native failure.', 503));
	}
}
