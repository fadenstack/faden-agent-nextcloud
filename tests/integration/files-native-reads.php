<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'native-reads-20261029') {
	exit(2);
}
define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (\Throwable $error): never {
	fwrite(STDERR, $error::class . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
	fwrite(STDERR, $error->getMessage() . "\n");
	fwrite(STDERR, json_encode($GLOBALS['checks'] ?? [], JSON_THROW_ON_ERROR) . "\n");
	exit(1);
});

use OCA\FadenstackAgent\AppInfo\Application;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ScopePolicy;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

$checks = [];
$assert = static function (string $name, bool $condition) use (&$checks): void {
	$checks[] = ['case' => $name, 'pass' => $condition];
	if (!$condition) {
		throw new RuntimeException('Failed native check: ' . $name);
	}
};
$denied = static function (string $name, callable $work, int $status) use ($assert): void {
	try {
		$work();
	} catch (Problem $error) {
		$assert($name, $error->status === $status);
		return;
	}
	$assert($name, false);
};

$config = Server::get(IConfig::class);
$session = Server::get(IUserSession::class);
$userMgr = Server::get(IUserManager::class);
$root = Server::get(IRootFolder::class);
$shares = Server::get(IManager::class);

$userA = $userMgr->get('faden-workflow-lab') ?? $userMgr->get('pilot-alice');
$userB = $userMgr->get('pilot-bob');
if (!$userA || !$userB) {
	throw new RuntimeException('Synthetic test users A and B must exist.');
}

$session->setUser($userA);
$homeA = $root->getUserFolder($userA->getUID());
$fixtureFolder = $homeA->newFolder('NativeReadsFixture_' . bin2hex(random_bytes(4)));
$subFolder = $fixtureFolder->newFolder('Subproject-Überblick');
$fileA = $fixtureFolder->newFile('Contract-2026.md', '# Contract for native reads');
$fileB = $subFolder->newFile('Plan-Überblick.md', '# Detailed plan with Unicode');

$fileRef = 'nextcloud:files:' . $fileA->getId();
$folderRef = 'nextcloud:files:' . $fixtureFolder->getId();
$subFolderRef = 'nextcloud:files:' . $subFolder->getId();

$broker = Server::get(FileService::class);
$oldPolicy = $config->getAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');
$oldReadsEnabled = $config->getAppValue(Application::APP_ID, 'files_reads_enabled', '0');

$createdShares = [];

