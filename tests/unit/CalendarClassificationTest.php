<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\CalendarClassification;
use PHPUnit\Framework\TestCase;

final class CalendarClassificationTest extends TestCase {
	public function testMissingOrPublicClassCannotLowerTheConfiguredClassification(): void {
		self::assertSame('unknown', CalendarClassification::sourceClass("SUMMARY:Test\nCLASS:PUBLIC\n", 'unknown'));
		self::assertSame('protected', CalendarClassification::sourceClass("CLASS:PUBLIC\n", 'protected'));
		self::assertSame('standard', CalendarClassification::sourceClass("SUMMARY:No class\n", 'standard'));
	}

	public function testPrivateConfidentialAndUnrecognizedValuesAreProtected(): void {
		foreach (['PRIVATE', 'CONFIDENTIAL', 'UNRECOGNIZED', ''] as $value) {
			self::assertSame('protected', CalendarClassification::sourceClass("CLASS:$value\n", 'standard'));
		}
	}

	public function testFoldingParametersAndRepeatedPropertiesCannotHideConfidentiality(): void {
		foreach (["CLASS:CONFI\r\n DENTIAL\r\n", "CLASS;X-LABEL=\"a:b\":CONFIDENTIAL\n", "CLASS:PUBLIC\nclass:private\n"] as $raw) {
			self::assertSame('protected', CalendarClassification::sourceClass($raw, 'standard'));
		}
	}

	public function testTextContainingTheWordClassIsNotAProperty(): void {
		self::assertSame('standard', CalendarClassification::sourceClass("SUMMARY:CLASS:CONFIDENTIAL\nX-CLASS:PRIVATE\n", 'standard'));
	}
}
