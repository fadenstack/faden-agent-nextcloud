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
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
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
$createdGroups = [];

try {
	// Enable files reads opt-in
	$config->setAppValue(Application::APP_ID, 'files_reads_enabled', '1');
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');
	$denied('omitted folder cannot fall back to root', fn () => $broker->organizeRead(), 400);
	$config->setAppValue(Application::APP_ID, 'files_reads_enabled', '0');
	$denied('native folder endpoint opt-in is required', fn () => $broker->organizeRead($folderRef), 403);
	$denied('native share endpoint opt-in is required', fn () => $broker->sharesRead($fileRef), 403);
	$config->setAppValue(Application::APP_ID, 'files_reads_enabled', '1');

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
	$restricted['tools'] = ['nc_read_file'];
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, json_encode($restricted, JSON_THROW_ON_ERROR));
	$denied('native tool allowlist enforced before folder lookup', fn () => $broker->organizeRead($subFolderRef), 403);
	$denied('native tool allowlist enforced before share lookup', fn () => $broker->sharesRead($fileRef), 403);
	$restricted['sources'] = [['kind' => 'folder', 'resourceId' => $folderRef]];
	$restricted['tools'] = ScopePolicy::EXTENDED_READ_TOOLS;
	$restricted['classificationRules'] = [['scope' => ['kind' => 'file', 'resourceId' => $fileRef], 'class' => 'protected']];
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, json_encode($restricted, JSON_THROW_ON_ERROR));
	$filtered = $broker->organizeRead($folderRef);
	$assert('protected child metadata is excluded before disclosure', !in_array('Contract-2026.md', array_column($filtered['entries'], 'filename'), true));
	$assert('filtered listing explicitly reports partial coverage', $filtered['partial'] === true);
	$legacyContext = $broker->select($fixtureFolder->getId());
	$legacyListing = $broker->listing($legacyContext['id']);
	$assert('implicit folder attachments filter protected child metadata before processing', $legacyListing['partial'] === true && !in_array('Contract-2026.md', array_column($legacyListing['files'], 'name'), true));

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
	$assert('sharesRead explicitly reports partial provider coverage', $inspected['complete'] === false && str_contains($inspected['coverage'], 'Other providers'));
	$assert('sharesRead binds target and native revision', $inspected['resourceId'] === $fileRef && $inspected['etag'] !== '');
	$inspected = $inspected['shares'];
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
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userB->getUID());
	$denied('non-owner without share permissions is denied share inspection (403)', fn () => $broker->sharesRead($fileRef), 403);
	$denied('cached share lineage rechecks inspection permission', fn () => $broker->scopeResource($fileRef, 'shares'), 403);
	$assert('recipient can retain file READ while share inspection is denied', $broker->scopeResource($fileRef, 'read')['resourceId'] === $fileRef);

	// Switch back to User A
	$session->setUser($userA);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userA->getUID());
	$shares->deleteShare($userShare);
	$shares->deleteShare($parentShare);
	$session->setUser($userB);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userB->getUID());
	$denied('revoked recipient loses file metadata read', fn () => $broker->scopeResource($fileRef, 'read'), 404);
	$session->setUser($userA);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userA->getUID());
	$resharable = $shares->createShare($shares->newShare()->setNode($fileA)->setShareType(IShare::TYPE_USER)->setSharedWith($userB->getUID())
		->setSharedBy($userA->getUID())->setShareOwner($userA->getUID())->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE));
	$createdShares[] = $resharable;
	$session->setUser($userB);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userB->getUID());
	$recipientShares = $broker->sharesRead($fileRef);
	$assert('recipient with SHARE can inspect without following owner-only parents', $recipientShares['resourceId'] === $fileRef && $recipientShares['complete'] === false && $recipientShares['shares'] === []);
	$session->setUser($userA);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userA->getUID());
	for ($index = 0; $index < 102; $index++) {
		$subFolder->newFile('bounded-' . $index . '.txt', 'Synthetic content');
	}
	$bounded = $broker->organizeRead($subFolderRef);
	$assert('native listing record cap leaves room for its parent lineage', count($bounded['entries']) === 100 && $bounded['partial'] === true);
	$groupManager = Server::get(IGroupManager::class);
	$prefix = 'native-read-bound-' . bin2hex(random_bytes(4)) . '-';
	for ($index = 0; $index < 51; $index++) {
		$group = $groupManager->createGroup($prefix . $index);
		$createdGroups[] = $group;
		$share = $shares->newShare()->setNode($fileA)->setShareType(IShare::TYPE_GROUP)->setSharedWith($group->getGID())
			->setSharedBy($userA->getUID())->setShareOwner($userA->getUID())->setPermissions(Constants::PERMISSION_READ);
		$createdShares[] = $shares->createShare($share);
	}
	$boundedShares = $broker->sharesRead($fileRef);
	$assert('native share enumeration caps records and never claims a full inventory', count($boundedShares['shares']) === 50 && $boundedShares['complete'] === false);
	$apps = Server::get(IAppManager::class);
	$apps->disableApp('files_sharing');
	try {
		$denied('disabled sharing app fails closed', fn () => $broker->sharesRead($fileRef), 503);
	} finally {
		$apps->enableApp('files_sharing');
	}

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
	foreach ($createdGroups as $group) {
		$group->delete();
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
