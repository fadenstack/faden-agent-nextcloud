<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Files;

use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/** Public native query interface; bound discovery before any content is opened. */
final class BoundedTextQuery implements ISearchQuery {
	public function __construct(private IUser $user, private bool $home = false) {}
	public function getSearchOperation() {
		return new class implements ISearchComparison {
			private array $hints = [];
			public function getQueryHint(string $name, $default) { return $this->hints[$name] ?? $default; }
			public function setQueryHint(string $name, $value): void { $this->hints[$name] = $value; }
			public function getType(): string { return self::COMPARE_LIKE; }
			public function getField(): string { return 'mimetype'; }
			public function getExtra(): string { return ''; }
			public function getValue(): string { return 'text/%'; }
		};
	}
	public function getLimit() { return 101; }
	public function getOffset() { return 0; }
	public function getOrder() { return []; }
	public function getUser() { return $this->user; }
	public function limitToHome(): bool { return $this->home; }
	public function getSelectFields(): array { return ['*']; }
}
