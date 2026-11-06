<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\History\EffectProgress;
use OCA\FadenstackAgent\History\JournalException;
use PHPUnit\Framework\TestCase;

final class EffectProgressTest extends TestCase {
	public function testCompoundSavePreservesNativeIdentifiersAndDispatchOrder(): void {
		$state = EffectProgress::advance('nc_mail_save_draft', [], 'save_local_draft', 'dispatched');
		$state = EffectProgress::advance('nc_mail_save_draft', $state, 'save_local_draft', 'observed', ['nativeId' => 42]);
		$state = EffectProgress::advance('nc_mail_save_draft', $state, 'persist_imap', 'dispatched');
		$state = EffectProgress::advance('nc_mail_save_draft', $state, 'persist_imap', 'observed', ['nativeId' => 83, 'revision' => str_repeat('a', 64)]);
		self::assertSame(42, $state[0]['facts']['nativeId']);
		self::assertSame(83, $state[1]['facts']['nativeId']);
	}

	public function testSmtpMustFollowObservedUnscheduledOutbox(): void {
		$this->expectException(JournalException::class);
		EffectProgress::advance('nc_mail_send_draft', [['step' => 'stage_outbox', 'phase' => 'dispatched', 'facts' => []]], 'send_smtp', 'dispatched');
	}

	public function testDispatchCannotBeRepeatedAfterTimeoutOrObservation(): void {
		$state = EffectProgress::advance('nc_mail_send_draft', [], 'stage_outbox', 'dispatched');
		$this->expectException(JournalException::class);
		EffectProgress::advance('nc_mail_send_draft', $state, 'stage_outbox', 'dispatched');
	}

	public function testObservationCannotBeRecordedWithoutDispatch(): void {
		$this->expectException(JournalException::class);
		EffectProgress::advance('nc_mail_send_draft', [], 'stage_outbox', 'observed', ['nativeId' => 1]);
	}

	public function testCheckpointDoesNotAcceptAnArbitraryOperation(): void {
		$this->expectException(JournalException::class);
		EffectProgress::advance('nc_talk_send_message', [], 'send_smtp', 'dispatched');
	}

	public function testFactsCannotContainCredentialsOrPayloadChanges(): void {
		$state = EffectProgress::advance('nc_mail_send_draft', [], 'stage_outbox', 'dispatched');
		$this->expectException(JournalException::class);
		EffectProgress::advance('nc_mail_send_draft', $state, 'stage_outbox', 'observed', ['nativeId' => 4, 'token' => 'secret']);
	}

	public function testCorruptProgressDoesNotPermitANewSend(): void {
		$this->expectException(JournalException::class);
		EffectProgress::advance('nc_mail_send_draft', [['step' => 'send_smtp', 'phase' => 'observed']], 'send_smtp', 'dispatched');
	}
}
