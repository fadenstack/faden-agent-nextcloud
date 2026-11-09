<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\AgentFiles\AgentfileProvider;
use OCA\FadenstackAgent\AgentFiles\ManifestBindings;
use OCA\FadenstackAgent\AgentFiles\ManifestParser;
use OCA\FadenstackAgent\Providers\ProviderPolicy;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

final class AgentfileProviderTest extends TestCase {
	private const URL = 'https://example.sharepoint.com/sites/Test/Handbook.md';
	private function fixture(): array {
		$manifest = ['$schema' => ManifestParser::SCHEMA, 'version' => 'v1.9', 'name' => 'Synthetic handbook', 'description' => 'Definition is data.', 'instructions' => 'No authority.',
			'capabilities' => [['name' => 'OneDriveAndSharePoint', 'items_by_url' => [['url' => self::URL]]]]];
		$state = (object)['actor' => 'alice', 'bodies' => [17 => json_encode($manifest), 18 => 'Synthetic secret'], 'allowed' => [17, 18], 'classes' => [], 'etag' => 'unchanged-native-etag', 'reads' => [], 'onRead' => null];
		$binding = ['resourceId' => 'nextcloud:files:17', 'manifestSha256' => hash('sha256', $state->bodies[17]), 'agent' => 'test', 'sources' => [['reference' => self::URL, 'resourceId' => 'nextcloud:files:18']]];
		$state->settings = ['integration_enabled' => '1', 'development' => '1', 'provider_reads_enabled' => '1', 'server_url' => 'https://faden.test', 'agent' => 'test',
			'agentfile_bindings' => json_encode(['version' => 1, 'bindings' => [$binding]]),
			'provider_policy' => json_encode(['version' => 1, 'agentfile' => ['enabled' => true, 'collections' => ['17'], 'sourceClass' => 'standard']])];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $state->settings[$key] ?? $default);
		$users = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(static fn () => $state->actor);
		$users->method('getUser')->willReturn($user);
		$nodes = [];
		foreach ([17 => 'Handbook.agent', 18 => 'Handbook.md'] as $id => $name) {
			$node = $this->createMock(File::class);
			$node->method('getId')->willReturn($id);
			$node->method('getName')->willReturn($name);
			$node->method('getSize')->willReturnCallback(static fn () => strlen($state->bodies[$id]));
			$node->method('fopen')->willReturnCallback(static function () use ($id, $state) {
				$stream = fopen('php://temp', 'w+');
				fwrite($stream, $state->bodies[$id]);
				rewind($stream);
				$state->reads[] = $id;
				if ($state->onRead) {
					($state->onRead)($id);
				}
				return $stream;
			});
			$nodes[$id] = $node;
		}
		$home = $this->createMock(Folder::class);
		$home->method('getById')->willReturnCallback(static fn ($id) => isset($nodes[$id]) ? [$nodes[$id]] : []);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);
		$scope = $this->createMock(ScopePolicy::class);
		$scope->method('getPolicy')->willReturn(['version' => 'policy', 'tools' => [AgentfileProvider::TOOL], 'sourceClasses' => ['standard', 'protected'], 'downstreamPolicy' => 'configured']);
		$scope->method('describeNode')->willReturnCallback(static function ($node) use ($state) {
			if (!in_array($node->getId(), $state->allowed, true)) {
				throw new Problem('Unavailable.', 403);
			}
			return ['resourceId' => 'nextcloud:files:' . $node->getId(), 'revision' => $state->etag, 'sourceClass' => $state->classes[$node->getId()] ?? 'standard', 'audience' => 'private'];
		});
		$configuration = new Configuration($config);
		$policy = new ProviderPolicy($config, $configuration, $scope, $users);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://nextcloud.test/f/18');
		$reflection = new \ReflectionClass(ServerVersion::class);
		$server = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('version')->setValue($server, [35, 0, 2, 0]);
		return [new AgentfileProvider(new ManifestParser(), new ManifestBindings($config), $policy, $scope, $configuration, $root, $urls, $server), $state, $binding];
	}
	private function refused(callable $call, int $status): void {
		try {
			$call();
			self::fail('Expected native refusal.');
		} catch (Problem $error) {
			self::assertSame($status, $error->status);
		}
	}
	public function testReadOnlyDiscoveryAndExplicitSourceMappingUseIndependentNativeAuthorization(): void {
		[$p, $s] = $this->fixture();
		self::assertSame(['nc_agentfile_read'], $p->capabilities()[0]['tools']);
		$r = $p->read(['resourceId' => 'nextcloud:files:17']);
		self::assertTrue($r['untrustedConfigurationData']);
		self::assertSame('nextcloud:files:18', $r['sources'][0]['resourceId']);
		$source = $p->read(['resourceId' => 'nextcloud:files:17', 'sourceReference' => self::URL, 'expectedDigest' => $r['digest']]);
		self::assertSame('Synthetic secret', $source['source']['content']);
		self::assertSame('agentfile', $source['resource']['provider']);
		$s->allowed = [17];
		$definition = $p->read(['resourceId' => 'nextcloud:files:17']);
		self::assertSame(['reference' => self::URL, 'available' => false], $definition['sources'][0]);
		$this->refused(fn () => $p->read(['resourceId' => 'nextcloud:files:17', 'sourceReference' => self::URL, 'expectedDigest' => $r['digest']]), 403);
	}
	public function testUnapprovedBytesProfileMismatchAndMappingChangesNeverReadKnowledge(): void {
		[$p, $s, $binding] = $this->fixture();
		$s->bodies[17] .= ' ';
		$this->refused(fn () => $p->read(['resourceId' => 'nextcloud:files:17']), 403);
		self::assertNotContains(18, $s->reads);
		$s->bodies[17] = rtrim($s->bodies[17]);
		$s->settings['agent'] = 'other';
		$this->refused(fn () => $p->read(['resourceId' => 'nextcloud:files:17']), 403);
		$s->settings['agent'] = 'test';
		$binding['sources'][] = ['reference' => 'https://example.sharepoint.com/Other.md', 'resourceId' => 'nextcloud:files:19'];
		$s->settings['agentfile_bindings'] = json_encode(['version' => 1, 'bindings' => [$binding]]);
		$this->refused(fn () => $p->read(['resourceId' => 'nextcloud:files:17']), 403);
	}
	public function testWeakNativeEtagsDoNotHideChangedManifestBytesDuringSourceRead(): void {
		[$p, $s] = $this->fixture();
		$digest = hash('sha256', $s->bodies[17]);
		$s->onRead = static function ($id) use ($s): void {
			if ($id === 18) {
				$s->bodies[17] .= ' ';
			}
		};
		$this->refused(fn () => $p->read(['resourceId' => 'nextcloud:files:17', 'sourceReference' => self::URL, 'expectedDigest' => $digest]), 409);
	}
	public function testProtectedSourceClassificationCannotBeDowngradedByStandardManifest(): void {
		[$p, $s] = $this->fixture();
		$s->classes[18] = 'protected';
		self::assertSame('protected', $p->read(['resourceId' => 'nextcloud:files:17'])['resource']['sourceClass']);
		$s->classes[18] = 'unknown';
		$this->refused(fn () => $p->read(['resourceId' => 'nextcloud:files:17']), 403);
	}
	public function testSelectorsCannotIntroduceInlineJsonUrlsOrUnboundNativeSources(): void {
		[$p, $s] = $this->fixture();
		foreach ([['pathOrContent' => '{}'], ['resourceId' => 'https://other.test/x'], ['resourceId' => null], ['sourceReference' => self::URL]] as $args) {
			$this->refused(fn () => $p->read($args), 400);
		}
		$this->refused(fn () => $p->read(['resourceId' => 'nextcloud:files:17', 'sourceReference' => 'https://example.sharepoint.com/Other.md', 'expectedDigest' => hash('sha256', $s->bodies[17])]), 403);
		self::assertNotContains(18, $s->reads);
	}
}
