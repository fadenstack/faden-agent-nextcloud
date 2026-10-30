<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DavScopePolicyTest extends TestCase {
	private function policy(bool $development, bool $reads, bool $configured = true): array {
		$config = $this->createMock(IConfig::class);
		$settings = ['revision' => 'dav-test-1', 'sources' => [['kind' => 'user-files']], 'destinations' => [],
			'tools' => [...ScopePolicy::FILE_TOOLS, ...ScopePolicy::DAV_TOOLS], 'sourceClasses' => ['standard'],
			'downstreamPolicy' => 'unverified', 'destinationAudiences' => [], 'movements' => [], 'classificationDefault' => 'standard'];
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => [
			'development' => $development ? '1' : '0', 'provider_reads_enabled' => $reads ? '1' : '0',
			'integration_enabled' => '1', 'server_url' => 'https://cloud.example.test',
			'scope_policy' => $configured ? json_encode($settings, JSON_THROW_ON_ERROR) : '',
		][$key] ?? $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('synthetic-owner');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);
		return (new ScopePolicy($config, $this->createMock(IRootFolder::class), $users, $this->createMock(IManager::class)))
			->getPolicy('synthetic-owner', 'https://cloud.example.test');
	}

	public function testExplicitNativePolicyCanServeAllFifteenDavToolGrants(): void {
		$policy = $this->policy(true, true);
		self::assertSame(ScopePolicy::DAV_TOOLS, array_values(array_intersect($policy['tools'], ScopePolicy::DAV_TOOLS)));
	}

	public static function disabledGates(): array {
		return [[false, true], [true, false], [false, false]];
	}

	#[DataProvider('disabledGates')]
	public function testNativeDavToolsStayAbsentUnlessBothGatesAreEnabled(bool $development, bool $reads): void {
		self::assertSame([], array_values(array_intersect($this->policy($development, $reads)['tools'], ScopePolicy::DAV_TOOLS)));
	}

	public function testTurningOnTheGateNeverAddsToolsToTheLegacyFilesDefaults(): void {
		self::assertSame(ScopePolicy::FILE_TOOLS, $this->policy(true, true, false)['tools']);
	}
}
