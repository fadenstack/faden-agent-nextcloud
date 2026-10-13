<?php

declare(strict_types=1);

$loader = require __DIR__ . '/../vendor/autoload.php';
// The OCP package supplies public API stubs but deliberately no runtime loader.
$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
