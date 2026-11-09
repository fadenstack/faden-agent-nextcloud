<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\EngineAuthority;
use OCA\FadenstackAgent\Providers\EngineDraft;
use OCA\FadenstackAgent\Providers\EngineJson;
use OCA\FadenstackAgent\Providers\EngineProvenance;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class EngineDraftTest extends TestCase {
	private function refused(callable $call): void {
		try {
			$call();
			self::fail('Expected a bounded draft refusal.');
		} catch (Problem $error) {
			self::assertContains($error->status, [400, 403, 413, 503]);
		}
	}
	public function testWindmillGraphReferencesUnknownFieldsAndPriorOriginSurviveMarking(): void {
		$value = EngineDraft::parse('windmill', '{"summary":"New draft","value":{"modules":[{"id":"a","value":{"type":"script","path":"u/alice/example","input_transforms":{}},"future":{}}]},"schema":{"properties":{},"x-fadenstack-provenance":{"opaque":"prior"}}}');
		$source = EngineDraft::source('windmill', $value);
		$marked = EngineJson::decode(EngineDraft::mark('windmill', $value, ['record' => (object)['id' => 'synthetic-record'], 'signature' => 'unit-only']));
		self::assertSame($source, EngineDraft::source('windmill', $marked));
		self::assertSame('prior', $marked->schema->{EngineDraft::MARKER}->previous_unverified->opaque);
		self::assertSame(hash('sha256', $source), $marked->schema->{EngineDraft::MARKER}->payload_sha256);
		self::assertInstanceOf(\stdClass::class, $marked->value->modules[0]->future);
		self::assertSame('u/alice/example', $marked->value->modules[0]->value->path);
	}
	public function testN8nTransformsBeforeApprovalAndStoresOriginWithoutChangingNodes(): void {
		$value = EngineDraft::parse('n8n', '{"name":"New draft","description":"Original description","nodes":[{"id":"1","credentials":{"httpHeaderAuth":{"id":"native-reference"}},"parameters":{}}],"connections":{},"settings":{"callerPolicy":"any","availableInMCP":true,"timezone":"Europe/Berlin"}}');
		self::assertSame('none', $value->settings->callerPolicy);
		self::assertFalse($value->settings->availableInMCP);
		self::assertSame('Europe/Berlin', $value->settings->timezone);
		$source = EngineDraft::source('n8n', $value);
		$marked = EngineJson::decode(EngineDraft::mark('n8n', $value, ['record' => (object)['id' => 'synthetic-record'], 'signature' => 'unit-only']));
		self::assertSame($source, EngineDraft::source('n8n', $marked));
		self::assertSame('Original description', $marked->description);
		self::assertStringStartsWith('<!-- fadenstack-provenance:', $marked->nodes[0]->notes);
		self::assertSame('native-reference', $marked->nodes[0]->credentials->httpHeaderAuth->id);
		self::assertInstanceOf(\stdClass::class, $marked->connections);
	}
	public function testUnknownRootFieldsActivationAndImpersonationAreNeverSilentlyDropped(): void {
		foreach (['active' => true, 'projectId' => 'other', 'shared' => [], 'pinData' => new \stdClass(), 'meta' => new \stdClass(), 'future' => 1] as $field => $value) {
			$this->refused(fn () => EngineDraft::parse('n8n', json_encode(['name' => 'New', 'nodes' => [], 'connections' => new \stdClass(), 'settings' => new \stdClass(), $field => $value])));
		}
		foreach (['on_behalf_of', 'on_behalf_of_email', 'job_token_scopes', 'force', 'path'] as $field) {
			$this->refused(fn () => EngineDraft::parse('windmill', json_encode(['summary' => 'New', 'value' => (object)['modules' => []], $field => 'untrusted'])));
		}
	}
	public function testMissingObjectShapesAndOversizedNodeCollectionsFailBeforeDispatch(): void {
		foreach (['[]', '{"name":"New","nodes":[],"connections":[],"settings":{}}', '{"name":"New","nodes":[1],"connections":{},"settings":{}}', '{"name":"New","nodes":[],"connections":{},"settings":{}}'] as $i => $json) {
			if ($i < 3) {
				$this->refused(fn () => EngineDraft::parse('n8n', $json));
			} else {
				$value = EngineJson::decode($json);
				$value->nodes = array_fill(0, 201, new \stdClass());
				$this->refused(fn () => EngineDraft::parse('n8n', EngineJson::encode($value)));
			}
		}
	}
	public function testReadbackChecksEveryApprovedFieldIncludingTheOriginRecord(): void {
		$value = EngineDraft::parse('n8n', '{"name":"New","nodes":[{"id":"a"}],"connections":{},"settings":{}}');
		$expected = EngineDraft::mark('n8n', $value, ['record' => (object)['id' => 'synthetic-record'], 'signature' => 'unit-only']);
		$actual = EngineJson::decode($expected);
		$actual->id = 'native-id';
		self::assertTrue(EngineDraft::matches($expected, $actual));
		$actual->description .= ' changed';
		self::assertFalse(EngineDraft::matches($expected, $actual));
		$actual = EngineJson::decode($expected);
		$actual->connections = [];
		self::assertFalse(EngineDraft::matches($expected, $actual));
	}
	public function testWrongArrayShapesAreDistinguishedFromTheNativeNodeLimit(): void {
		foreach (['windmill', 'n8n'] as $provider) {
			$base = $provider === 'windmill' ? (object)['summary' => 'New', 'value' => new \stdClass()]
				: (object)['name' => 'New', 'connections' => new \stdClass(), 'settings' => new \stdClass()];
			foreach ([null, new \stdClass(), (object)['0' => new \stdClass()]] as $nodes) {
				if ($provider === 'windmill') {
					$base->value->modules = $nodes;
				} else {
					$base->nodes = $nodes;
				}
				try {
					EngineDraft::parse($provider, EngineJson::encode($base));
					self::fail('A node map or missing array must not be accepted.');
				} catch (Problem $error) {
					self::assertSame(400, $error->status);
					self::assertStringContainsString($provider === 'windmill' ? 'value.modules' : 'n8n nodes', $error->getMessage());
				}
			}
			foreach ([0, 200, 201] as $count) {
				$nodes = array_fill(0, $count, new \stdClass());
				if ($provider === 'windmill') {
					$base->value->modules = $nodes;
				} else {
					$base->nodes = $nodes;
				}
				try {
					EngineDraft::parse($provider, EngineJson::encode($base));
					self::assertLessThanOrEqual(200, $count);
					self::assertFalse($provider === 'n8n' && $count === 0);
				} catch (Problem $error) {
					if ($provider === 'n8n' && $count === 0) {
						self::assertSame(400, $error->status);
						self::assertStringContainsString('empty workflow', $error->getMessage());
					} else {
						self::assertSame(201, $count);
						self::assertSame(413, $error->status);
					}
				}
			}
		}
	}
	public function testNodeNoteCarrierPreservesUserNotesParametersAndVisibilityWithoutAddingNodes(): void {
		$value = EngineDraft::parse('n8n', '{"name":"New","description":"Keep description","nodes":[{"id":"a","notes":"Grüße 世界","notesInFlow":true,"parameters":{"opaque":{}},"future":{"keep":true}},{"id":"b","notes":""}],"connections":{},"settings":{}}');
		$source = EngineDraft::source('n8n', $value);
		$marked = EngineJson::decode(EngineDraft::mark('n8n', $value, ['record' => (object)['id' => 'new-record'], 'signature' => 'unit-only']));
		self::assertCount(2, $marked->nodes);
		self::assertTrue($marked->nodes[0]->notesInFlow);
		self::assertTrue($marked->nodes[0]->future->keep);
		self::assertInstanceOf(\stdClass::class, $marked->nodes[0]->parameters->opaque);
		self::assertSame('', $marked->nodes[1]->notes);
		self::assertSame('Keep description', $marked->description);
		self::assertStringStartsWith("Grüße 世界\n<!-- fadenstack-provenance:", $marked->nodes[0]->notes);
		self::assertSame($source, EngineDraft::source('n8n', $marked));
	}
	public function testLegacyDescriptionOriginMovesIntoVersionedNodeNotesAndRetainsItsUnverifiedRecord(): void {
		$old = (object)['contract' => 'fadenstack/n8n-origin/v1', 'signed_record' => (object)['record' => (object)['id' => 'old-record']]];
		$value = EngineDraft::parse('n8n', EngineJson::encode((object)['name' => 'New', 'description' => "User description\n<!-- fadenstack-provenance:" . base64_encode(EngineJson::encode($old)) . ' -->',
			'nodes' => [(object)['id' => 'a', 'notes' => null]], 'connections' => new \stdClass(), 'settings' => new \stdClass()]));
		$source = EngineDraft::source('n8n', $value);
		$marked = EngineJson::decode(EngineDraft::mark('n8n', $value, ['record' => (object)['id' => 'new-record'], 'signature' => 'unit-only']));
		preg_match('/fadenstack-provenance:([A-Za-z0-9+\/=]+) -->$/D', $marked->nodes[0]->notes, $matches);
		$origin = EngineJson::decode(base64_decode($matches[1]));
		self::assertSame('fadenstack/n8n-origin/v2', $origin->contract);
		self::assertSame('n8n-draft-source-json/v2', $origin->payload_profile);
		self::assertSame('old-record', $origin->previous_unverified->signed_record->record->id);
		self::assertSame('User description', $marked->description);
		self::assertSame($source, EngineDraft::source('n8n', $marked));
		self::assertSame(hash('sha256', $source), $origin->payload_sha256);
	}
	public function testConflictingOrMalformedOriginNotesAndNonTextAnchorNotesFailClosed(): void {
		foreach ([['description' => '<!-- fadenstack-provenance:e30= -->', 'notes' => '<!-- fadenstack-provenance:W10= -->'],
			['description' => '', 'notes' => '<!-- fadenstack-provenance:e30= --> trailing'],
			['description' => '', 'notes' => new \stdClass()]] as $case) {
			$this->refused(function () use ($case): void {
				$value = EngineDraft::parse('n8n', EngineJson::encode((object)['name' => 'New', 'description' => $case['description'],
					'nodes' => [(object)['id' => 'a', 'notes' => $case['notes']]], 'connections' => new \stdClass(), 'settings' => new \stdClass()]));
				EngineDraft::source('n8n', $value);
			});
		}
	}
	public function testLegacyEmptyDraftReadbackDoesNotRequireTheNewWriterShape(): void {
		$legacy = EngineJson::encode(EngineJson::decode('{"name":"Old empty draft","description":"<!-- fadenstack-provenance:e30= -->","nodes":[],"connections":{},"settings":{}}'));
		$actual = EngineJson::decode($legacy);
		$actual->id = 'native-id';
		self::assertTrue(EngineDraft::matches($legacy, $actual));
		$actual->description = '';
		self::assertFalse(EngineDraft::matches($legacy, $actual));
	}
	public function testN8nOwnerRequiresOnePersonalProjectForTheVerifiedEnginePrincipal(): void {
		$owner = ['shared' => [(object)['role' => 'workflow:owner', 'project' => (object)['type' => 'personal', 'creatorId' => 'engine-alice']]]];
		EngineAuthority::assertN8nOwner($owner, 'engine-alice');
		self::assertTrue(true);
		$this->refused(fn () => EngineAuthority::assertN8nOwner($owner, 'engine-bob'));
		$owner['shared'][0]->project->type = 'team';
		$this->refused(fn () => EngineAuthority::assertN8nOwner($owner, 'engine-alice'));
	}
	public function testNativeCopiesPreserveUnspecifiedOpaqueFieldsAndOriginAcrossTopLevelReplacements(): void {
		foreach (['windmill', 'n8n'] as $provider) {
			$source = $provider === 'windmill'
				? '{"path":"u/alice/source","summary":"Source","description":"User description","value":{"modules":[{"id":"a","opaque":{"keep":true}}]},"schema":{"x-fadenstack-provenance":{"native":"prior"},"x-opaque":{}},"is_draft":true}'
				: '{"id":"source","name":"Source","description":"User description","nodes":[{"id":"a","notes":"User notes\\n<!-- fadenstack-provenance:eyJuYXRpdmUiOiJwcmlvciJ9 -->","credentials":{"ref":{"id":"keep"}},"parameters":{},"opaque":{}}],"connections":{},"settings":{"timezone":"Europe/Berlin"},"pinData":{"omit":"sample"},"shared":[]}';
			$patch = $provider === 'windmill' ? '{"summary":"Copy","schema":{"x-replacement":true}}' : '{"name":"Copy"}';
			$copy = EngineDraft::copy($provider, EngineJson::decode($source), $patch);
			self::assertSame('prior', $copy['origin']->native);
			self::assertObjectNotHasProperty($provider === 'windmill' ? 'path' : 'id', $copy['value']);
			$marked = EngineJson::decode(EngineDraft::mark($provider, $copy['value'], ['record' => (object)['id' => 'new']], ['origin' => $copy['origin']]));
			if ($provider === 'windmill') {
				self::assertSame('prior', $marked->schema->{EngineDraft::MARKER}->previous_unverified->native);
				self::assertTrue($marked->schema->{'x-replacement'});
				self::assertObjectNotHasProperty('x-opaque', $marked->schema, 'Top-level replacements do not pretend to deep merge.');
				self::assertTrue($marked->value->modules[0]->opaque->keep);
			} else {
				self::assertSame('keep', $marked->nodes[0]->credentials->ref->id);
				self::assertStringStartsWith("User notes\n", $marked->nodes[0]->notes);
				self::assertSame('Europe/Berlin', $marked->settings->timezone);
				self::assertObjectNotHasProperty('pinData', $marked);
				self::assertObjectNotHasProperty('shared', $marked);
			}
		}
	}
	public function testNativeCopyRejectsUnsupportedRootsAndModelSuppliedOrigins(): void {
		$source = EngineJson::decode('{"name":"Source","nodes":[{"id":"a"}],"connections":{},"settings":{}}');
		foreach (['[]', '{"id":"other"}', '{"active":true}', '{"nodes":[{"notes":"<!-- fadenstack-provenance:e30= -->"}]}'] as $patch) {
			$this->refused(fn () => EngineDraft::copy('n8n', $source, $patch));
		}
		$source->futureRoot = 'not silently dropped';
		$this->refused(fn () => EngineDraft::copy('n8n', $source, '{}'));
	}
	private static function b64(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
	private function signature(array $extraHeader = []): array {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		$csr = openssl_csr_new(['commonName' => 'Synthetic origin signer'], $key, ['digest_alg' => 'sha256']);
		$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
		openssl_x509_export($cert, $pem);
		$der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
		$record = (object)['contract' => 'provenance/v1', 'id' => 'test-record', 'metadata' => new \stdClass()];
		$head = self::b64(json_encode(['alg' => 'ES256', 'x5c' => [base64_encode($der)], ...$extraHeader]));
		$body = self::b64(EngineJson::encode($record));
		openssl_sign($head . '.' . $body, $signature, $key, OPENSSL_ALGO_SHA256);
		$lengthR = ord($signature[3]);
		$r = substr($signature, 4, $lengthR);
		$lengthS = ord($signature[5 + $lengthR]);
		$s = substr($signature, 6 + $lengthR, $lengthS);
		$raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
		return [['record' => $record, 'signature' => $head . '.' . $body . '.' . self::b64($raw)], ['signing_chains' => [$pem]]];
	}
	public function testValidPublisherPinnedEs256SignatureRetainsEmptyObjects(): void {
		[$signed, $anchors] = $this->signature();
		EngineProvenance::verifySignature($signed, $anchors);
		self::assertTrue(true);
	}
	public function testAnEmbeddedCertificateDoesNotGrantTrustAndPayloadTamperingFails(): void {
		[$signed, $anchors] = $this->signature();
		[, $other] = $this->signature();
		$this->refused(fn () => EngineProvenance::verifySignature($signed, $other));
		$signed['record']->id = 'changed';
		$this->refused(fn () => EngineProvenance::verifySignature($signed, $anchors));
	}
	public function testCriticalHeadersAndWrongAlgorithmsAreRefusedEvenWhenSigned(): void {
		foreach ([['crit' => ['unhandled']], ['alg' => 'none']] as $header) {
			[$signed, $anchors] = $this->signature($header);
			$this->refused(fn () => EngineProvenance::verifySignature($signed, $anchors));
		}
	}
}
