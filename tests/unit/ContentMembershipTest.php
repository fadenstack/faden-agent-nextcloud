<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\ContentAuthority;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class ContentMembershipTest extends TestCase {
	private function form(): array {
		return ['id' => 1, 'questions' => [['id' => 2, 'formId' => 1, 'type' => 'multiple_unique', 'text' => 'Track', 'isRequired' => true, 'options' => [['id' => 3, 'questionId' => 2, 'text' => 'Alpha']]]]];
	}
	public function testQuestionAndOptionMembershipIsVerifiedIndependentlyOfTheHttpStatus(): void {
		ContentAuthority::assertFormMembership($this->form(), 1);
		self::assertTrue(true);
		$foreign = $this->form();
		$foreign['questions'][0]['options'][0]['questionId'] = 99;
		$this->expectException(Problem::class);
		ContentAuthority::assertFormMembership($foreign, 1);
	}
	public function testForeignQuestionAndDuplicateNativeIdsFailClosed(): void {
		$foreign = $this->form();
		$foreign['questions'][0]['formId'] = 99;
		try {
			ContentAuthority::assertFormMembership($foreign, 1);
			self::fail('Accepted a foreign question.');
		} catch (Problem) {
			self::assertTrue(true);
		}
		$duplicate = $this->form();
		$duplicate['questions'][] = $duplicate['questions'][0];
		$this->expectException(Problem::class);
		ContentAuthority::assertFormMembership($duplicate, 1);
	}
}
