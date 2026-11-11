<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

require_once __DIR__ . '/MetadataStore.php';

use OCA\FadenstackAgent\Identity\CloudApi;
use OCA\FadenstackAgent\Providers\EngineProvenance;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileProvenance;
use OCA\FadenstackAgent\Service\Problem;
use OCA\FadenstackAgent\Service\ProvenanceService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Full save/journal behavior is exercised through the native app, not an obsolete mocked FileService. */
class FileServiceProvenanceTest extends TestCase {
	use MetadataStore;

	private function service(array $policy): FileProvenance {
		$config = $this->createMock(Configuration::class);
		$config->method('get')->willReturn(['serverUrl' => 'https://cloud.test', 'agent' => 'nc', 'development' => false]);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeader')->willReturnCallback(fn ($key) => $key === 'Content-Type' ? 'application/json' : '');
		$response->method('getBody')->willReturn(json_encode($policy));
		$client = $this->createMock(IClient::class);
		$client->method('get')->with('https://cloud.test/v1/agents/nc/provenance/policy', self::anything())->willReturn($response);
		$client->expects(self::never())->method('post');
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$cloud = new CloudApi($config, $clients);
		return new FileProvenance($cloud, new EngineProvenance($cloud), new ProvenanceService($this->metadataManager(), $this->createMock(LoggerInterface::class)));
	}

	public function testExemptContentKeepsExactBytesAndNeverCallsTheSigner(): void {
		$plan = $this->service(['on_missing' => 'record', 'exempt_edit_kinds' => ['translated']])->prepare('Bonjour', 'New.md', 'translated', str_repeat('a', 32));
		self::assertSame('Bonjour', $plan['content']);
		self::assertNull($plan['record']);
	}

	public function testStricterLabelAndWatermarkPoliciesRefuseBeforeApprovalOrSigning(): void {
		foreach ([['visible_label' => true], ['on_missing' => 'refuse', 'short_text_tokens' => 0]] as $policy) {
			try {
				$this->service($policy)->prepare('A long draft', 'New.md', 'generated', str_repeat('a', 32));
				self::fail('An unsupported stricter policy must not silently write.');
			} catch (Problem $error) {
				self::assertSame(422, $error->status);
			}
		}
	}

	public function testMalformedMarkingAuthorityDoesNotFallBackToUnmarkedWrites(): void {
		$this->expectException(Problem::class);
		$this->service(['on_missing' => 'future-permissive'])->prepare('Draft', 'New.md', 'generated', str_repeat('a', 32));
	}

	public function testNormalizationMatchesTheSharedTextContractIncludingNfcAndNonWhitespaceBom(): void {
		self::assertSame('Café next', FileProvenance::normalizeText(" Cafe\u{0301}\t\nnext\x07\x01 "));
		self::assertSame("\u{FEFF}text", FileProvenance::normalizeText("\u{FEFF}text"));
		self::assertSame('one two', FileProvenance::normalizeText("one\u{0085}\u{001C}two"));
	}

	public function testAPolicyChangeInvalidatesThePreparedPlanWithoutSigningAgain(): void {
		try {
			$this->service(['on_missing' => 'refuse'])->assertCurrent(['policy' => ['on_missing' => 'record']], str_repeat('a', 32));
			self::fail('Changed marking authority must require a new preview.');
		} catch (Problem $error) {
			self::assertSame(409, $error->status);
		}
	}

	public function testMalformedReaderHashCannotReachTheSigner(): void {
		$this->expectException(Problem::class);
		$this->service([])->prepare('Draft', 'New.md', 'generated', str_repeat('a', 32), 'not-a-digest');
	}
}
