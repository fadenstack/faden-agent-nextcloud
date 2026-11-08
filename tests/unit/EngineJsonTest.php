<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Tests\Unit;

use OCA\FadenstackAgent\Providers\EngineJson;
use OCA\FadenstackAgent\Service\Problem;
use PHPUnit\Framework\TestCase;

final class EngineJsonTest extends TestCase {
	public function testOpaqueGraphFieldsAndEmptyObjectTypesSurvive(): void {
		$json = '{"nodes":[],"connections":{},"schema":{"x-fadenstack-provenance":{"opaque":"unchanged"},"properties":{}},"credentials":{"reference":{"id":"owned-id","name":"Native credential"}},"future":{"z":null,"a":[1,2.5,true,"é"]}}';
		$value = EngineJson::decode($json);
		self::assertInstanceOf(\stdClass::class, $value->connections);
		self::assertSame([], $value->nodes);
		$again = EngineJson::decode(EngineJson::encode($value));
		self::assertEquals($value, $again);
		self::assertInstanceOf(\stdClass::class, $again->schema->properties);
		self::assertSame('unchanged', $again->schema->{'x-fadenstack-provenance'}->opaque);
	}
	public function testObjectKeyOrderDoesNotChangeTheDigestButNodeOrderDoes(): void {
		$a = EngineJson::encode(EngineJson::decode('{"z":{},"nodes":["first","second"],"a":1}'));
		$b = EngineJson::encode(EngineJson::decode('{"a":1,"nodes":["first","second"],"z":{}}'));
		self::assertSame($a, $b);
		self::assertNotSame($a, EngineJson::encode(EngineJson::decode('{"a":1,"nodes":["second","first"],"z":{}}')));
	}
	public function testWideGraphsAndDeepGraphsAreBoundedIndependentlyOfBytes(): void {
		foreach ([array_fill(0, 12001, 0), array_fill(0, 300, array_fill(0, 50, 0))] as $wide) {
			try {
				EngineJson::encode($wide);
				self::fail('Unbounded graph breadth.');
			} catch (Problem $error) {
				self::assertSame(413, $error->status);
			}
		}
		$deep = 0;
		for ($i = 0; $i < 25; $i++) {
			$deep = [$deep];
		}
		$this->expectException(Problem::class);
		EngineJson::encode($deep);
	}
	public function testByteBoundsAndNonJsonObjectsFailClosed(): void {
		foreach ([str_repeat('x', EngineJson::MAX_BYTES + 1), new \DateTimeImmutable(), INF] as $value) {
			try {
				EngineJson::encode($value);
				self::fail('Accepted an unsupported graph value.');
			} catch (Problem $error) {
				self::assertContains($error->status, [400, 413]);
			}
		}
	}
}
