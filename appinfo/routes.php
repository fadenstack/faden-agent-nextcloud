<?php

declare(strict_types=1);
return ['routes' => [
	['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	['name' => 'config#get', 'url' => '/config', 'verb' => 'GET'],
	['name' => 'config#save', 'url' => '/config', 'verb' => 'PUT'],
	['name' => 'files#select', 'url' => '/context', 'verb' => 'POST'],
	['name' => 'files#scope', 'url' => '/scope/resource', 'verb' => 'POST'],
	['name' => 'files#searchPlan', 'url' => '/search/plan', 'verb' => 'POST'],
	['name' => 'files#searchResolve', 'url' => '/search/resolve', 'verb' => 'POST'],
	['name' => 'files#searchCapabilities', 'url' => '/search/capabilities', 'verb' => 'POST'],
	['name' => 'files#searchContent', 'url' => '/search/content', 'verb' => 'POST'],
	['name' => 'files#read', 'url' => '/context/read', 'verb' => 'POST'],
	['name' => 'files#content', 'url' => '/context/content', 'verb' => 'POST'],
	['name' => 'files#listing', 'url' => '/context/list', 'verb' => 'POST'],
	['name' => 'files#propose', 'url' => '/proposals', 'verb' => 'POST'],
	['name' => 'files#approve', 'url' => '/proposals/{id}/approve', 'verb' => 'POST'],
	['name' => 'files#commit', 'url' => '/proposals/{id}/commit', 'verb' => 'POST'],
	['name' => 'files#finishCreate', 'url' => '/creations/finish', 'verb' => 'POST'],
]];
