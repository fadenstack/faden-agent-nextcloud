<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\History\JournalIdentity;
use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\History\JournalService;
use OCA\FadenstackAgent\History\VerifiedReadback;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\Problem;
use OCP\ISession;
use OCP\IUserSession;

/** Native, current-session Contacts discovery, reads, and conditional mutations via CardDAV. */
final class ContactsProvider {
	private const KEY = 'fadenstack_contacts_state';

	public function __construct(
		private NativeApiBridge $bridge,
		private ProviderPolicy $policy,
		private Configuration $configuration,
		private ISession $session,
		private IUserSession $users,
		private JournalService $journal,
	) {
	}

	private function state(): array {
		$state = $this->session->get(self::KEY);
		if (!is_array($state) || ($state['binding'] ?? null) !== $this->session->getId()) {
			$state = ['binding' => $this->session->getId(), 'proposals' => []];
			$this->session->set(self::KEY, $state);
		}
		return $state;
	}

	private function setState(array $state): void {
		$this->session->set(self::KEY, $state);
	}

	private function journalIdentity(array $policy): JournalIdentity {
		$state = $this->state();
		$binding = hash('sha256', json_encode([$state['binding'], $this->configuration->get(), $policy['policyVersion']], JSON_THROW_ON_ERROR));
		return new JournalIdentity($policy['actor'], JournalIdentity::nativeScope(), $binding);
	}

	private function assertProposalIdentity(array $proposal, array $policy): JournalIdentity {
		$identity = $this->journalIdentity($policy);
		if (($proposal['identityScope'] ?? null) !== $identity->scopeHash || ($proposal['identitySession'] ?? null) !== $identity->sessionHash) {
			throw new Problem('The account, device, profile or scope changed. Review a new proposal.', 409);
		}
		return $identity;
	}

	private function proposal(string $id): array {
		$p = $this->state()['proposals'][$id] ?? null;
		if (!$p || $p['expires'] < time()) {
			throw new Problem('The proposal expired or was already consumed.', 410);
		}
		return $p;
	}