try {
	// Enable files reads opt-in
	$config->setAppValue(Application::APP_ID, 'files_reads_enabled', '1');
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');

	// 1. OrganizeRead on folder returns canonical references, entries and metadata
	$listing = $broker->organizeRead($folderRef);
	$assert('folder organizeRead succeeds and returns canonical resourceId', $listing['resourceId'] === $folderRef);
	$assert('folder organizeRead returns entries with canonical IDs', count($listing['entries']) >= 2);
	$entryNames = array_column($listing['entries'], 'filename');
	$assert('listing contains Contract-2026.md', in_array('Contract-2026.md', $entryNames, true));
	$assert('listing contains Subproject-Überblick with Unicode', in_array('Subproject-Überblick', $entryNames, true));

	// 2. Selected file versus parent: organizeRead on a file MUST fail with 415 (cannot silently grant parent/root access)
	$denied('selected file cannot be organized as folder (fails 415)', fn () => $broker->organizeRead($fileRef), 415);

	// 3. Invalid reference (path or URL) rejected
	$denied('arbitrary path rejected for organizeRead (fails 400)', fn () => $broker->organizeRead('/some/path'), 400);
	$denied('arbitrary URL rejected for organizeRead (fails 400)', fn () => $broker->organizeRead('https://cloud.test/f/1'), 400);

	// 4. Stale expectedRevision throws 409
	$denied('stale folder revision throws 409 on organizeRead', fn () => $broker->organizeRead($folderRef, 'stale-rev-999'), 409);

	// 5. Configured source roots / scope denial
	$restricted = [
		'revision' => 'restricted-subfolder-only',
		'sources' => [['kind' => 'folder', 'resourceId' => $subFolderRef]],
		'destinations' => [['kind' => 'folder', 'resourceId' => $subFolderRef]],
		'tools' => ScopePolicy::EXTENDED_READ_TOOLS,
		'sourceClasses' => ['standard'],
		'classificationDefault' => 'standard',
		'destinationAudiences' => ['private'],
		'downstreamPolicy' => 'unverified',
		'movements' => [['source' => 'standard', 'destination' => 'private']],
	];
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, json_encode($restricted, JSON_THROW_ON_ERROR));

	// Allowed root succeeds
	$subListing = $broker->organizeRead($subFolderRef);
	$assert('allowed scoped subfolder resolves', $subListing['resourceId'] === $subFolderRef);

	// Denied root outside configured sources fails 404
	$denied('organizeRead outside configured source roots is denied (404)', fn () => $broker->organizeRead($folderRef), 404);

	// Reset policy to legacy default
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');

	// 6. Share creation & inspection
	// Direct user share to User B
	$userShare = $shares->newShare();
	$userShare->setNode($fileA)
		->setShareType(IShare::TYPE_USER)
		->setSharedWith($userB->getUID())
		->setSharedBy($userA->getUID())
		->setShareOwner($userA->getUID())
		->setPermissions(Constants::PERMISSION_READ);
	$userShare = $shares->createShare($userShare);
	$createdShares[] = $userShare;

	// Inherited share on parent folder
	$parentShare = $shares->newShare();
	$parentShare->setNode($fixtureFolder)
		->setShareType(IShare::TYPE_USER)
		->setSharedWith($userB->getUID())
		->setSharedBy($userA->getUID())
		->setShareOwner($userA->getUID())
		->setPermissions(Constants::PERMISSION_READ);
	$parentShare = $shares->createShare($parentShare);
	$createdShares[] = $parentShare;

	// Public link share on file (to test secret stripping)
	$linkShare = $shares->newShare();
	$linkShare->setNode($fileA)
		->setShareType(IShare::TYPE_LINK)
		->setSharedBy($userA->getUID())
		->setShareOwner($userA->getUID())
		->setPassword('SecretSharePass123!')
		->setPermissions(Constants::PERMISSION_READ);
	$linkShare = $shares->createShare($linkShare);
	$createdShares[] = $linkShare;

	// Call sharesRead as User A
	$inspected = $broker->sharesRead($fileRef);
	$assert('sharesRead returns direct and inherited shares', count($inspected) >= 3);

	// Verify distinction between direct and inherited
	$directEntries = array_filter($inspected, fn ($s) => $s['inherited'] === false && $s['shareType'] === IShare::TYPE_USER);
	$inheritedEntries = array_filter($inspected, fn ($s) => $s['inherited'] === true);
	$linkEntries = array_filter($inspected, fn ($s) => $s['shareType'] === IShare::TYPE_LINK);

	$assert('distinguishes direct user share', count($directEntries) >= 1);
	$assert('distinguishes inherited parent share', count($inheritedEntries) >= 1);
	$assert('distinguishes link share', count($linkEntries) >= 1);

	// Secret stripping verification
	$encoded = json_encode($inspected, JSON_THROW_ON_ERROR);
	$assert('secret password is never returned', !str_contains($encoded, 'SecretSharePass123!'));
	$assert('raw link token is stripped', !str_contains($encoded, $linkShare->getToken()));
	$linkEntry = array_values($linkEntries)[0];
	$assert('link share has public-link identifier', $linkEntry['shareWith'] === 'public-link');

	// Stale revision on sharesRead
	$denied('stale file revision throws 409 on sharesRead', fn () => $broker->sharesRead($fileRef, 'stale-file-rev'), 409);

	// 7. Privilege check: User B (recipient without share permissions) attempting to inspect shares
	$session->setUser($userB);
	$denied('non-owner without share permissions is denied share inspection (403)', fn () => $broker->sharesRead($fileRef), 403);

	// Switch back to User A
	$session->setUser($userA);

	$assert('all native checks completed successfully', true);
} finally {
	// Cleanup shares
	foreach ($createdShares as $s) {
		try {
			$shares->deleteShare($s);
		} catch (\Throwable $e) {
			// ignore cleanup error
		}
	}
	// Cleanup files and folders
	try {
		$fixtureFolder->delete();
	} catch (\Throwable $e) {
		// ignore cleanup error
	}
	// Restore config
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, $oldPolicy);
	$config->setAppValue(Application::APP_ID, 'files_reads_enabled', $oldReadsEnabled);
}

echo json_encode([
	'layer' => 'real Nextcloud native Files & Sharing integration',
	'checks' => $checks,
	'summary' => count($checks) . ' native checks verified',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

