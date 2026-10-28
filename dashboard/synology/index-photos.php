<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/server.php';

try {
    if (!set_time_limit(0)) {
        throw new HomeHQ\Problem('Cannot remove the CLI execution limit for photo indexing.');
    }
    $index = HomeHQ\rebuildPhotoIndex(__DIR__, HomeHQ\config(__DIR__));
    printf("Indexed %d photos from %d candidates; %d unsupported images skipped; limits reached: %s.\n",
        count($index['photos']), $index['total'], $index['unsupported'], $index['limited'] ? 'yes' : 'no');
} catch (Throwable $e) {
    fwrite(STDERR, 'HomeHQ photo indexing failed: '
        . ($e instanceof HomeHQ\Problem ? $e->getMessage() : 'Check NAS photo permissions and the PHP error log.') . "\n");
    error_log('HomeHQ: photo indexing failed (' . get_class($e) . ').');
    exit(1);
}
