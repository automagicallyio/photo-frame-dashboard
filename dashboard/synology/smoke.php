<?php
declare(strict_types=1);

require __DIR__ . '/server.php';

function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function request(string $url, array $headers = [], ?string $body = null): array
{
    $h = curl_init($url);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    }
    $data = curl_exec($h);
    return [(int)curl_getinfo($h, CURLINFO_HTTP_CODE), $data, curl_getinfo($h, CURLINFO_CONTENT_TYPE)];
}

$package = dirname(__DIR__) . '/build/synology/homehq';
verify(is_file($package . '/public/index.php'), 'Run npm run package:synology before the HTTP smoke test.');
$root = sys_get_temp_dir() . '/homehq-http-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/public', 0700);
mkdir($root . '/private', 0700);
mkdir($root . '/photos', 0700);
$process = null;
try {
    symlink($package . '/vendor', $root . '/vendor');
    symlink($package . '/server.php', $root . '/server.php');
    copy($package . '/index-photos.php', $root . '/index-photos.php');
    foreach (['index.php', 'api.php', 'photo.php'] as $file) {
        copy($package . '/public/' . $file, $root . '/public/' . $file);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    verify($socket !== false, 'Cannot allocate a test port.');
    $host = stream_socket_get_name($socket, false);
    fclose($socket);
    $c = json_decode(file_get_contents(__DIR__ . '/config.example.json'), true, 32, JSON_THROW_ON_ERROR);
    $c['allowed_hosts'] = [$host];
    $c['photo_directory'] = $root . '/photos';
    $c['calendars'][0]['url'] = 'https://example.invalid/private-test-secret';
    file_put_contents($root . '/private/config.json', json_encode($c, JSON_THROW_ON_ERROR));
    $image = imagecreatetruecolor(1800, 1300);
    imagejpeg($image, $root . '/photos/test.jpg');
    unset($image);
    $today = (new DateTimeImmutable('now', new DateTimeZone($c['timezone'])))->format('Y-m-d');
    HomeHQ\cached($root, 'weather:' . json_encode([$c['weather'], $c['timezone'], $today]), 900, fn() => [
        'hours' => [], 'hi' => 12, 'lo' => 5, 'name' => 'Test weather', 'date' => $today
    ]);
    $process = proc_open([PHP_BINARY, '-S', $host, '-t', $root . '/public'], [
        0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']
    ], $pipes);
    verify(is_resource($process), 'Cannot start the test server.');
    fclose($pipes[0]);
    $url = 'http://' . $host;
    for ($i = 0; $i < 50; $i++) {
        [$status, $html] = request($url . '/');
        if ($status !== 0) {
            break;
        }
        usleep(100000);
    }
    verify($status === 200, 'Generated index failed: ' . $status . "\n" . file_get_contents($root . '/server.log'));
    verify(str_contains($html, 'id="homehq-data"'), 'Live bootstrap missing.');
    verify(!str_contains($html, 'private-test-secret'), 'Private feed URL leaked into the page.');
    foreach (['private/config.json', 'server.php', 'index-photos.php', 'vendor/autoload.php'] as $path) {
        verify(request($url . '/' . $path)[0] === 404, 'Private file is publicly reachable: ' . $path);
    }
    verify(request($url . '/api.php')[0] === 403, 'API accepted a cross-site/simple request.');
    $headers = ['X-HomeHQ: 1', 'Content-Type: application/json'];
    [$status, $body] = request($url . '/api.php', $headers);
    verify($status === 200, 'API read failed.');
    $initial = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    verify(count($initial['errors']) === 2 && str_contains(implode(' ', $initial['errors']), 'Photo index not ready'),
        'Missing index must produce an explicit notice without blocking other features.');
    verify(!is_file($root . '/private/photo-index.json'), 'Web request unexpectedly scanned photos.');
    $indexer = proc_open([PHP_BINARY, $root . '/index-photos.php'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']
    ], $indexPipes);
    verify(is_resource($indexer), 'Cannot start CLI indexing.');
    fclose($indexPipes[0]);
    $output = stream_get_contents($indexPipes[1]);
    $error = stream_get_contents($indexPipes[2]);
    fclose($indexPipes[1]);
    fclose($indexPipes[2]);
    verify(proc_close($indexer) === 0 && str_contains($output, 'Indexed 1 photos'), 'CLI indexing failed: ' . $error);
    [$status, $body] = request($url . '/api.php', $headers);
    $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    verify(!str_contains($body, 'private-test-secret') && !str_contains($body, '/photos/'), 'Private source details leaked into JSON.');
    verify(count($data['photos']) === 1 && count($data['errors']) === 1, 'Photo index or explicit calendar error missing.');
    verify($data['albumStatus']['shown'] === 1 && $data['albumStatus']['updated'] > time() - 60, 'Index status missing.');
    $indexFile = $root . '/private/photo-index.json';
    $index = json_decode(file_get_contents($indexFile), true, 512, JSON_THROW_ON_ERROR);
    $index['updated'] = time() - 25 * 3600;
    file_put_contents($indexFile, json_encode($index, JSON_THROW_ON_ERROR));
    $daily = json_decode(request($url . '/api.php', $headers)[1], true, 64, JSON_THROW_ON_ERROR);
    verify(count($daily['photos']) === 1 && count($daily['errors']) === 1,
        'Daily indexing must allow more than a day between successful scans without a warning.');
    $index['updated'] = time() - 49 * 3600;
    file_put_contents($indexFile, json_encode($index, JSON_THROW_ON_ERROR));
    $stale = json_decode(request($url . '/api.php', $headers)[1], true, 64, JSON_THROW_ON_ERROR);
    verify(count($stale['photos']) === 1 && str_contains(implode(' ', $stale['errors']), 'older photo index'),
        'Stale index must keep serving photos and warn about the scheduler.');
    $c['photo_index_interval_hours'] = 168;
    file_put_contents($root . '/private/config.json', json_encode($c, JSON_THROW_ON_ERROR));
    $index['updated'] = time() - 8 * 24 * 3600;
    file_put_contents($indexFile, json_encode($index, JSON_THROW_ON_ERROR));
    $weekly = json_decode(request($url . '/api.php', $headers)[1], true, 64, JSON_THROW_ON_ERROR);
    verify(count($weekly['photos']) === 1 && count($weekly['errors']) === 1,
        'Weekly indexing must allow more than a week between successful scans without a warning.');
    $index['updated'] = time() - 15 * 24 * 3600;
    file_put_contents($indexFile, json_encode($index, JSON_THROW_ON_ERROR));
    $weeklyStale = json_decode(request($url . '/api.php', $headers)[1], true, 64, JSON_THROW_ON_ERROR);
    verify(count($weeklyStale['photos']) === 1 && str_contains(implode(' ', $weeklyStale['errors']), 'older photo index'),
        'Weekly indexing must warn after two intervals while preserving photos.');
    [$status, $jpeg, $type] = request($url . '/' . $data['photos'][0]['url']);
    verify($status === 200 && $type === 'image/jpeg', 'Thumbnail endpoint failed.');
    verify(str_starts_with($jpeg, "\xff\xd8"), 'Thumbnail body is not JPEG: ' . substr($jpeg, 0, 300) . "\n" . file_get_contents($root . '/server.log'));
    $dimensions = getimagesizefromstring($jpeg);
    verify($dimensions !== false && $dimensions[0] <= 1600 && $dimensions[1] <= 1200, 'Full-sized or invalid photo was served to the frame.');
    verify(request($url . '/photo.php?id=../../private/config.json')[0] === 404, 'Photo path traversal was accepted.');
    $update = json_encode(['revision' => 0, 'chores' => [], 'groceries' => [
        ['id' => 'test', 'done' => false, 'name' => 'Test item', 'cat' => 'Other', 'note' => '', 'qty' => 1, 'store' => 'grocer']
    ]], JSON_THROW_ON_ERROR);
    verify(request($url . '/api.php', array_merge($headers, ['Origin: https://attacker.invalid']), $update)[0] === 403, 'Cross-origin write accepted.');
    verify(request($url . '/api.php', $headers, '{broken')[0] === 400, 'Malformed JSON accepted.');
    verify(request($url . '/api.php', $headers, str_repeat('x', 262145))[0] === 413, 'Oversized request accepted.');
    verify(request($url . '/api.php', $headers, $update)[0] === 200, 'List write failed.');
    verify(request($url . '/api.php', $headers, $update)[0] === 409, 'Conflicting update overwrote state.');
    $persisted = json_decode(request($url . '/api.php', $headers)[1], true, 64, JSON_THROW_ON_ERROR);
    verify($persisted['lists']['groceries'][0]['name'] === 'Test item', 'List update did not survive an independent request.');
    echo "Generated-package HTTP tests passed.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}
