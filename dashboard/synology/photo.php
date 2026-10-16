<?php
declare(strict_types=1);

require dirname(__DIR__) . '/server.php';

try {
    $root = dirname(__DIR__);
    $config = HomeHQ\config($root);
    HomeHQ\guard($config);
    HomeHQ\thumbnail($root, $config, $_GET['id'] ?? '');
} catch (Throwable $e) {
    HomeHQ\failure($e);
}
