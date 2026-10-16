<?php
declare(strict_types=1);

require dirname(__DIR__) . '/server.php';

try {
    $root = dirname(__DIR__);
    $config = HomeHQ\config($root);
    HomeHQ\guard($config, true);
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $result = HomeHQ\dashboard($root, $config);
    } elseif ($method === 'POST') {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            throw new HomeHQ\Problem('Expected application/json.', 415);
        }
        $body = file_get_contents('php://input', false, null, 0, 262145);
        if ($body === false || strlen($body) > 262144) {
            throw new HomeHQ\Problem('List update exceeds 256 KiB.', 413);
        }
        try {
            $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HomeHQ\Problem('Invalid JSON.', 400);
        }
        if (!is_array($input)) {
            throw new HomeHQ\Problem('Expected a JSON object.', 400);
        }
        $result = HomeHQ\lists($root, $config, $input);
    } else {
        header('Allow: GET, POST');
        throw new HomeHQ\Problem('Method not allowed.', 405);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    HomeHQ\failure($e);
}
