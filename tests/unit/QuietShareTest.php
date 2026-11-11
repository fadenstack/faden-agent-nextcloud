<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Files\QuietShare;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

final class QuietShareTest extends TestCase {
	public function testConcurrentExistingShareCannotRestoreItsOldMailFlag(): void {
		$requested = $this->share('bob');
		$existing = $this->share('bob');
		$unrelated = $this->share('charlie');
		$requested->expects(self::atLeastOnce())->method('setMailSend')->with(false);
		$existing->expects(self::once())->method('setMailSend')->with(false);
		$existing->expects(self::never())->method('setPermissions');
		$unrelated->expects(self::never())->method('setMailSend');
		$events = $this->createMock(IEventDispatcher::class);
		$listener = null;
		$events->expects(self::once())->method('addListener')->with(ShareCreatedEvent::class, self::callback(static function ($callback) use (&$listener): bool {
			$listener = $callback;
			return true;
		}), PHP_INT_MIN);
		$events->expects(self::once())->method('removeListener')->with(ShareCreatedEvent::class, self::callback(static fn ($callback): bool => is_callable($callback)));
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::once())->method('createShare')->with($requested)->willReturnCallback(static function () use (&$listener, $existing, $unrelated): IShare {
			$listener(new ShareCreatedEvent($unrelated));
			$listener(new ShareCreatedEvent($existing));
			return $existing;
		});
		self::assertSame($existing, (new QuietShare($manager, $events))->create($requested));
	}
	public function testNativeFailureStillRemovesTheRequestLocalListener(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects(self::once())->method('removeListener');
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::once())->method('createShare')->willThrowException(new \RuntimeException('native refusal'));
		$this->expectException(\RuntimeException::class);
		(new QuietShare($manager, $events))->create($this->share('bob'));
	}
	private function share(string $recipient): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(51);
		$share->method('getShareType')->willReturn(IShare::TYPE_USER);
		$share->method('getSharedBy')->willReturn('alice');
		$share->method('getSharedWith')->willReturn($recipient);
		return $share;
	}
}
