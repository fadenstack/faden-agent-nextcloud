<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\EngineJson;
use OCA\FadenstackAgent\Providers\EngineWebhookTargets;
use OCA\FadenstackAgent\Providers\N8nExecutePlan;
use OCA\FadenstackAgent\Service\Problem;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class N8nExecuteTest extends TestCase {
	private function target(): array {
		return ['id' => 'own-hook', 'connectionId' => 'own', 'workflowId' => 'flow1', 'path' => 'own-hook', 'nodeId' => 'node1', 'credentialId' => 'cred1'];
	}
	private function flow(): array {
		$node = (object)['id' => 'node1', 'name' => 'Webhook', 'type' => 'n8n-nodes-base.webhook', 'typeVersion' => 2,
			'parameters' => (object)['path' => 'own-hook', 'httpMethod' => 'POST', 'authentication' => 'headerAuth', 'responseMode' => 'lastNode', 'options' => new \stdClass()],
			'credentials' => (object)['httpHeaderAuth' => (object)['id' => 'cred1']]];
		return ['id' => 'flow1', 'active' => true, 'isArchived' => false, 'activeVersionId' => str_repeat('a', 36), 'versionId' => str_repeat('a', 36),
			'nodes' => [$node], 'connections' => new \stdClass(), 'activeVersion' => (object)['workflowId' => 'flow1', 'versionId' => str_repeat('a', 36), 'nodes' => [$node], 'connections' => new \stdClass()]];
	}
	private function refusal(callable $call): void {
		try {
			$call();
			self::fail('Expected native refusal.');
		} catch (Problem $error) {
			self::assertContains($error->status, [403, 503]);
		}
	}
	public function testOnlyTheConfiguredStaticAuthenticatedPublishedHookIsAccepted(): void {
		self::assertSame('Webhook', N8nExecutePlan::publishedHook($this->flow(), $this->target())->name);
		foreach (['active' => false, 'isArchived' => true, 'versionId' => str_repeat('b', 36), 'activeVersion' => null] as $key => $value) {
			$this->refusal(fn () => N8nExecutePlan::publishedHook([...$this->flow(), $key => $value], $this->target()));
		}
		foreach (['httpMethod' => 'GET', 'path' => 'other', 'authentication' => 'none', 'responseMode' => 'onReceived', 'options' => (object)['rawBody' => true]] as $key => $value) {
			$flow = $this->flow();
			$flow['nodes'][0]->parameters->{$key} = $value;
			$this->refusal(fn () => N8nExecutePlan::publishedHook($flow, $this->target()));
		}
		$this->refusal(fn () => N8nExecutePlan::publishedHook($this->flow(), [...$this->target(), 'credentialId' => 'other']));
	}
	private function proposal(): array {
		return ['id' => str_repeat('a', 48), 'target' => 'flow1', 'webhookNodeName' => 'Webhook',
			'payload' => ['version' => str_repeat('a', 36), 'bodyJson' => '{"_fadenstack":{"actionId":"' . str_repeat('a', 48) . '"},"data":{}}']];
	}
	private function execution(): array {
		$json = ['id' => '7', 'workflowId' => 'flow1', 'workflowVersionId' => str_repeat('a', 36), 'workflowData' => (object)['id' => 'flow1'], 'mode' => 'webhook',
			'finished' => true, 'status' => 'success', 'retryOf' => null,
			'data' => ['resultData' => ['runData' => ['Webhook' => [['data' => ['main' => [[['json' => ['headers' => ['secret' => 'do-not-project'], 'body' => EngineJson::decode($this->proposal()['payload']['bodyJson'])]]]]]]]]]]];
		return (array)json_decode(json_encode($json));
	}
	public function testNativeStatusNeverProjectsHeadersInputsOutputsOrGraph(): void {
		foreach (['success' => 'succeeded', 'running' => 'running', 'waiting' => 'running', 'error' => 'failed', 'canceled' => 'cancelled'] as $status => $state) {
			$result = N8nExecutePlan::observe($this->proposal(), [...$this->execution(), 'status' => $status]);
			self::assertSame($state, $result['executionState']);
			self::assertTrue($result['dispatchVerified']);
			self::assertFalse($result['externalEffectsVerified']);
			self::assertStringNotContainsString('do-not-project', json_encode($result));
			self::assertArrayNotHasKey('data', $result);
		}
	}
	public function testWrongVersionWorkflowOrArgumentsNeverVerifyAndMissingCorrelationStaysUnknown(): void {
		foreach (['workflowId' => 'other', 'workflowVersionId' => str_repeat('b', 36), 'mode' => 'manual', 'retryOf' => '2', 'finished' => null, 'status' => 'invented'] as $key => $value) {
			$this->refusal(fn () => N8nExecutePlan::observe($this->proposal(), [...$this->execution(), $key => $value]));
		}
		$job = $this->execution();
		$job['data']->resultData->runData->Webhook[0]->data->main[0][0]->json->body->data->changed = true;
		$this->refusal(fn () => N8nExecutePlan::observe($this->proposal(), $job));
		$job = $this->execution();
		$job['data']->resultData->runData->Webhook[0]->data->main[0][0]->json->body->_fadenstack->actionId = str_repeat('b', 48);
		self::assertNull(N8nExecutePlan::observe($this->proposal(), $job));
	}
	public function testTargetConfigurationCannotIntroduceUrlsHeadersOrTraversal(): void {
		foreach (['path' => '../escape', 'workflowId' => 'other?x=y', 'nodeId' => [], 'url' => 'https://other.test', 'header' => 'Authorization'] as $key => $value) {
			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')->willReturn(json_encode([[...$this->target(), $key => $value]]));
			$this->refusal(fn () => (new EngineWebhookTargets($config))->all());
		}
	}
}