	private function assertPrivateContact(string $vcf): void {
		if ($vcf === '' || strlen($vcf) > 65536 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $vcf)) {
			throw new Problem('Invalid vCard contact data.', 400);
		}
		// Refuse external photo fetch or embedded large binary attachments
		if (preg_match('/(?:^|\r?\n)PHOTO(?:;[^:]*)?:https?:\/\//i', $vcf)) {
			throw new Problem('External photo fetching is not permitted.', 400);
		}
	}

	private function document(array $response): \DOMXPath {
		if ($response['status'] !== 207 || str_contains(strtoupper($response['body']), '<!DOCTYPE')) {
			throw new Problem('The native Contacts response is invalid.', 503);
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			$loaded = $document->loadXML($response['body'], LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		if (!$loaded || $document->documentElement?->localName !== 'multistatus' || $document->documentElement?->namespaceURI !== 'DAV:') {
			throw new Problem('The native Contacts response is invalid.', 503);
		}
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('d', 'DAV:');
		$xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:carddav');
		return $xpath;
	}

	private function text(\DOMXPath $xpath, string $expression, \DOMNode $node): string {
		return (string)$xpath->evaluate('string(' . $expression . ')', $node);
	}

	public function capabilities(): array {
		$policy = $this->policy->contacts();
		$writesEnabled = $this->configuration->providerWritesEnabled() && ($policy['writesEnabled'] ?? false);
		return [[
			'provider' => 'contacts',
			'apiVersion' => 'carddav-v1',
			'policyVersion' => $policy['policyVersion'],
			'enabled' => $policy['enabled'],
			'tools' => $writesEnabled
				? ['nc_contacts_list', 'nc_contacts_find', 'nc_contacts_read', 'nc_contacts_create', 'nc_contacts_patch', 'nc_contacts_delete']
				: ['nc_contacts_list', 'nc_contacts_find', 'nc_contacts_read'],
			'conflict' => 'conditional',
			'history' => 'audit-only',
			'refresh' => 'manual',
			'canRead' => true,
			'canWrite' => $writesEnabled,
		]];
	}

	public function books(): array {
		$policy = $this->policy->contacts();
		$this->policy->assertDisclosure($policy);
		$xpath = $this->document($this->bridge->send(NativeRequest::addressbookCollections($policy['actor'])));
		$books = [];
		foreach ($xpath->query('/d:multistatus/d:response') as $response) {
			$href = $this->text($xpath, 'd:href', $response);
			$types = $xpath->query('d:propstat/d:prop/d:resourcetype/*', $response);
			$isBook = false;
			foreach ($types as $type) {
				if ($type->localName === 'addressbook' && $type->namespaceURI === 'urn:ietf:params:xml:ns:carddav') {
					$isBook = true;
					break;
				}
			}
			if (!$isBook) {
				continue;
			}
			$segments = array_values(array_filter(explode('/', trim($href, '/'))));
			$id = rawurldecode(end($segments) ?: '');
			if (!in_array($id, $policy['collections'], true)) {
				continue;
			}
			$title = $this->text($xpath, 'd:propstat/d:prop/d:displayname', $response);
			$books[] = [
				'provider' => 'contacts',
				'resourceId' => 'nextcloud:contacts:' . rtrim(strtr(base64_encode($id), '+/', '-_'), '='),
				'collectionId' => $id,
				'revision' => $this->text($xpath, 'd:propstat/d:prop/d:getetag', $response) ?: 'book-r1',
				'sourceClass' => $policy['sourceClass'],
				'audience' => $policy['audience'],
				'policyVersion' => $policy['policyVersion'],
				'title' => $title !== '' ? $title : $id,
			];
		}
		return $books;
	}

	public function find(string $query): array {
		$policy = $this->policy->contacts();
		$this->policy->assertDisclosure($policy);
		if (strlen($query) < 1 || strlen($query) > 80) {
			throw new Problem('Use search text between 1 and 80 characters.', 400);
		}
		$books = $this->books();
		$found = [];
		$safeQuery = htmlspecialchars($query, ENT_XML1, 'UTF-8');
		$body = "<c:addressbook-query xmlns:d=\"DAV:\" xmlns:c=\"urn:ietf:params:xml:ns:carddav\"><d:prop><d:getetag/><c:address-data/></d:prop><c:filter><c:prop-filter name=\"FN\"><c:text-match collation=\"i;unicode-casemap\" match-type=\"contains\">{$safeQuery}</c:text-match></c:prop-filter></c:filter></c:addressbook-query>";

		foreach (array_slice($books, 0, 10) as $book) {
			$bookId = $book['collectionId'];
			$xpath = $this->document($this->bridge->send(new NativeRequest(
				'REPORT',
				"/remote.php/dav/addressbooks/users/{$policy['actor']}/{$bookId}/",
				['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'],
				$body
			)));
			foreach ($xpath->query('/d:multistatus/d:response') as $response) {
				$href = $this->text($xpath, 'd:href', $response);
				$segments = array_values(array_filter(explode('/', trim($href, '/'))));
				$filename = rawurldecode(end($segments) ?: '');
				if (!str_ends_with(strtolower($filename), '.vcf')) {
					continue;
				}
				$content = $this->text($xpath, 'd:propstat/d:prop/c:address-data', $response);
				if ($content === '') {
					continue;
				}
				$etag = trim($this->text($xpath, 'd:propstat/d:prop/d:getetag', $response), '"') ?: 'contact-r1';
				$found[] = [
					'provider' => 'contacts',
					'resourceId' => 'nextcloud:contacts:' . rtrim(strtr(base64_encode("{$bookId}\0{$filename}"), '+/', '-_'), '='),
					'collectionId' => $bookId,
					'filename' => $filename,
					'revision' => $etag,
					'sourceClass' => $policy['sourceClass'],
					'audience' => $policy['audience'],
					'policyVersion' => $policy['policyVersion'],
					'contentProfile' => 'vcard-xml-normalized',
					'content' => $content,
				];
				if (count($found) >= 20) {
					return ['contacts' => $found, 'complete' => false];
				}
			}
		}
		return ['contacts' => $found, 'complete' => true];
	}

	public function read(string $bookId, ?string $filename = null): array {
		$policy = $this->policy->contacts();
		$this->policy->assertDisclosure($policy);
		if (!in_array($bookId, $policy['collections'], true)) {
			throw new Problem('The address book is outside the configured scope.', 404);
		}
		if ($filename !== null && $filename !== '') {
			NativeRequest::segment($filename);
			$res = $this->bridge->send(NativeRequest::addressbookObject($policy['actor'], $bookId, $filename));
			if ($res['status'] !== 200) {
				throw new Problem('The contact resource is not available.', 404);
			}
			return [
				'etag' => trim($res['etag'] ?? '', '"'),
				'content' => $res['body'],
			];
		}

		$xpath = $this->document($this->bridge->send(NativeRequest::addressbookObjects($policy['actor'], $bookId)));
		$contacts = [];
		foreach ($xpath->query('/d:multistatus/d:response') as $response) {
			$href = $this->text($xpath, 'd:href', $response);
			$segments = array_values(array_filter(explode('/', trim($href, '/'))));
			$fn = rawurldecode(end($segments) ?: '');
			if (!str_ends_with(strtolower($fn), '.vcf')) {
				continue;
			}
			$content = $this->text($xpath, 'd:propstat/d:prop/c:address-data', $response);
			if ($content === '') {
				continue;
			}
			$contacts[] = [
				'provider' => 'contacts',
				'resourceId' => 'nextcloud:contacts:' . rtrim(strtr(base64_encode("{$bookId}\0{$fn}"), '+/', '-_'), '='),
				'collectionId' => $bookId,
				'filename' => $fn,
				'revision' => trim($this->text($xpath, 'd:propstat/d:prop/d:getetag', $response), '"') ?: 'contact-r1',
				'sourceClass' => $policy['sourceClass'],
				'audience' => $policy['audience'],
				'policyVersion' => $policy['policyVersion'],
				'contentProfile' => 'vcard-xml-normalized',
				'content' => $content,
			];
			if (count($contacts) >= 50) {
				break;
			}
		}
		return ['contacts' => $contacts, 'complete' => count($contacts) < 50];
	}

	public function propose(string $operation, string $bookId, string $filename, string $vcardData, ?string $expectedEtag = null): array {
		$policy = $this->policy->contacts();
		$this->policy->assertDisclosure($policy);
		if (!($policy['writesEnabled'] ?? false)) {
			throw new Problem('Contacts mutations have been disabled by the administrator.', 403);
		}
		if (!in_array($bookId, $policy['collections'], true)) {
			throw new Problem('The address book is outside the configured scope.', 404);
		}
		NativeRequest::segment($filename);
		if (!str_ends_with(strtolower($filename), '.vcf')) {
			throw new Problem('A valid filename ending in .vcf is required.', 400);
		}
		if (!in_array($operation, ['create', 'patch', 'delete'], true)) {
			throw new Problem('Unsupported contacts operation.', 400);
		}

		$identity = $this->journalIdentity($policy);

		if ($operation === 'create') {
			try {
				$existing = $this->bridge->send(NativeRequest::addressbookObject($policy['actor'], $bookId, $filename));
				if ($existing['status'] === 200) {
					throw new Problem('A contact with this name already exists.', 409);
				}
			} catch (Problem $probe) {
				if ($probe->status !== 404) {
					throw $probe;
				}
			}
			$this->assertPrivateContact($vcardData);
			$expectedEtag = null;
		} elseif ($operation === 'patch') {
			if ($expectedEtag === null || $expectedEtag === '' || str_starts_with(trim($expectedEtag), 'W/') || str_starts_with(trim($expectedEtag), 'w/')) {
				throw new Problem('A strong native expectedEtag is required.', 400);
			}
			$current = $this->bridge->send(NativeRequest::addressbookObject($policy['actor'], $bookId, $filename));
			if ($current['status'] !== 200) {
				throw new Problem('The contact resource is not available.', 404);
			}
			$currentEtag = trim($current['etag'], '"');
			if ($currentEtag !== trim($expectedEtag, '"')) {
				throw new Problem('The contact resource changed. Read it again before proposing a write.', 409);
			}
			$this->assertPrivateContact($current['body']);
			$this->assertPrivateContact($vcardData);
		} else { // delete
			if ($expectedEtag === null || $expectedEtag === '' || str_starts_with(trim($expectedEtag), 'W/') || str_starts_with(trim($expectedEtag), 'w/')) {
				throw new Problem('A strong native expectedEtag is required.', 400);
			}
			$current = $this->bridge->send(NativeRequest::addressbookObject($policy['actor'], $bookId, $filename));
			if ($current['status'] !== 200) {
				throw new Problem('The contact resource is not available.', 404);
			}
			$currentEtag = trim($current['etag'], '"');
			if ($currentEtag !== trim($expectedEtag, '"')) {
				throw new Problem('The contact resource changed. Read it again before proposing a write.', 409);
			}
			$this->assertPrivateContact($current['body']);
			$vcardData = '';
		}

		$p = [
			'id' => bin2hex(random_bytes(24)),
			'operation' => $operation,
			'toolName' => 'nc_contacts_' . $operation,
			'collectionId' => $bookId,
			'filename' => $filename,
			'expectedEtag' => $expectedEtag,
			'content' => $vcardData,
			'hash' => hash('sha256', $vcardData),
			'target' => "Address book {$bookId}, contact {$filename}",
			'expires' => time() + 600,
			'approved' => false,
			'identityScope' => $identity->scopeHash,
			'identitySession' => $identity->sessionHash,
		];

		$state = $this->state();
		$state['proposals'] = array_filter($state['proposals'] ?? [], fn ($item) => $item['expires'] > time());
		if (count($state['proposals']) >= 10) {
			throw new Problem('Too many pending proposals. Wait for them to expire.', 429);
		}
		$state['proposals'][$p['id']] = $p;
		$this->setState($state);

		return [
			'id' => $p['id'],
			'operation' => $p['operation'],
			'toolName' => $p['toolName'],
			'target' => $p['target'],
			'collectionId' => $bookId,
			'filename' => $filename,
			'hash' => $p['hash'],
			'expires' => $p['expires'],
		];
	}

	public function approve(string $id): array {
		$policy = $this->policy->contacts();
		$this->policy->assertDisclosure($policy);
		if (!($policy['writesEnabled'] ?? false)) {
			throw new Problem('Contacts mutations have been disabled by the administrator.', 403);
		}
		$p = $this->proposal($id);
		$this->assertProposalIdentity($p, $policy);
		$state = $this->state();
		$state['proposals'][$id]['approved'] = true;
		$this->setState($state);
		return ['approved' => true];
	}

	public function commit(string $id): array {
		$policy = $this->policy->contacts();
		$this->policy->assertDisclosure($policy);
		if (!($policy['writesEnabled'] ?? false)) {
			throw new Problem('Contacts mutations have been disabled by the administrator.', 403);
		}
		$p = $this->proposal($id);
		$identity = $this->assertProposalIdentity($p, $policy);
		if (!($p['approved'] ?? false)) {
			throw new Problem('The proposal must be approved before execution.', 403);
		}

		$state = $this->state();
		unset($state['proposals'][$id]);
		$this->setState($state);

		$journalResource = new JournalResource('contacts', $p['collectionId'], $p['filename']);
		$journalEntry = $this->journal->prepare($identity, $p['toolName'], $journalResource, $p['content'], $p['expectedEtag'] ?? '', 'audit-only', 'not-integrated');
		$this->journal->markDispatched($identity, $journalEntry['id'], $journalEntry['ticket']);

		$this->session->close();

		$request = match ($p['operation']) {
			'create' => NativeRequest::createAddressbookObject($policy['actor'], $p['collectionId'], $p['filename'], $p['content']),
			'patch' => NativeRequest::updateAddressbookObject($policy['actor'], $p['collectionId'], $p['filename'], $p['content'], $p['expectedEtag']),
			'delete' => NativeRequest::deleteAddressbookObject($policy['actor'], $p['collectionId'], $p['filename'], $p['expectedEtag']),
		};

		try {
			$res = $this->bridge->send($request);
		} catch (Problem $err) {
			if ($err->status === 412) {
				$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'failed');
				throw new Problem('The contact resource was modified by another actor.', 412);
			}
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
			throw $err;
		}

		try {
			$verify = $this->bridge->send(NativeRequest::addressbookObject($policy['actor'], $p['collectionId'], $p['filename']));
			if ($p['operation'] === 'delete') {
				$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
				throw new Problem('The contact resource could not be confirmed deleted.', 409);
			}
			$finalEtag = trim($verify['etag'], '"');
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'verified', new VerifiedReadback($finalEtag));
			return [
				'status' => 'saved',
				'outcome' => 'verified',
				'etag' => $finalEtag,
				'target' => $p['target'],
				'collectionId' => $p['collectionId'],
				'filename' => $p['filename'],
			];
		} catch (Problem $verifyErr) {
			if ($p['operation'] === 'delete' && $verifyErr->status === 404) {
				$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'verified', new VerifiedReadback('absent'));
				return [
					'status' => 'deleted',
					'outcome' => 'verified',
					'target' => $p['target'],
					'collectionId' => $p['collectionId'],
					'filename' => $p['filename'],
				];
			}
			$this->journal->finish($identity, $journalEntry['id'], $journalEntry['ticket'], 'unknown');
			throw $verifyErr;
		}
	}
}
