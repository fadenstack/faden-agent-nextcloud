<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Providers\WorkflowAuthority;
use OCA\FadenstackAgent\Providers\WorkflowPlan;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class WorkflowPlanTest extends TestCase {
	private function draft(): array {
		$check = ['class' => WorkflowPlan::CHECK, 'operator' => 'is', 'value' => 'synthetic.txt'];
		return ['class' => WorkflowPlan::ACTION, 'name' => 'Synthetic draft', 'checks' => [$check, [...$check, 'operator' => '!is']], 'operation' => '1', 'entity' => WorkflowPlan::ENTITY, 'events' => [WorkflowPlan::EVENT]];
	}
	public function testDraftCannotRunForAnyFilenameAndActivationIsASeparateNativePayload(): void {
		$draft = WorkflowPlan::validateRecipe($this->draft());
		foreach (['synthetic.txt', 'SYNTHETIC.TXT', '', 'unrelated.txt'] as $filename) {
			$is = mb_strtolower($filename) === mb_strtolower($draft['checks'][0]['value']);
			$isNot = mb_strtolower($filename) !== mb_strtolower($draft['checks'][1]['value']);
			self::assertFalse($is && $isNot);
		}
		$request = NativeRequest::flow('alice', 'create', 0, $this->draft());
		self::assertSame('/ocs/v2.php/apps/workflowengine/api/v1/workflows/user?format=json', $request->path);
		self::assertCount(2, json_decode($request->body, true)['checks']);
		$active = $draft;
		$active['checks'] = [$active['checks'][0]];
		self::assertCount(1, json_decode(NativeRequest::flow('alice', 'update', 2, $active)->body, true)['checks']);
	}
	public function testUnsupportedOrModifiedNativeRecipesNeverLoseFieldsSilently(): void {
		foreach (['class', 'condition', 'events', 'operation', 'extra'] as $case) {
			$rule = $this->draft();
			if ($case === 'class') {
				$rule['class'] = 'Unreviewed\\Operation';
			} elseif ($case === 'condition') {
				$rule['checks'][1]['value'] = 'another.txt';
			} elseif ($case === 'events') {
				$rule['events'][] = 'UnreviewedEvent';
			} elseif ($case === 'operation') {
				$rule['operation'] = '1,2';
			} else {
				$rule['credentials'] = 'must-not-be-dropped';
			}
			try {
				WorkflowPlan::validateRecipe($rule);
				self::fail('Accepted modified ' . $case);
			} catch (Problem $error) {
				self::assertContains($error->status, [400, 409]);
			}
		}
	}
	public function testListedRulesMustCarryTheCurrentPersonalOwnerBeforeMetadataProjection(): void {
		$rule = [...$this->draft(), 'id' => 2, 'scope_type' => 1, 'scope_actor_id' => 'alice'];
		$rules = WorkflowAuthority::nativeRules([WorkflowPlan::ACTION => [$rule]], 'alice');
		self::assertCount(2, WorkflowPlan::validateRecipe($rules[2])['checks']);
		foreach ([[0, 'alice'], [1, 'bob'], [null, 'alice']] as [$type, $actor]) {
			$invalid = [...$rule, 'scope_type' => $type, 'scope_actor_id' => $actor];
			try {
				WorkflowAuthority::nativeRules([WorkflowPlan::ACTION => [$invalid]], 'alice');
				self::fail('Accepted another native scope.');
			} catch (Problem $error) {
				self::assertSame(403, $error->status);
			}
		}
	}
	public function testCreationHasNoReplacePathAndKeepsActorAndSegmentsBound(): void {
		$request = NativeRequest::createWhiteboard('alice', 'Design space', 'New board.whiteboard');
		self::assertSame('PUT', $request->method);
		self::assertSame('/remote.php/dav/files/alice/Design%20space/New%20board.whiteboard', $request->path);
		self::assertSame('*', $request->headers['If-None-Match']);
		self::assertSame([], json_decode($request->body, true)['elements']);
		foreach (['../escape', 'a//b', 'a\\b'] as $folder) {
			try {
				NativeRequest::createWhiteboard('alice', $folder, 'New.whiteboard');
				self::fail('Accepted an invalid folder.');
			} catch (Problem $error) {
				self::assertSame(400, $error->status);
			}
		}
	}
	public function testMalformedNativeIdentitiesAreNeverCoercedIntoARealRule(): void {
		foreach ([true, 1.0, [], null, '01', '1e0'] as $id) {
			$rule = [...$this->draft(), 'id' => $id, 'scope_type' => 1, 'scope_actor_id' => 'alice'];
			try {
				WorkflowAuthority::nativeRules([WorkflowPlan::ACTION => [$rule]], 'alice');
				self::fail('Accepted a malformed native identity.');
			} catch (Problem $error) {
				self::assertSame(503, $error->status);
			}
		}
		$this->expectException(Problem::class);
		WorkflowAuthority::nativeRules(null, 'alice');
	}
}
