<?php

declare(strict_types=1);

if (getenv('FADEN_DISPOSABLE_TEST') !== 'native-folder-create-20261029') {
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
use OCP\ISession;
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
		if ($error->status !== $status) {
			fwrite(STDERR, "Problem status was {$error->status}, expected {$status} ({$error->getMessage()})\n");
		}
		$assert($name, $error->status === $status);
		return;
	} catch (\Throwable $other) {
		fwrite(STDERR, 'Caught other throwable: ' . $other::class . ': ' . $other->getMessage() . "\n");
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
$fixtureFolder = $homeA->newFolder('FolderCreateFixture_' . bin2hex(random_bytes(4)));
$existingChild = $fixtureFolder->newFolder('AlreadyExists');
$fileA = $fixtureFolder->newFile('Document.txt', '# Document');

$folderRef = 'nextcloud:files:' . $fixtureFolder->getId();
$fileRef = 'nextcloud:files:' . $fileA->getId();

$broker = Server::get(FileService::class);
$oldPolicy = $config->getAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');
$oldCreateEnabled = $config->getAppValue(Application::APP_ID, 'folder_create_enabled', '0');

$createdShares = [];

try {
	// 1. Opt-in gating check: default off (0) rejects propose
	$config->setAppValue(Application::APP_ID, 'folder_create_enabled', '0');
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');
	$denied('folder creation rejected when folder_create_enabled is 0', fn () => $broker->proposeFolder($folderRef, 'NewDir'), 403);

	// Enable opt-in
	$config->setAppValue(Application::APP_ID, 'folder_create_enabled', '1');

	// 2. Canonical reference binding: paths or URLs rejected with 400
	$denied('relative or absolute path rejected with 400', fn () => $broker->proposeFolder('/FolderCreateFixture', 'SubDir'), 400);
	$denied('arbitrary URL rejected with 400', fn () => $broker->proposeFolder('https://cloud.test/f/50', 'SubDir'), 400);

	// 3. File context rejection: selecting a file must NEVER grant its parent or root (fails 415)
	$denied('file reference rejected for folder creation (fails 415)', fn () => $broker->proposeFolder($fileRef, 'SubDir'), 415);

	// 4. Folder name validation: invalid characters, traversal, empty names rejected with 400
	$denied('empty folder name rejected with 400', fn () => $broker->proposeFolder($folderRef, ''), 400);
	$denied('dot folder name rejected with 400', fn () => $broker->proposeFolder($folderRef, '.'), 400);
	$denied('dot-dot traversal rejected with 400', fn () => $broker->proposeFolder($folderRef, '..'), 400);
	$denied('slash in folder name rejected with 400', fn () => $broker->proposeFolder($folderRef, 'a/b'), 400);
	$denied('backslash in folder name rejected with 400', fn () => $broker->proposeFolder($folderRef, 'a\\b'), 400);
	$denied('overlong name (>100 chars) rejected with 400', fn () => $broker->proposeFolder($folderRef, str_repeat('x', 101)), 400);

	// 5. Existing folder name collision rejected during propose with 409
	$denied('existing name rejected during propose with 409', fn () => $broker->proposeFolder($folderRef, 'AlreadyExists'), 409);

	// 6. Stale parent revision rejected during propose with 409
	$denied('stale parent revision rejected with 409', fn () => $broker->proposeFolder($folderRef, 'ValidSubDir', 'stale-parent-etag-999'), 409);

	// 7. Destination root scope policy check
	$otherFolder = $homeA->newFolder('UnrelatedRoot_' . bin2hex(random_bytes(4)));
	$otherFolderRef = 'nextcloud:files:' . $otherFolder->getId();
	$restricted = [
		'revision' => 'restricted-dest-only',
		'sources' => [['kind' => 'folder', 'resourceId' => $folderRef]],
		'destinations' => [['kind' => 'folder', 'resourceId' => $folderRef]],
		'tools' => ['nc_files_create_folder'],
		'sourceClasses' => ['standard'],
		'classificationDefault' => 'standard',
		'destinationAudiences' => ['private'],
		'downstreamPolicy' => 'unverified',
		'movements' => [['source' => 'standard', 'destination' => 'private']],
	];
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, json_encode($restricted, JSON_THROW_ON_ERROR));

	// Outside allowed destination roots fails 404
	$denied('folder creation outside destination roots rejected with 404', fn () => $broker->proposeFolder($otherFolderRef, 'DeniedDir'), 404);

	// Reset policy to unrestricted
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, '');

	// 8. Successful proposal with Unicode name
	$unicodeName = 'Projekte 📁 2026';
	$proposal = $broker->proposeFolder($folderRef, $unicodeName);
	$assert('proposeFolder returns valid proposal array', is_array($proposal) && !empty($proposal['id']));
	$assert('proposal binds toolName nc_files_create_folder', $proposal['toolName'] === 'nc_files_create_folder');
	$assert('proposal binds resourceId', $proposal['resourceId'] === $folderRef);
	$assert('proposal binds folderId', $proposal['folderId'] === $fixtureFolder->getId());
	$assert('proposal binds name', $proposal['name'] === $unicodeName);
	$assert('proposal approved is false', $proposal['approved'] === false);
	$assert('proposal announces execution gate before approval', $proposal['executionReady'] === true);
	$assert('proposal has future expiration', $proposal['expires'] > time());
	$assert('proposal target has full path', str_ends_with($proposal['target'], '/' . $unicodeName));

	$proposalId = $proposal['id'];

	// 9. Reading proposal via folderProposal($proposalId)
	$readProposal = $broker->folderProposal($proposalId);
	$assert('folderProposal retrieves the pending proposal', $readProposal['id'] === $proposalId);

	// 10. Commit before approval MUST fail with 403
	$denied('commitFolder before approval fails with 403', fn () => $broker->commitFolder($proposalId), 403);

	// 11. Approval via approveFolder($proposalId)
	$approved = $broker->approveFolder($proposalId);
	$assert('approveFolder marks proposal approved=true', $approved['approved'] === true);

	// 12. Execution remains gated: newFolder cannot establish exclusive creation.
	$outcome = $broker->commitFolder($proposalId);
	$assert('commitFolder returns outcome not-dispatched', $outcome['outcome'] === 'not-dispatched');
	$assert('commitFolder returns history not-integrated', $outcome['history'] === 'not-integrated');
	$assert('blocked receipt binds exact proposal', $outcome['proposalId'] === $proposalId);
	$assert('blocked receipt binds exact target', $outcome['name'] === $unicodeName && $outcome['parentResourceId'] === $folderRef);
	$assert('blocked receipt never fabricates a created identity', !isset($outcome['resourceId'], $outcome['observedState']));
	$assert('execution gate performs no filesystem creation', !$fixtureFolder->nodeExists($unicodeName));

	// 13. Replay attack: proposal ticket was consumed and cannot be reused
	$denied('replaying consumed proposal fails with 410', fn () => $broker->commitFolder($proposalId), 410);

	// 13b. Cancellation and proposal quota recovery
	$cancelProp = $broker->proposeFolder($folderRef, 'ToCancel');
	$cancelled = $broker->cancelFolder($cancelProp['id']);
	$assert('cancelFolder returns cancelled true', ($cancelled['cancelled'] ?? false) === true);
	$denied('cancelled proposal cannot be approved', fn () => $broker->approveFolder($cancelProp['id']), 410);

	// 13c. Conditional folder creation protocol with folderProtocol = 1
	$condProp = $broker->proposeFolder($folderRef, 'CondFolder');
	$broker->approveFolder($condProp['id']);
	$plan = $broker->commitFolder($condProp['id'], 1);
	$assert('commitFolder with protocol 1 returns dav-create-folder plan', ($plan['kind'] ?? '') === 'dav-create-folder');
	$assert('plan binds receiptId', !empty($plan['receiptId']));
	$assert('plan binds dav_path', str_contains($plan['dav_path'], 'CondFolder'));
	$assert('plan binds parent_resource_id', $plan['parent_resource_id'] === $folderRef);
	$assert('plan binds parent_etag', !empty($plan['parent_etag']));
	$assert('plan binds name', $plan['name'] === 'CondFolder');

	// finishFolder before folder exists fails 409 and consumes receipt
	$denied('finishFolder fails 409 before folder exists on filesystem', fn () => $broker->finishFolder($plan['receiptId']), 409);
	$denied('replaying consumed finishFolder fails 410', fn () => $broker->finishFolder($plan['receiptId']), 410);

	// 13d. Successful conditional folder creation and verification
	$condProp2 = $broker->proposeFolder($folderRef, 'CondVerified');
	$broker->approveFolder($condProp2['id']);
	$plan2 = $broker->commitFolder($condProp2['id'], 1);

	// Simulate client WebDAV MKCOL 201
	$createdFolderNode = $fixtureFolder->newFolder('CondVerified');
	$verifiedOutcome = $broker->finishFolder($plan2['receiptId'], (string)$createdFolderNode->getId());
	$assert('finishFolder returns verified outcome', ($verifiedOutcome['outcome'] ?? '') === 'verified');
	$assert('verified outcome returns history not-integrated', ($verifiedOutcome['history'] ?? '') === 'not-integrated');
	$assert('verified outcome binds created resourceId', $verifiedOutcome['resourceId'] === 'nextcloud:files:' . $createdFolderNode->getId());
	$assert('verified outcome binds folder metadata', is_array($verifiedOutcome['folder']));
	$assert('verified outcome observedState is directory', ($verifiedOutcome['observedState']['is_directory'] ?? false) === true);

	// finishFolder cannot be replayed (fails 410)
	$denied('replaying finishFolder fails 410', fn () => $broker->finishFolder($plan2['receiptId']), 410);

	// 14. Expiration, configuration/session substitution and native Unicode bounds.
	$expired = $broker->proposeFolder($folderRef, 'Expired');
	$nativeSession = Server::get(ISession::class);
	$state = $nativeSession->get('fadenstack_agent.state');
	$state['folderProposals'][$expired['id']]['expires'] = time() - 1;
	$nativeSession->set('fadenstack_agent.state', $state);
	$denied('expired approval is refused', fn () => $broker->approveFolder($expired['id']), 410);
	$identityChanged = $broker->proposeFolder($folderRef, 'IdentityChanged');
	$state = $nativeSession->get('fadenstack_agent.state');
	$originalBinding = $state['binding'];
	$state['binding'] = bin2hex(random_bytes(32));
	$nativeSession->set('fadenstack_agent.state', $state);
	$denied('changed native session invalidates proposal', fn () => $broker->approveFolder($identityChanged['id']), 409);
	$state['binding'] = $originalBinding;
	$nativeSession->set('fadenstack_agent.state', $state);
	$config->setAppValue(Application::APP_ID, 'development', '0');
	$denied('development opt-out refuses commit', fn () => $broker->commitFolder($identityChanged['id']), 403);
	$config->setAppValue(Application::APP_ID, 'development', '1');
	$unicode = $broker->proposeFolder($folderRef, str_repeat('ä', 100));
	$assert('native name accepts 100 Unicode characters within 255 bytes', $unicode['name'] === str_repeat('ä', 100));
	$denied('native name refuses more than 255 UTF-8 bytes', fn () => $broker->proposeFolder($folderRef, str_repeat('📁', 64)), 400);
	$denied('native name refuses surrounding whitespace', fn () => $broker->proposeFolder($folderRef, ' padded '), 400);

	// 15. Native permission refusal: read-only share recipient cannot create folder
	// Share fixture folder with User B read-only (PERMISSION_READ only, no PERMISSION_CREATE)
	$roShare = $shares->newShare();
	$roShare->setNode($fixtureFolder)
		->setShareType(IShare::TYPE_USER)
		->setSharedWith($userB->getUID())
		->setSharedBy($userA->getUID())
		->setShareOwner($userA->getUID())
		->setPermissions(Constants::PERMISSION_READ);
	$roShare = $shares->createShare($roShare);
	$createdShares[] = $roShare;

	// Switch session to User B
	$session->setUser($userB);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userB->getUID());

	// User B trying to propose folder creation inside User A's read-only shared folder
	$denied('user without native create permissions is denied (fails 403)', fn () => $broker->proposeFolder($folderRef, 'DeniedByPerms'), 403);

	// Switch back to User A
	$session->setUser($userA);
	\OC_Util::tearDownFS();
	\OC_Util::setupFS($userA->getUID());

	$checks[] = ['case' => 'all_native_folder_create_checks_passed', 'pass' => true];
} finally {
	// Cleanup shares
	foreach ($createdShares as $s) {
		try {
			$shares->deleteShare($s);
		} catch (\Throwable) {
		}
	}
	// Restore config
	$config->setAppValue(Application::APP_ID, ScopePolicy::CONFIG_KEY, $oldPolicy);
	$config->setAppValue(Application::APP_ID, 'folder_create_enabled', $oldCreateEnabled);

	// Cleanup test folders
	try {
		$fixtureFolder->delete();
	} catch (\Throwable) {
	}
	if (isset($otherFolder)) {
		try {
			$otherFolder->delete();
		} catch (\Throwable) {
		}
	}
}

fwrite(STDOUT, json_encode(['status' => 'ok', 'total' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
exit(0);
