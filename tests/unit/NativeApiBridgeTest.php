<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\NativeApiBridge;
use OCA\FadenstackAgent\Providers\NativeRequest;
use OCA\FadenstackAgent\Service\Problem;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class NativeApiBridgeTest extends TestCase {
	private function fixture(array $settings = []): array {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $settings[$key] ?? $default);
		$config->method('getSystemValueString')->willReturn('https://cloud.test/nextcloud');
		$clients = $this->createMock(IClientService::class);
		$client = $this->createMock(IClient::class);
		$clients->method('newClient')->willReturn($client);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn ($name) => $settings[$name] ?? match ($name) {
			'Cookie' => 'native_session=fixture-only', 'requesttoken' => 'native-csrf', default => 'must-not-be-forwarded',
		});
		$session = $this->createMock(ISession::class);
		$session->method('getId')->willReturn('native-session-id');
		$users = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users->method('getUser')->willReturn($user);
		return [new NativeApiBridge($config, $clients, $request, $session, $users), $client, $session];
	}

	public function testDelegatesOnlyToCanonicalInstanceAfterClosingSession(): void {
		[$bridge, $client, $session] = $this->fixture();
		$closed = false;
		$session->expects(self::once())->method('close')->willReturnCallback(static function () use (&$closed): void {
			$closed = true;
		});
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(207);
		$response->method('getBody')->willReturn('<d:multistatus/>');
		$response->method('getHeader')->willReturnCallback(static fn ($key) => $key === 'Content-Type' ? 'application/xml' : '');
		$client->expects(self::once())->method('request')->with('PROPFIND', 'https://cloud.test/nextcloud/remote.php/dav/calendars/alice/',
			self::callback(static function (array $options) use (&$closed): bool {
				self::assertTrue($closed, 'The sibling request must not wait on our session lock');
				self::assertFalse($options['allow_redirects']);
				self::assertFalse($options['cookies']);
				self::assertSame('native_session=fixture-only', $options['headers']['Cookie']);
				self::assertSame('native-csrf', $options['headers']['requesttoken']);
				self::assertArrayNotHasKey('Authorization', $options['headers']);
				self::assertArrayNotHasKey('X-Fadenstack-Agent-Token', $options['headers']);
				return true;
			}))->willReturn($response);
		self::assertSame(207, $bridge->send(NativeRequest::calendarCollections('alice'))['status']);
	}

	public function testForeignActorIsRefusedBeforeAnyNativeRequest(): void {
		[$bridge, $client] = $this->fixture();
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$bridge->send(NativeRequest::calendarCollections('bob'));
	}

	public function testDifferentConfiguredOriginCannotReceiveNativeCookies(): void {
		[$bridge, $client] = $this->fixture(['native_api_base' => 'https://faden.test']);
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$bridge->send(NativeRequest::calendarCollections('alice'));
	}

	public function testCookieHeaderInjectionIsRefused(): void {
		[$bridge, $client] = $this->fixture(['Cookie' => "session=x\r\nAuthorization: x"]);
		$client->expects(self::never())->method('request');
		$this->expectException(Problem::class);
		$bridge->send(NativeRequest::calendarCollections('alice'));
	}

	public function testNativeRedirectIsNotReportedAsSuccess(): void {
		[$bridge, $client] = $this->fixture();
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(302);
		$client->method('request')->willReturn($response);
		$this->expectException(Problem::class);
		$bridge->send(NativeRequest::calendarCollections('alice'));
	}

	public function testBoundedStreamIsClosedOnOversizeFailure(): void {
		[$bridge, $client] = $this->fixture();
		$body = fopen('php://temp', 'r+');
		fwrite($body, str_repeat('x', NativeApiBridge::MAX_BYTES + 1));
		rewind($body);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(207);
		$response->method('getBody')->willReturn($body);
		$client->method('request')->willReturn($response);
		try {
			$bridge->send(NativeRequest::calendarCollections('alice'));
			self::fail('Oversized response accepted');
		} catch (Problem $error) {
			self::assertSame(413, $error->status);
			self::assertFalse(is_resource($body));
		}
	}

	public function testClosedRouteRejectsEncodedAndRawTraversalBeforeDispatch(): void {
		foreach (['..', '../bob', 'x/y', "x\r\nDepth: 4", 'https://other.test'] as $id) {
			try {
				NativeRequest::calendarObject('alice', $id, 'event.ics');
				self::fail('Unsafe identifier accepted');
			} catch (Problem) {
				self::assertTrue(true);
			}
		}
		self::assertStringContainsString('/%252e%252e/', NativeRequest::calendarObject('alice', '%2e%2e', 'event.ics')->path);
	}
}
