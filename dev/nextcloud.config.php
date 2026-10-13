<?php

// Local development only. See the official Nextcloud development environment guide.
$CONFIG = [
	'debug' => true,
	'appstoreenabled' => false,
	'apps_paths' => [
		['path' => '/var/www/html/apps', 'url' => '/apps', 'writable' => false],
		['path' => '/var/www/html/custom_apps', 'url' => '/custom_apps', 'writable' => false],
	],
];
