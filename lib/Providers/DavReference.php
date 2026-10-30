<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\Service\Problem;

/** Native DAV hrefs must remain in the resolved caller/collection namespace. */
final class DavReference {
	private static function path(string $base, string $href): string {
		$parts = parse_url($href);
		$origin = parse_url($base);
		if ($parts === false || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
			|| (isset($parts['host']) && (($parts['scheme'] ?? '') !== $origin['scheme'] || $parts['host'] !== $origin['host'] || ($parts['port'] ?? null) !== ($origin['port'] ?? null)))) {
			throw new Problem('Foreign native DAV reference.', 503);
		}
		return $parts['path'] ?? '';
	}

	private static function prefix(string $base, string $provider, string $actor): string {
		$root = $provider === 'contacts' ? '/remote.php/dav/addressbooks/users/' : '/remote.php/dav/calendars/';
		return (parse_url($base, PHP_URL_PATH) ?: '') . $root . NativeRequest::segment($actor) . '/';
	}

	public static function collection(string $base, string $provider, string $actor, string $href): ?string {
		$path = self::path($base, $href);
		$prefix = self::prefix($base, $provider, $actor);
		if ($path === $prefix) {
			return null;
		}
		if (!str_starts_with($path, $prefix)) {
			throw new Problem('Foreign native DAV collection.', 503);
		}
		$id = rawurldecode(rtrim(substr($path, strlen($prefix)), '/'));
		NativeRequest::segment($id);
		return $id;
	}

	public static function object(string $base, string $provider, string $actor, string $collection, string $href): string {
		$path = self::path($base, $href);
		$prefix = self::prefix($base, $provider, $actor) . NativeRequest::segment($collection) . '/';
		if (!str_starts_with($path, $prefix)) {
			throw new Problem('Foreign native DAV object.', 503);
		}
		$name = rawurldecode(substr($path, strlen($prefix)));
		NativeRequest::segment($name);
		return $name;
	}
}
