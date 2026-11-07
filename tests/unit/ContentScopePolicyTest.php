<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;

final class ContentScopePolicyTest extends TestCase {
	private function policy(bool $development, bool $reads, bool $configured): array {
		$config = $this->createMock(IConfig::class);
		$settings = ['revision' => 'content-test', 'sources' => [['kind' => 'user-files']], 'destinations' => [], 'tools' => [...ScopePolicy::FILE_TOOLS, ...ScopePolicy::CONTENT_TOOLS], 'sourceClasses' => ['standard'], 'downstreamPolicy' => 'unverified', 'destinationAudiences' => [], 'movements' => [], 'classificationDefault' => 'standard'];
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => ['development' => $development ? '1' : '0', 'provider_reads_enabled' => $reads ? '1' : '0', 'integration_enabled' => '1', 'server_url' => 'https://cloud.example.test', 'scope_policy' => $configured ? json_encode($settings, JSON_THROW_ON_ERROR) : ''][$key] ?? $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		return (new ScopePolicy($config, $this->createMock(IRootFolder::class), $users, $this->createMock(IManager::class)))->getPolicy('alice', 'https://cloud.example.test');
	}
	public function testNativeContentRequiresExplicitScopeAndBothOptIns(): void {
		self::assertSame(ScopePolicy::CONTENT_TOOLS, array_values(array_intersect($this->policy(true, true, true)['tools'], ScopePolicy::CONTENT_TOOLS)));
		foreach ([[false, true, true], [true, false, true], [false, false, true], [true, true, false]] as $args) {
			self::assertSame([], array_values(array_intersect($this->policy(...$args)['tools'], ScopePolicy::CONTENT_TOOLS)));
		}
	}
}
