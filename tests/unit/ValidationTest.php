<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase {
	public function testSecureOrigins(): void {
		self::assertSame('https://ai.example.org:8443', Configuration::origin('https://ai.example.org:8443/', false));
		self::assertSame('http://localhost:8000', Configuration::origin('http://localhost:8000', true));
		self::assertSame('http://[::1]:8000', Configuration::origin('http://[::1]:8000', true));
	}
	public function testHttpRequiresExplicitDevelopment(): void {
		$this->expectException(Problem::class);
		Configuration::origin('http://localhost:8000', false);
	}
	public function testDevelopmentDoesNotAllowRemoteHttp(): void {
		$this->expectException(Problem::class);
		Configuration::origin('http://192.0.2.61', true);
	}
	public function testCredentialsCannotAppearInServerUrls(): void {
		$this->expectException(Problem::class);
		Configuration::origin('https://user:password@example.org', false);
	}
	public function testServerPathIsNotAnOrigin(): void {
		$this->expectException(Problem::class);
		Configuration::origin('https://example.org/another-service', false);
	}
	public function testTraversalIsRejected(): void {
		$this->expectException(Problem::class);
		FileService::filename('../secret.md');
	}
	public function testBackslashIsRejected(): void {
		$this->expectException(Problem::class);
		FileService::filename('folder\\secret.md');
	}
	public function testExecutableExtensionIsRejected(): void {
		$this->expectException(Problem::class);
		FileService::filename('shell.php');
	}
	public function testControlCharacterIsRejected(): void {
		$this->expectException(Problem::class);
		FileService::filename("bad\0.md");
	}
	public function testSupportedFilenameIsAccepted(): void {
		FileService::filename('Zusammenfassung.md');
		self::assertTrue(true);
	}
}
