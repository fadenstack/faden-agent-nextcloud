<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\AgentFiles;

use OCA\FadenstackAgent\Providers\StructuredProvider;
use OCA\FadenstackAgent\Service\Problem;
use OCP\IConfig;

/** Native administrator approval lives outside the untrusted definition bytes. */
final class ManifestBindings {
	public function __construct(
		private IConfig $config,
	) {
	}
	public function all(): array {
		try {
			$raw = $this->config->getAppValue('fadenstack_agent', 'agentfile_bindings', '');
			if ($raw === '') {
				return [];
			}
			if (strlen($raw) > 32768) {
				throw new \UnexpectedValueException();
			}
			$value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			if (!is_array($value) || array_diff(array_keys($value), ['version', 'bindings']) || ($value['version'] ?? null) !== 1
				|| !is_array($value['bindings'] ?? null) || !array_is_list($value['bindings']) || count($value['bindings']) > 8) {
				throw new \UnexpectedValueException();
			}
			$rows = [];
			foreach ($value['bindings'] as $binding) {
				if (!is_array($binding) || count($binding) !== 4 || array_diff(array_keys($binding), ['resourceId', 'manifestSha256', 'agent', 'sources'])
					|| !is_string($binding['resourceId'] ?? null) || !preg_match('/^nextcloud:files:[1-9][0-9]{0,14}$/D', $binding['resourceId'])
					|| !is_string($binding['manifestSha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $binding['manifestSha256'])
					|| !is_string($binding['agent'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $binding['agent'])
					|| !is_array($binding['sources'] ?? null) || !array_is_list($binding['sources']) || count($binding['sources']) < 1 || count($binding['sources']) > 5
					|| isset($rows[$binding['resourceId']])) {
					throw new \UnexpectedValueException();
				}
				$refs = [];
				foreach ($binding['sources'] as $source) {
					if (!is_array($source) || count($source) !== 2 || array_diff(array_keys($source), ['reference', 'resourceId'])
						|| !is_string($source['resourceId'] ?? null) || !preg_match('/^nextcloud:files:[1-9][0-9]{0,14}$/D', $source['resourceId'])) {
						throw new \UnexpectedValueException();
					}
					$reference = ManifestParser::reference($source['reference'] ?? null);
					if (in_array($reference, $refs, true)) {
						throw new \UnexpectedValueException();
					}
					$refs[] = $reference;
				}
				$rows[$binding['resourceId']] = [...$binding, 'stamp' => StructuredProvider::digest($binding)];
			}
			return $rows;
		} catch (\Throwable) {
			throw new Problem('The administrator agent-file binding configuration is invalid.', 503);
		}
	}
}
