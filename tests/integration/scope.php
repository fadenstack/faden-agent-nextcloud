<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'foundation-20261026') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager;

$config = Server::get(IConfig::class);
$session = Server::get(IUserSession::class);
$user = Server::get(IUserManager::class)->get('faden-workflow-lab');
$session->setUser($user);
$root = Server::get(IRootFolder::class);
$shares = Server::get(IManager::class);
$policy = new ScopePolicy($config, $root, $session, $shares);
$folder = $root->getUserFolder($user->getUID())->newFolder('Scope fixture ' . bin2hex(random_bytes(5)));
$file = $folder->newFile('allowed.md', 'synthetic scope source');
$other = $folder->newFile('other.md', 'unrelated synthetic source');
$ref = 'nextcloud:files:' . $file->getId();
$checks = [];
$assert = function (string $name, bool $condition) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $condition];
	if (!$condition) {
		throw new RuntimeException('Failed: ' . $name);
	}
};
$denied = function (string $name, callable $work, array $statuses = [403, 404]) use ($assert): void {
	try {
		$work();
	} catch (Problem $error) {
		$assert($name, in_array($error->status, $statuses, true));
		return;
	}
	$assert($name, false);
};
$set = fn (array $value) => $config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, json_encode($value, JSON_THROW_ON_ERROR));
$old = $config->getAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');
$origin = $config->getAppValue(Application::APP_ID, 'server_url', '');
try {
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');
	$default = $policy->getPolicy($user->getUID(), $origin);
	$assert('legacy integration stays native-user Files scoped', $default['sources'] === [['kind' => 'user-files']]);
	$assert('default is not downstream provider attestation', $default['downstreamPolicy'] === 'unverified');
	$assert('native owner file has authoritative private audience', $policy->resolve($ref, 'read')['audience'] === 'private');
	$restricted = ['revision' => 'fixture-1', 'sources' => [['kind' => 'file', 'resourceId' => $ref]],
		'destinations' => [['kind' => 'file', 'resourceId' => $ref]], 'tools' => ['nc_read_file', 'nc_update_text_file'],
		'sourceClasses' => ['standard'], 'classificationDefault' => 'standard', 'destinationAudiences' => ['private'],
		'downstreamPolicy' => 'unverified', 'movements' => [['destination' => 'private', 'source' => 'standard']]];
	$set($restricted);
	$assert('native policy changes invalidate the config stamp', $policy->getPolicy($user->getUID(), $origin)['version'] !== $default['version']);
	$assert('exact configured source resolves', $policy->resolve($ref, 'read')['resourceId'] === $ref);
	$policy->assertMovement($file, $file);
	$assert('movement rule key order does not change meaning', true);
	$denied('foreign native account cannot request policy', fn () => $policy->getPolicy('pilot-bob', $origin), [401]);
	$denied('unconfigured file is unavailable even to its native owner', fn () => $policy->resolve('nextcloud:files:' . $other->getId()));
	$denied('source file never grants creation into its parent', fn () => $policy->assertMovement($file, $folder));
	$denied('forged URL is not a native reference', fn () => $policy->resolve('https://foreign.invalid/file'), [400]);
	$denied('file-only scope cannot query broad filename counts', fn () => $policy->searchScope());
	$assert('file-only scope cannot use a broad content index', !$policy->indexedContentAllowed());
	$restricted['sources'] = [['kind' => 'folder', 'resourceId' => 'nextcloud:files:' . $folder->getId()]];
	$set($restricted);
	$assert('folder scope narrows discovery before the query', $policy->searchScope() === 'nextcloud:files:' . $folder->getId());
	$restricted['classificationDefault'] = 'unknown';
	$set($restricted);
	$denied('unknown data classification blocks content processing', fn () => $policy->resolve($ref, 'read'));
	$restricted['classificationDefault'] = 'protected';
	$restricted['sourceClasses'] = ['protected'];
	$set($restricted);
	$denied('protected source needs an explicit downstream policy', fn () => $policy->resolve($ref, 'read'));
	$restricted['downstreamPolicy'] = 'configured';
	$set($restricted);
	$assert('explicit configured policy allows a permitted protected source', $policy->resolve($ref, 'read')['sourceClass'] === 'protected');
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '{malformed');
	$denied('malformed policy has no permissive fallback', fn () => $policy->getPolicy($user->getUID(), $origin), [503]);
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');
	$share = $shares->newShare();
	$share->setNode($file)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith('pilot-bob')->setSharedBy($user->getUID())->setShareOwner($user->getUID())->setPermissions(\OCP\Constants::PERMISSION_READ);
	$share = $shares->createShare($share);
	try {
		$assert('native user share changes destination audience', $policy->audience($file) === 'shared');
		$denied('native write ACL alone does not authorize broader audience movement', fn () => $policy->assertMovement($other, $file));
	} finally {
		$shares->deleteShare($share);
	}
} finally {
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, $old);
}
echo json_encode(['layer' => 'real Nextcloud configuration, Files and Share manager', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
