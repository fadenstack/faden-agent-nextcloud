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
		self::assertStringStartsWith("Original description\n<!-- fadenstack-provenance:", $marked->description);
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
		$value = EngineDraft::parse('n8n', '{"name":"New","nodes":[],"connections":{},"settings":{}}');
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
	public function testN8nOwnerRequiresOnePersonalProjectForTheVerifiedEnginePrincipal(): void {
		$owner = ['shared' => [(object)['role' => 'workflow:owner', 'project' => (object)['type' => 'personal', 'creatorId' => 'engine-alice']]]];
		EngineAuthority::assertN8nOwner($owner, 'engine-alice');
		self::assertTrue(true);
		$this->refused(fn () => EngineAuthority::assertN8nOwner($owner, 'engine-bob'));
		$owner['shared'][0]->project->type = 'team';
		$this->refused(fn () => EngineAuthority::assertN8nOwner($owner, 'engine-alice'));
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
