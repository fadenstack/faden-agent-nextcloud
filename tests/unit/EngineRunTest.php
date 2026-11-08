<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\EngineRunPlan;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class EngineRunTest extends TestCase {
	private function proposal(): array {
		return ['workspace' => 'sandbox', 'enginePrincipal' => 'alice', 'target' => 'u/alice/test',
			'payload' => ['jobId' => EngineRunPlan::jobId(str_repeat('a', 48)), 'version' => '7', 'argsJson' => '{"nested":{},"text":"synthetic"}']];
	}
	private function job(): array {
		return ['id' => $this->proposal()['payload']['jobId'], 'workspace_id' => 'sandbox', 'created_by' => 'alice', 'permissioned_as' => 'u/alice',
			'script_path' => 'u/alice/test', 'script_hash' => '0000000000000007', 'job_kind' => 'flow', 'type' => 'CompletedJob', 'success' => true, 'canceled' => false,
			'args' => (object)['text' => 'synthetic', 'nested' => new \stdClass()], 'logs' => 'must not escape', 'result' => ['sensitive' => 'must not escape']];
	}
	public function testReadbackSeparatesDispatchNativeStateAndExternalEffects(): void {
		foreach (['succeeded' => [], 'failed' => ['success' => false], 'cancelled' => ['canceled' => true], 'running' => ['type' => 'QueuedJob']] as $state => $change) {
			$result = EngineRunPlan::observe($this->proposal(), [...$this->job(), ...$change]);
			self::assertSame($state, $result['executionState']);
			self::assertTrue($result['dispatchVerified']);
			self::assertFalse($result['externalEffectsVerified']);
			self::assertArrayNotHasKey('logs', $result);
			self::assertArrayNotHasKey('args', $result);
			self::assertArrayNotHasKey('result', $result);
		}
	}
	public function testWrongAccountVersionWorkflowArgumentsAndMalformedStateCannotVerifyAJob(): void {
		foreach (['id' => EngineRunPlan::jobId(str_repeat('b', 48)), 'workspace_id' => 'other', 'created_by' => 'bob', 'permissioned_as' => 'u/bob',
			'script_path' => 'u/alice/other', 'script_hash' => '0000000000000008', 'job_kind' => 'script', 'args' => new \stdClass(),
			'type' => 'unknown', 'success' => 'true', 'canceled' => null] as $field => $value) {
			try {
				EngineRunPlan::observe($this->proposal(), [...$this->job(), $field => $value]);
				self::fail('Expected refusal: ' . $field);
			} catch (Problem $error) {
				self::assertSame(503, $error->status);
			}
		}
	}
	public function testJobIdIsDeterministicUniqueAndBoundedByTheNativeActionId(): void {
		$id = EngineRunPlan::jobId(str_repeat('a', 48));
		self::assertSame($id, EngineRunPlan::jobId(str_repeat('a', 48)));
		self::assertNotSame($id, EngineRunPlan::jobId(str_repeat('b', 48)));
		self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id);
	}
}
