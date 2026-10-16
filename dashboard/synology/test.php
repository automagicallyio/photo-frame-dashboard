<?php
declare(strict_types=1);

require __DIR__ . '/server.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function refuses(callable $run, int $status): void
{
    try {
        $run();
    } catch (HomeHQ\Problem $e) {
        check($e->status === $status, 'Unexpected refusal status: ' . $e->status);
        return;
    }
    throw new RuntimeException('Expected refusal, got success.');
}

$root = sys_get_temp_dir() . '/homehq-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/private', 0700);
mkdir($root . '/photos', 0700);
try {
    $c = json_decode(file_get_contents(__DIR__ . '/config.example.json'), true, 32, JSON_THROW_ON_ERROR);
    file_put_contents($root . '/private/config.json', json_encode($c));
    check(HomeHQ\config($root)['timezone'] === 'America/Toronto', 'Configuration failed.');
    $legacy = $c;
    unset($legacy['photo_index_interval_hours']);
    file_put_contents($root . '/private/config.json', json_encode($legacy));
    check(HomeHQ\config($root)['photo_index_interval_hours'] === 24, 'Missing indexing interval must default to daily.');
    foreach ([24, 168] as $interval) {
        file_put_contents($root . '/private/config.json', json_encode(array_replace($c, ['photo_index_interval_hours' => $interval])));
        check(HomeHQ\config($root)['photo_index_interval_hours'] === $interval, 'Configured indexing interval was lost.');
    }
    foreach ([0, -1, 1.5, '24', null, true] as $interval) {
        file_put_contents($root . '/private/config.json', json_encode(array_replace($c, ['photo_index_interval_hours' => $interval])));
        refuses(fn() => HomeHQ\config($root), 503);
    }
    file_put_contents($root . '/private/config.json', json_encode($c));
    $_SERVER = ['HTTP_HOST' => 'localhost:8088', 'HTTP_X_HOMEHQ' => '1'];
    HomeHQ\guard($c, true);
    $_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
    refuses(fn() => HomeHQ\guard($c, true), 403);
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_HOMEHQ']);
    refuses(fn() => HomeHQ\guard($c, true), 403);
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    refuses(fn() => HomeHQ\guard($c), 403);
    $_SERVER['HTTP_HOST'] = 'localhost:8088';
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    refuses(fn() => HomeHQ\guard($c), 403);

    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//HomeHQ tests//EN',
        'BEGIN:VEVENT', 'UID:weekly', 'DTSTAMP:20260101T000000Z',
        'DTSTART;TZID=America/Toronto:20261025T090000', 'DTEND;TZID=America/Toronto:20261025T100000',
        'RRULE:FREQ=WEEKLY;COUNT=4', 'EXDATE;TZID=America/Toronto:20261108T090000',
        'SUMMARY:Weekly event', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:weekly', 'DTSTAMP:20260101T000000Z',
        'RECURRENCE-ID;TZID=America/Toronto:20261115T090000',
        'DTSTART;TZID=America/Toronto:20261115T110000', 'DTEND;TZID=America/Toronto:20261115T120000',
        'SUMMARY:Moved occurrence', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:allday', 'DTSTAMP:20260101T000000Z',
        'DTSTART;VALUE=DATE:20261030', 'DTEND;VALUE=DATE:20261102', 'SUMMARY:Multi-day event', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:cancelled', 'DTSTAMP:20260101T000000Z',
        'DTSTART:20261030T120000Z', 'DTEND:20261030T130000Z', 'STATUS:CANCELLED', 'SUMMARY:Cancelled', 'END:VEVENT',
        'BEGIN:VEVENT', 'UID:duration', 'DTSTAMP:20260101T000000Z',
        'DTSTART:20261030T140000Z', 'DURATION:PT90M', 'SUMMARY:Duration event', 'END:VEVENT',
        'END:VCALENDAR', ''
    ]);
    $zone = new DateTimeZone('America/Toronto');
    $events = HomeHQ\calendarEvents($ics, ['household'], new DateTimeImmutable('2026-10-20', $zone), new DateTimeImmutable('2026-11-20', $zone), 'test');
    check(count($events) === 5, 'Recurrence, exclusion, moved occurrence, duration, or cancellation failed.');
    $weekly = array_values(array_filter($events, fn($e) => $e['title'] === 'Weekly event'));
    check(count($weekly) === 2, 'Unexpected weekly occurrence count.');
    check((new DateTimeImmutable($weekly[0]['start']))->setTimezone($zone)->format('H:i P') === '09:00 -04:00', 'Pre-DST time changed.');
    check((new DateTimeImmutable($weekly[1]['start']))->setTimezone($zone)->format('H:i P') === '09:00 -05:00', 'Post-DST time changed.');
    $allDay = array_values(array_filter($events, fn($e) => $e['allDay']))[0];
    check($allDay['start'] === '2026-10-30' && $allDay['end'] === '2026-11-02', 'All-day date shifted.');
    $duration = array_values(array_filter($events, fn($e) => $e['title'] === 'Duration event'))[0];
    check(strtotime($duration['end']) - strtotime($duration['start']) === 5400, 'DURATION was lost.');
    $moved = array_values(array_filter($events, fn($e) => $e['title'] === 'Moved occurrence'))[0];
    check((new DateTimeImmutable($moved['start']))->setTimezone($zone)->format('H:i') === '11:00', 'Exception time was lost.');

    file_put_contents($root . '/photos/photo.jpg', 'test fixture');
    file_put_contents($root . '/secret.txt', 'not a photo');
    check(HomeHQ\photoPath($root . '/photos', 'photo.jpg') === realpath($root . '/photos/photo.jpg'), 'Valid photo path refused.');
    refuses(fn() => HomeHQ\photoPath($root . '/photos', '../secret.txt'), 404);
    symlink($root . '/secret.txt', $root . '/photos/link.jpg');
    refuses(fn() => HomeHQ\photoPath($root . '/photos', 'link.jpg'), 404);
    $c['photo_directory'] = $root . '/photos';
    refuses(fn() => HomeHQ\photoIndex($root, $c), 503);
    $image = imagecreatetruecolor(3, 2);
    imagejpeg($image, $root . '/photos/valid.jpg');
    unset($image);
    mkdir($root . '/photos/@eaDir');
    copy($root . '/photos/valid.jpg', $root . '/photos/@eaDir/thumbnail.jpg');
    file_put_contents($root . '/photos/unsupported.heic', 'test fixture');
    mkdir($root . '/outside');
    copy($root . '/photos/valid.jpg', $root . '/outside/hidden.jpg');
    symlink($root . '/outside', $root . '/photos/linked-folder');
    $index = HomeHQ\rebuildPhotoIndex($root, $c);
    check(count($index['photos']) === 1, 'Invalid images, symlinks, or Synology thumbnails were indexed.');
    check(array_values($index['photos'])[0]['relative'] === 'valid.jpg', 'Wrong photo indexed.');
    check($index['unsupported'] === 2, 'Invalid/unsupported images were not counted.');
    check(HomeHQ\photoIndex($root, $c) === $index, 'Saved index did not round-trip.');
    $lock = fopen($root . '/private/photo-index.lock', 'c');
    check(flock($lock, LOCK_EX | LOCK_NB), 'Cannot acquire test index lock.');
    refuses(fn() => HomeHQ\rebuildPhotoIndex($root, $c), 503);
    check(HomeHQ\photoIndex($root, $c) === $index, 'Web reader blocked or lost the previous index during a scan.');
    fclose($lock);
    $missing = array_replace($c, ['photo_directory' => $root . '/missing']);
    refuses(fn() => HomeHQ\rebuildPhotoIndex($root, $missing), 503);
    refuses(fn() => HomeHQ\photoIndex($root, $missing), 503);
    check(HomeHQ\photoIndex($root, $c) === $index, 'Failed scan destroyed the previous index.');
    rename($root . '/photos', $root . '/photos-offline');
    $start = microtime(true);
    check(HomeHQ\photoIndex($root, $c) === $index, 'Cached reader touched the offline source folder.');
    check(microtime(true) - $start < 0.5, 'Reading a saved index should not scan the album.');
    rename($root . '/photos-offline', $root . '/photos');
    mkdir($root . '/sample');
    foreach (['old', 'new'] as $year) {
        mkdir($root . '/sample/' . $year);
        for ($i = 0; $i < 501; $i++) {
            copy($root . '/photos/valid.jpg', $root . '/sample/' . $year . '/' . $i . '.jpg');
        }
    }
    $sample = HomeHQ\rebuildPhotoIndex($root, array_replace($c, ['photo_directory' => $root . '/sample']));
    check(count($sample['photos']) === 1000 && $sample['total'] === 1002 && $sample['limited'], 'Large album sample limit failed.');
    $folders = array_unique(array_map(fn($p) => dirname($p['relative']), $sample['photos']));
    check(count($folders) === 2, 'Sample did not span album folders.');
    check(HomeHQ\condition(73, true) === ['snow', 'Snow'], 'Snow mislabeled.');
    check(HomeHQ\condition(0, false)[0] === 'moon', 'Night icon incorrect.');

    $first = HomeHQ\lists($root, $c);
    check($first === ['chores' => [], 'groceries' => [], 'revision' => 0], 'Lists must start empty.');
    $update = ['revision' => 0, 'chores' => [
        ['id' => 'c1', 'done' => true, 'title' => 'Test chore', 'who' => 'household', 'r' => 'morning']
    ], 'groceries' => [
        ['id' => 'g1', 'done' => false, 'name' => 'Test grocery', 'cat' => 'Other', 'note' => '', 'qty' => 2, 'store' => 'grocer']
    ]];
    $saved = HomeHQ\lists($root, $c, $update);
    check($saved['revision'] === 1 && $saved['chores'][0]['done'], 'List save failed.');
    check(HomeHQ\lists($root, $c) === $saved, 'List state did not persist after reopening database.');
    refuses(fn() => HomeHQ\lists($root, $c, $update), 409);
    $bad = $update;
    $bad['groceries'][0]['qty'] = 0;
    refuses(fn() => HomeHQ\validateLists($bad, $c), 422);
    $bad = $update;
    $bad['chores'][0]['who'] = 'unknown';
    refuses(fn() => HomeHQ\validateLists($bad, $c), 422);
    $bad = $update;
    $bad['groceries'][] = $bad['groceries'][0];
    refuses(fn() => HomeHQ\validateLists($bad, $c), 422);
    $db = HomeHQ\database($root);
    $db->exec("UPDATE lists SET day='2000-01-01'");
    $db = null;
    $tomorrow = HomeHQ\lists($root, $c);
    check($tomorrow['revision'] === 2 && !$tomorrow['chores'][0]['done'], 'Daily chores did not reset.');
    check($tomorrow['groceries'][0]['qty'] === 2, 'Shopping list changed during day rollover.');
    echo "PHP Synology tests passed.\n";
} finally {
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
