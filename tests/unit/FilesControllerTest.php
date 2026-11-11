<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests;

use OCA\FadenstackAgent\Controller\FilesController;
use OCA\FadenstackAgent\Identity\CloudIdentityVerifier;
use OCA\FadenstackAgent\Service\Configuration;
use OCA\FadenstackAgent\Service\FileService;
use OCA\FadenstackAgent\Service\SearchAccessService;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FilesControllerTest extends TestCase {
	public function testUnexpectedSearchFailureLogsOnlyCodeLocationAndKeepsPublicErrorGeneric(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('X-Fadenstack-Session')->willReturn('native-binding');
		$files = $this->createMock(FileService::class);
		$files->expects(self::once())->method('check')->with('native-binding');
		$search = $this->createMock(SearchAccessService::class);
		$search->method('plan')->willThrowException(new \RuntimeException('sensitive-content-do-not-log'));
		$config = $this->createMock(Configuration::class);
		$http = $this->createMock(IClientService::class);
		$http->expects(self::never())->method('newClient');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'Native Files runtime failure ({class} at {file}:{line}).',
			self::callback(static fn (array $context): bool => array_keys($context) === ['app', 'class', 'file', 'line']
				&& $context['class'] === \RuntimeException::class && $context['file'] === 'FilesControllerTest.php'
				&& is_int($context['line']) && !str_contains(json_encode($context), 'sensitive-content')),
		);
		$controller = new FilesController('fadenstack_agent', $request, $files, $search, new CloudIdentityVerifier($config, $http), $config, $logger);
		$response = $controller->searchPlan(['query' => 'synthetic']);
		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'The file operation failed. No success is claimed.'], $response->getData());
	}
}
