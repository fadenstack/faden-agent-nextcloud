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
	$assert('proposal announces execution readiness when native guard is active', $proposal['executionReady'] === true);
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

	// 13c. Conditional DAV is supported via ticketed native DAV effect guard holding LOCK_SHARED.
	$condProp = $broker->proposeFolder($folderRef, 'CondFolder');
	$broker->approveFolder($condProp['id']);
	$plan = $broker->commitFolder($condProp['id'], 1);
	$assert('protocol 1 returns dav-create-folder plan with ticketed native guard', is_array($plan) && ($plan['kind'] ?? '') === 'dav-create-folder');
	$assert('plan binds receiptId', $plan['receiptId'] === $condProp['id']);
	$assert('plan binds parent resource ID', $plan['parent_resource_id'] === $folderRef);
	$assert('plan binds name CondFolder', $plan['name'] === 'CondFolder');

	// Dispatch ticketed MKCOL through native DAV guard
	$davReq = new \Sabre\HTTP\Request('MKCOL', $plan['dav_path'], [
		'If-None-Match' => '*',
		'X-Fadenstack-Folder-Ticket' => $plan['receiptId'],
	]);
	$davResp = new \Sabre\HTTP\Response();
	$guardPlugin = Server::get(\OCA\FadenstackAgent\DAV\FolderCreateGuardPlugin::class);
	$guardPlugin->beforeMkcol($davReq, $davResp);
	$childCond = $fixtureFolder->newFolder('CondFolder');
	$davResp->setStatus(201);
	$guardPlugin->afterMkcol($davReq, $davResp);

	// Finalize folder creation via finishFolder
	$createdFileId = \OCP\Files\DavUtil::getDavFileId($childCond->getId());
	$verifiedCond = $broker->finishFolder($plan['receiptId'], $createdFileId);
	$assert('finishFolder verifies created conditional folder', $verifiedCond['outcome'] === 'verified');
	$assert('verified conditional folder binds exact created ID', $verifiedCond['resourceId'] === 'nextcloud:files:' . $childCond->getId());
	$assert('created folder exists on fixture', $fixtureFolder->nodeExists('CondFolder'));

	// 13d. Decisive acceptance check: parent substitution race is BLOCKED by native DAV guard
	$subProp = $broker->proposeFolder($folderRef, 'SubRaceFolder');
	$broker->approveFolder($subProp['id']);
	$subPlan = $broker->commitFolder($subProp['id'], 1);

	// Concurrent adversary moves fixture folder and recreates an empty one with the same name
	$oldFolderName = $fixtureFolder->getName();
	$fixtureFolder->move($homeA->getPath() . '/' . $oldFolderName . '-moved');
	$replacementFolder = $homeA->newFolder($oldFolderName);
	$assert('replacement folder has different ID', $replacementFolder->getId() !== $fixtureFolder->getId());

	// Agent MKCOL arrives at original path with ticket for original folder ID
	$raceReq = new \Sabre\HTTP\Request('MKCOL', $subPlan['dav_path'], [
		'If-None-Match' => '*',
		'X-Fadenstack-Folder-Ticket' => $subPlan['receiptId'],
	]);
	$raceResp = new \Sabre\HTTP\Response();
	$substitutionCaught = false;
	try {
		$guardPlugin->beforeMkcol($raceReq, $raceResp);
	} catch (\Sabre\DAV\Exception\Conflict $e) {
		$substitutionCaught = true;
		$guardPlugin->onException($e);
	}
	$assert('native DAV guard blocks parent substitution with 409 Conflict', $substitutionCaught);
	$assert('replacement folder remains empty (no write redirected)', !$replacementFolder->nodeExists('SubRaceFolder'));

	// Cleanup replacement folder and move original fixture back
	$replacementFolder->delete();
	$fixtureFolder->move($homeA->getPath() . '/' . $oldFolderName);

	// 13e. Ticket replay rejection: consumed ticket is rejected
	$replayReq = new \Sabre\HTTP\Request('MKCOL', $plan['dav_path'], [
		'If-None-Match' => '*',
		'X-Fadenstack-Folder-Ticket' => $plan['receiptId'],
	]);
	$replayResp = new \Sabre\HTTP\Response();
	$replayDenied = false;
	try {
		$guardPlugin->beforeMkcol($replayReq, $replayResp);
	} catch (\Sabre\DAV\Exception\Forbidden $e) {
		$replayDenied = true;
	}
	$assert('replaying consumed folder ticket is denied with 403', $replayDenied);

	// Legacy receipt reconciliation validates an actual native response identity.
	$legacyProp = $broker->proposeFolder($folderRef, 'LegacyReceipt');
	$created = $fixtureFolder->newFolder('LegacyReceipt');
	$nativeSession = Server::get(ISession::class);
	$state = $nativeSession->get('fadenstack_agent.state');
	$legacy = ['receiptId' => $legacyProp['id'], 'folderId' => $fixtureFolder->getId(),
		'folderPath' => '/' . ltrim($homeA->getRelativePath($fixtureFolder->getPath()), '/'), 'name' => 'LegacyReceipt',
		'target' => $legacyProp['target'], 'parentEtag' => $fixtureFolder->getEtag(), 'expires' => time() + 600,
		'identityScope' => $legacyProp['identityScope'], 'identitySession' => $legacyProp['identitySession']];
	$state['folderReceipts'][$legacyProp['id']] = $legacy;
	$nativeSession->set('fadenstack_agent.state', $state);
	$denied('missing creation response ID is refused', fn () => $broker->finishFolder($legacyProp['id']), 400);
	$denied('invalid creation response ID is refused', fn () => $broker->finishFolder($legacyProp['id'], 'not-an-id'), 400);
	$denied('numeric-prefix ID substitution is refused', fn () => $broker->finishFolder($legacyProp['id'], (string)$created->getId() . 'forged'), 409);
	$state = $nativeSession->get('fadenstack_agent.state');
	$state['folderReceipts'][$legacyProp['id']] = $legacy;
	$nativeSession->set('fadenstack_agent.state', $state);
	$verified = $broker->finishFolder($legacyProp['id'], \OCP\Files\DavUtil::getDavFileId($created->getId()));
	$assert('valid legacy receipt verifies exact native DAV identity', $verified['outcome'] === 'verified' && $verified['resourceId'] === 'nextcloud:files:' . $created->getId());
	$denied('legacy receipt finalization remains single-use', fn () => $broker->finishFolder($legacyProp['id'], \OCP\Files\DavUtil::getDavFileId($created->getId())), 410);

	// Cancellation works before the proposal response is available, and after configuration changes.
	$nonce = 'request-11111111-1111-4111-8111-111111111111';
	$broker->cancelFolder($nonce);
	$denied('cancel-before-propose tombstone refuses a reordered preview', fn () => $broker->proposeFolder($folderRef, 'TooLate', null, $nonce), 410);
	$nonce2 = 'request-22222222-2222-4222-8222-222222222222';
	$correlated = $broker->proposeFolder($folderRef, 'Correlated', null, $nonce2);
	$broker->cancelFolder($nonce2);
	$denied('cancellation by request nonce removes pending proposal', fn () => $broker->approveFolder($correlated['id']), 410);
	$changedConfig = $broker->proposeFolder($folderRef, 'ChangedConfig');
	$oldAgent = $config->getAppValue(Application::APP_ID, 'agent', '');
	$config->setAppValue(Application::APP_ID, 'agent', 'changed-profile');
	$broker->cancelFolder($changedConfig['id']);
	$config->setAppValue(Application::APP_ID, 'agent', $oldAgent);
	$denied('own-session cancellation survives a changed agent configuration', fn () => $broker->approveFolder($changedConfig['id']), 410);

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

	// Repeated cancellation recovers proposal quota; tombstones stay bounded.
	$state = $nativeSession->get('fadenstack_agent.state');
	$state['folderProposals'] = [];
	$nativeSession->set('fadenstack_agent.state', $state);
	for ($index = 0; $index < 12; $index++) {
		$item = $broker->proposeFolder($folderRef, 'Cancelled' . $index);
		$broker->cancelFolder($item['id']);
	}
	$assert('twelve cancelled previews recover the ten-proposal quota immediately', count($nativeSession->get('fadenstack_agent.state')['folderProposals']) === 0);
	$enabled = $config->getAppValue(Application::APP_ID, 'integration_enabled', '0');
	$config->setAppValue(Application::APP_ID, 'integration_enabled', '0');
	$broker->checkSession($nativeSession->get('fadenstack_agent.state')['binding']);
	$assert('native session cleanup does not require an enabled integration', $broker->cancelFolder($nonce)['cancelled']);
	$denied('ordinary operations remain disabled during cleanup', fn () => $broker->check($nativeSession->get('fadenstack_agent.state')['binding']), 403);
	$config->setAppValue(Application::APP_ID, 'integration_enabled', $enabled);
	for ($index = 0; $index < 101; $index++) {
		$broker->cancelFolder(sprintf('request-%08x-1111-4111-8111-111111111111', $index));
	}
	$assert('cancellation tombstones are bounded to one hundred entries', count($nativeSession->get('fadenstack_agent.state')['folderCancellations']) === 100);
	$denied('overflow refuses new previews without evicting live cancellation records', fn () => $broker->proposeFolder($folderRef, 'Overflow'), 429);

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
