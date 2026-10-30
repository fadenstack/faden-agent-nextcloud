<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Providers;

use OCA\FadenstackAgent\History\JournalResource;
use OCA\FadenstackAgent\Service\Problem;

/** Fresh native authority, including when the dispatch gate is disabled. No saved credentials. */
final class ProviderAuthority {
	public function __construct(private NativeApiBridge $bridge, private ProviderPolicy $policy) {
	}

	public function target(JournalResource $resource): array {
		$provider = $resource->provider;
		$prefix = $provider === 'contacts' ? 'book:' : 'collection:';
		$kind = match ($provider) { 'calendar' => 'event:', 'tasks' => 'task:', 'contacts' => 'contact:', default => throw new Problem('Unavailable provider action.', 404) };
		if (!str_starts_with($resource->authorityReference, $prefix) || !str_starts_with($resource->reference, $resource->authorityReference . '/' . $kind)) {
			throw new Problem('Unavailable provider action.', 404);
		}
		$collection = substr($resource->authorityReference, strlen($prefix));
		$filename = substr($resource->reference, strlen($resource->authorityReference . '/' . $kind));
		NativeRequest::segment($collection);
		NativeRequest::segment($filename);
		return ['provider' => $provider, 'collectionId' => $collection, 'filename' => $filename];
	}

	public function collection(string $provider, string $collection): array {
		$policy = $this->policy->audit($provider);
		$this->policy->assertDisclosure($policy);
		if (!in_array($collection, $policy['collections'], true)) {
			throw new Problem('The provider collection is no longer in scope.', 403);
		}
		$req = $provider === 'contacts' ? NativeRequest::addressbookCollection($policy['actor'], $collection) : NativeRequest::calendarCollection($policy['actor'], $collection);
		$xpath = DavResponse::document($this->bridge->send($req), $provider === 'contacts' ? 'urn:ietf:params:xml:ns:carddav' : 'urn:ietf:params:xml:ns:caldav');
		$xpath->registerNamespace('c', $provider === 'contacts' ? 'urn:ietf:params:xml:ns:carddav' : 'urn:ietf:params:xml:ns:caldav');
		$responses = $xpath->query('/d:multistatus/d:response');
		if ($responses->length !== 1 || DavReference::collection($this->bridge->base(), $provider, $policy['actor'],
			trim((string)$xpath->evaluate('string(d:href)', $responses->item(0)))) !== $collection) {
			throw new Problem('The native collection authority could not be verified.', 503);
		}
		$type = $provider === 'contacts' ? 'addressbook' : 'calendar';
		if ($xpath->query('/d:multistatus/d:response/d:propstat/d:prop/d:resourcetype/c:' . $type)->length !== 1
			|| !$xpath->query('/d:multistatus/d:response/d:propstat/d:prop/d:current-user-privilege-set/d:privilege/d:read | /d:multistatus/d:response/d:propstat/d:prop/d:current-user-privilege-set/d:privilege/d:all')->length) {
			throw new Problem('Native collection read access could not be verified.', 403);
		}
		return [...$policy, 'privileges' => DavResponse::privileges($xpath, $xpath->query('/d:multistatus/d:response')->item(0))];
	}

	public function mutation(string $provider, string $collection, string $operation): void {
		$policy = $this->collection($provider, $collection);
		if (!in_array($operation, ['create', 'patch', 'delete'], true) || !($policy['privileges'][$operation] ?? false)) {
			throw new Problem('The current native role does not permit this operation.', 403);
		}
	}

	/** Return current bytes internally only; the audit endpoint projects metadata/observations. */
	public function inspect(JournalResource $resource): array {
		$target = $this->target($resource);
		$policy = $this->collection($target['provider'], $target['collectionId']);
		$req = $target['provider'] === 'contacts'
			? NativeRequest::addressbookObject($policy['actor'], $target['collectionId'], $target['filename'])
			: NativeRequest::calendarObject($policy['actor'], $target['collectionId'], $target['filename']);
		try {
			$current = $this->bridge->send($req);
			if ($current['status'] !== 200) {
				throw new Problem('The native object state could not be verified.', 503);
			}
			$this->policy->assertDisclosure($policy, CalendarClassification::sourceClass($current['body'], $policy['sourceClass']));
			return [...$target, 'present' => true, 'content' => $current['body'], 'etag' => DavResponse::etag($current['etag'])];
		} catch (Problem $error) {
			if ($error->status !== 404) {
				throw $error;
			}
			// A hidden collection must never be mistaken for a verified absent object.
			$this->collection($target['provider'], $target['collectionId']);
			return [...$target, 'present' => false];
		}
	}
}
