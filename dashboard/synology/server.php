<?php
declare(strict_types=1);

namespace HomeHQ;

require_once __DIR__ . '/vendor/autoload.php';

final class Problem extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 503)
    {
        parent::__construct($message);
    }
}

function config(string $root): array
{
    $file = $root . '/private/config.json';
    if (!is_file($file)) {
        throw new Problem('Setup required: copy private/config.example.json to private/config.json. See the Synology guide.');
    }
    $c = json_decode(readContents($file), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($c) || empty($c['allowed_hosts']) || empty($c['people']) || count($c['people']) > 5) {
        throw new Problem('Configuration requires allowed_hosts and one to five people.');
    }
    $c += ['photo_index_interval_hours' => 24];
    if (!is_int($c['photo_index_interval_hours']) || $c['photo_index_interval_hours'] < 1) {
        throw new Problem('photo_index_interval_hours must be a positive whole number in private/config.json.');
    }
    new \DateTimeZone($c['timezone'] ?? '');
    $ids = [];
    foreach ($c['people'] as $person) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $person['id'] ?? '')
            || !is_string($person['name'] ?? null) || $person['name'] === '' || strlen($person['name']) > 80
            || !preg_match('/^#[0-9a-f]{6}$/iD', $person['color'] ?? '')
            || in_array($person['id'], array_merge($ids, ['any', 'family']), true)) {
            throw new Problem('Invalid or duplicate person in private/config.json.');
        }
        $ids[] = $person['id'];
    }
    foreach ($c['calendars'] ?? [] as $calendar) {
        if (empty($calendar['people']) || array_diff($calendar['people'], $ids)) {
            throw new Problem('Each calendar must refer to configured people.');
        }
    }
    $w = $c['weather'] ?? [];
    if (!is_numeric($w['latitude'] ?? null) || abs((float)$w['latitude']) > 90
        || !is_numeric($w['longitude'] ?? null) || abs((float)$w['longitude']) > 180) {
        throw new Problem('Invalid weather coordinates in private/config.json.');
    }
    return $c;
}

function guard(array $c, bool $api = false): void
{
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if (!in_array($host, array_map('strtolower', $c['allowed_hosts']), true)) {
        throw new Problem('Host not allowed. Add the exact LAN hostname and port to allowed_hosts.', 403);
    }
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site === 'cross-site' || ($api && ($_SERVER['HTTP_X_HOMEHQ'] ?? '') !== '1')) {
        throw new Problem('Only same-origin dashboard requests are allowed.', 403);
    }
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $origin = parse_url($_SERVER['HTTP_ORIGIN']);
        $expected = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $originHost = strtolower(($origin['host'] ?? '') . (isset($origin['port']) ? ':' . $origin['port'] : ''));
        if (($origin['scheme'] ?? '') !== $expected || $originHost !== $host) {
            throw new Problem('Cross-origin request refused.', 403);
        }
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: frame-ancestors 'self'");
}

function readContents(string $file): string
{
    $data = file_get_contents($file);
    if ($data === false) {
        throw new Problem('Cannot read a required NAS file. Check the PHP profile and folder permissions.');
    }
    return $data;
}

function writeFile(string $file, string $data): void
{
    $tmp = tempnam(dirname($file), '.homehq-');
    if ($tmp === false) {
        throw new Problem('Cannot create a private data file. Check NAS permissions.');
    }
    try {
        if (file_put_contents($tmp, $data) !== strlen($data) || !rename($tmp, $file)) {
            throw new Problem('Cannot save private data. Check NAS disk space and permissions.');
        }
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
}

function cached(string $root, string $key, int $seconds, callable $load): mixed
{
    $dir = $root . '/private/cache';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new Problem('Cannot create the private cache directory.');
    }
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    if (is_file($file) && filemtime($file) > time() - $seconds) {
        return json_decode(readContents($file), true, 512, JSON_THROW_ON_ERROR);
    }
    $data = $load();
    writeFile($file, json_encode($data, JSON_THROW_ON_ERROR));
    return $data;
}

function download(string $url, int $limit = 10000000): string
{
    $h = curl_init($url);
    if ($h === false) {
        throw new Problem('Cannot initialize the HTTPS client.');
    }
    $body = '';
    curl_setopt_array($h, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'HomeHQ/1.0 (private home dashboard)',
        CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }
    ]);
    try {
        if (!curl_exec($h) || curl_getinfo($h, CURLINFO_HTTP_CODE) !== 200) {
            // Never include the URL or curl error: a calendar URL contains a credential.
            throw new Problem('Upstream HTTPS request failed. Check NAS connectivity, certificates, and the private feed URL.');
        }
    } finally {
        unset($h);
    }
    return $body;
}

function calendarEvents(string $text, array $people, \DateTimeImmutable $start, \DateTimeImmutable $end, string $source): array
{
    $cal = \Sabre\VObject\Reader::read($text);
    if (!$cal instanceof \Sabre\VObject\Component\VCalendar) {
        throw new Problem('The calendar response is not an iCalendar calendar.');
    }
    try {
        \Sabre\VObject\Settings::$maxRecurrences = 5000;
        $expanded = $cal->expand($start, $end, $start->getTimezone());
        $events = [];
        foreach ($expanded->select('VEVENT') as $event) {
            if ((string)$event->STATUS === 'CANCELLED' || !isset($event->DTSTART)) {
                continue;
            }
            $a = $event->DTSTART->getDateTime();
            $b = isset($event->DTEND) ? $event->DTEND->getDateTime()
                : (isset($event->DURATION) ? $a->add(\Sabre\VObject\DateTimeParser::parseDuration((string)$event->DURATION))
                    : $a->modify($event->DTSTART->hasTime() ? '+1 minute' : '+1 day'));
            if ($a >= $end || $b <= $start) {
                continue;
            }
            $allDay = !$event->DTSTART->hasTime();
            $events[] = [
                'id' => hash('sha256', $source . (string)$event->UID . $a->format('c')),
                'title' => (string)$event->SUMMARY,
                'start' => $allDay ? $a->format('Y-m-d') : $a->format('c'),
                'end' => $allDay ? $b->format('Y-m-d') : $b->format('c'),
                'allDay' => $allDay,
                'people' => $people,
                'loc' => (string)$event->LOCATION,
                'notes' => (string)$event->DESCRIPTION
            ];
            if (count($events) > 5000) {
                throw new Problem('Calendar exceeds 5,000 occurrences in the displayed range.');
            }
        }
        return $events;
    } finally {
        if (isset($expanded)) {
            $expanded->destroy();
        }
        $cal->destroy();
    }
}

function calendars(string $root, array $c): array
{
    if (empty($c['calendars'])) {
        throw new Problem('No Google calendars configured yet.');
    }
    $zone = new \DateTimeZone($c['timezone']);
    $start = (new \DateTimeImmutable('today', $zone))->modify('-45 days');
    $end = $start->modify('+180 days');
    return cached($root, 'calendar:' . json_encode($c['calendars']) . $start->format('Y-m-d'), 300, function () use ($c, $start, $end) {
        $events = [];
        foreach ($c['calendars'] as $i => $calendar) {
            $url = $calendar['url'] ?? '';
            if (!preg_match('~^https://calendar\.google\.com/calendar/ical/[^?#\s]+/basic\.ics$~D', $url)) {
                throw new Problem('Configure each Google calendar with its Secret address in iCal format.');
            }
            try {
                $events = array_merge($events, calendarEvents(download($url), $calendar['people'], $start, $end, (string)$i));
            } catch (\Throwable $e) {
                // Parser errors can quote private calendar contents.
                error_log('HomeHQ: calendar source ' . ($i + 1) . ' failed (' . get_class($e) . ').');
                throw new Problem('Google calendar ' . ($i + 1) . ' could not be loaded. Check its private feed and the NAS PHP log.');
            }
        }
        usort($events, fn($a, $b) => strcmp($a['start'], $b['start']));
        return ['events' => $events, 'from' => $start->format('Y-m-d'), 'until' => $end->format('Y-m-d')];
    });
}

function weather(string $root, array $c): array
{
    $date = (new \DateTimeImmutable('now', new \DateTimeZone($c['timezone'])))->format('Y-m-d');
    return cached($root, 'weather:' . json_encode([$c['weather'], $c['timezone'], $date]), 900, function () use ($c) {
        $query = http_build_query([
            'latitude' => $c['weather']['latitude'], 'longitude' => $c['weather']['longitude'],
            'timezone' => $c['timezone'], 'timeformat' => 'unixtime', 'forecast_days' => 2,
            'hourly' => 'temperature_2m,precipitation_probability,weather_code,is_day',
            'daily' => 'temperature_2m_max,temperature_2m_min'
        ]);
        $w = json_decode(download('https://api.open-meteo.com/v1/forecast?' . $query, 1000000), true, 64, JSON_THROW_ON_ERROR);
        $hours = $w['hourly'] ?? [];
        $out = [];
        foreach ($hours['time'] ?? [] as $i => $time) {
            $temp = $hours['temperature_2m'][$i] ?? null;
            $p = $hours['precipitation_probability'][$i] ?? null;
            $code = $hours['weather_code'][$i] ?? null;
            if (!is_numeric($temp) || !is_numeric($p) || !is_numeric($code)) {
                throw new Problem('Weather provider returned incomplete hourly data.');
            }
            [$icon, $label] = condition((int)$code, (bool)($hours['is_day'][$i] ?? true));
            $out[] = ['time' => $time * 1000, 't' => (int)round($temp), 'p' => $p, 'c' => $icon, 'label' => $label];
        }
        if (count($out) < 24 || !is_numeric($w['daily']['temperature_2m_max'][0] ?? null)
            || !is_numeric($w['daily']['temperature_2m_min'][0] ?? null)) {
            throw new Problem('Weather provider returned incomplete forecast data.');
        }
        return ['hours' => $out, 'hi' => (int)round($w['daily']['temperature_2m_max'][0]),
            'lo' => (int)round($w['daily']['temperature_2m_min'][0]), 'name' => $c['weather']['name'],
            'date' => (new \DateTimeImmutable('now', new \DateTimeZone($c['timezone'])))->format('Y-m-d')];
    });
}

function condition(int $code, bool $day): array
{
    return match (true) {
        $code === 0 => [$day ? 'sun' : 'moon', 'Clear'],
        $code <= 2 => ['partly', 'Partly cloudy'],
        $code === 3 => ['cloud', 'Overcast'],
        $code <= 48 => ['cloud', 'Fog'],
        in_array($code, [71, 73, 75, 77, 85, 86], true) => ['snow', 'Snow'],
        $code >= 95 => ['rain', 'Thunderstorms'],
        default => ['rain', 'Rain']
    };
}

function photoPath(string $base, string $relative): string
{
    $root = realpath($base);
    $path = realpath($base . '/' . $relative);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        throw new Problem('Photo not found.', 404);
    }
    return $path;
}

function photoIndex(string $root, array $c): array
{
    $file = $root . '/private/photo-index.json';
    if (!is_file($file)) {
        throw new Problem('Photo index not ready. Run the NAS index-photos.php scheduled task; other dashboard features remain available.');
    }
    $index = json_decode(readContents($file), true, 512, JSON_THROW_ON_ERROR);
    if (($index['source'] ?? '') !== hash('sha256', $c['photo_directory'] ?? '')) {
        throw new Problem('Photo folder changed. Run the NAS index-photos.php scheduled task to rebuild its index.');
    }
    return $index;
}

function rebuildPhotoIndex(string $root, array $c): array
{
    $lock = fopen($root . '/private/photo-index.lock', 'c');
    if ($lock === false) {
        throw new Problem('Cannot open the photo index lock. Check private folder permissions.');
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new Problem('Photo indexing is already running; wait for that task to finish.');
        }
        $base = realpath($c['photo_directory'] ?? '');
        if ($base === false || !is_dir($base) || !is_readable($base)) {
            throw new Problem('Photo folder unavailable. Configure a local NAS path with read-only access for the indexing task and Web Station service.');
        }
        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            fn($file) => !$file->isLink() && $file->getFilename() !== '@eaDir'
        );
        $files = new \RecursiveIteratorIterator($directories);
        $candidates = [];
        $unsupported = 0;
        $scanned = 0;
        $limited = false;
        foreach ($files as $file) {
            if (++$scanned > 100000) {
                $limited = true;
                break;
            }
            if (!$file->isFile() || $file->isLink() || str_contains($file->getPathname(), '/@eaDir/')) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                if (in_array(strtolower($file->getExtension()), ['heic', 'heif', 'avif', 'gif', 'tiff', 'bmp'], true)) {
                    $unsupported++;
                }
                continue;
            }
            $candidates[] = $file->getPathname();
        }
        // Sample across folders/years, not just the first files in directory order.
        shuffle($candidates);
        $photos = [];
        foreach ($candidates as $path) {
            if (count($photos) >= 1000) {
                $limited = true;
                break;
            }
            $file = new \SplFileInfo($path);
            $size = @getimagesize($path);
            if ($size === false || $size[0] * $size[1] > 20000000) {
                $unsupported++;
                continue;
            }
            $relative = substr($file->getPathname(), strlen($base) + 1);
            $id = hash('sha256', $relative . ':' . $file->getMTime() . ':' . $file->getSize());
            $width = $size[0];
            $height = $size[1];
            if ($size[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($file->getPathname(), 'IFD0');
                if (in_array((int)($exif['Orientation'] ?? 1), [5, 6, 7, 8], true)) {
                    [$width, $height] = [$height, $width];
                }
            }
            $photos[$id] = ['relative' => $relative, 'o' => $width > $height ? 'l' : 'p'];
        }
        $index = ['source' => hash('sha256', $c['photo_directory'] ?? ''), 'updated' => time(),
            'photos' => $photos, 'unsupported' => $unsupported, 'limited' => $limited, 'total' => count($candidates)];
        writeFile($root . '/private/photo-index.json', json_encode($index, JSON_THROW_ON_ERROR));
        return $index;
    } finally {
        fclose($lock);
    }
}

function thumbnail(string $root, array $c, string $id): never
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
        throw new Problem('Photo not found.', 404);
    }
    $index = photoIndex($root, $c);
    $photo = $index['photos'][$id] ?? null;
    if ($photo === null) {
        throw new Problem('Photo not found. The photo index may have refreshed.', 404);
    }
    $path = photoPath($c['photo_directory'], $photo['relative']);
    $cache = $root . '/private/cache/' . $id . '.jpg';
    if (!is_file($cache)) {
        $size = getimagesize($path);
        if ($size === false || $size[0] * $size[1] > 20000000) {
            throw new Problem('Photo is invalid or exceeds 20 megapixels.', 422);
        }
        $image = match ($size[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => false
        };
        if ($image === false) {
            throw new Problem('Photo could not be decoded.', 422);
        }
        $scaled = null;
        try {
            if ($size[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = exif_read_data($path, 'IFD0');
                $orientation = (int)($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
                if ($angle !== 0) {
                    $rotated = imagerotate($image, $angle, 0);
                    if ($rotated === false) {
                        throw new Problem('Photo orientation could not be applied.');
                    }
                    $image = $rotated;
                }
            }
            $ratio = min(1, 1600 / imagesx($image), 1200 / imagesy($image));
            $scaled = imagescale($image, max(1, (int)(imagesx($image) * $ratio)), max(1, (int)(imagesy($image) * $ratio)));
            if ($scaled === false) {
                throw new Problem('Photo could not be resized.');
            }
            ob_start();
            try {
                if (!imagejpeg($scaled, null, 82)) {
                    throw new Problem('Photo thumbnail could not be encoded.');
                }
                $jpeg = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            writeFile($cache, $jpeg);
        } finally {
            unset($image, $scaled);
        }
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=1800');
    if (readfile($cache) === false) {
        throw new Problem('Cannot read the cached thumbnail.');
    }
    exit;
}

function database(string $root): \PDO
{
    $db = new \PDO('sqlite:' . $root . '/private/lists.sqlite', null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS lists (id INTEGER PRIMARY KEY CHECK(id=1), revision INTEGER NOT NULL, day TEXT NOT NULL, data TEXT NOT NULL)');
    return $db;
}

function lists(string $root, array $c, ?array $update = null): array
{
    $db = database($root);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $day = (new \DateTimeImmutable('now', new \DateTimeZone($c['timezone'])))->format('Y-m-d');
        $row = $db->query('SELECT revision, day, data FROM lists WHERE id=1')->fetch();
        $revision = $row ? (int)$row['revision'] : 0;
        $data = $row ? json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR) : ['chores' => [], 'groceries' => []];
        if ($row && $row['day'] !== $day) {
            foreach ($data['chores'] as &$chore) {
                $chore['done'] = false;
                unset($chore['doneBy']);
            }
            unset($chore);
            $revision++;
        }
        if ($update !== null) {
            if (($update['revision'] ?? null) !== $revision) {
                throw new Problem('Lists changed on another device or a new day began. Reload before editing again.', 409);
            }
            $data = validateLists($update, $c);
            $revision++;
        }
        $stmt = $db->prepare('INSERT INTO lists VALUES(1,?,?,?) ON CONFLICT(id) DO UPDATE SET revision=excluded.revision, day=excluded.day, data=excluded.data');
        $stmt->execute([$revision, $day, json_encode($data, JSON_THROW_ON_ERROR)]);
        $db->exec('COMMIT');
        return array_merge($data, ['revision' => $revision]);
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function validateLists(array $input, array $c): array
{
    $out = [];
    $people = array_merge(array_column($c['people'], 'id'), ['any']);
    foreach (['groceries', 'chores'] as $type) {
        $items = $input[$type] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > 500) {
            throw new Problem('Lists must contain at most 500 items each.', 422);
        }
        $ids = [];
        $out[$type] = [];
        foreach ($items as $item) {
            if (!is_array($item) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $item['id'] ?? '')
                || in_array($item['id'], $ids, true) || !is_bool($item['done'] ?? null)) {
                throw new Problem('Invalid or duplicate list item.', 422);
            }
            $ids[] = $item['id'];
            $entry = ['id' => $item['id'], 'done' => $item['done']];
            foreach ($type === 'groceries' ? ['name', 'cat', 'note'] : ['title'] as $field) {
                if (!is_string($item[$field] ?? null) || strlen($item[$field]) > 240
                    || ($field !== 'note' && trim($item[$field]) === '')) {
                    throw new Problem('List item text is missing or too long (240 bytes maximum).', 422);
                }
                $entry[$field] = $item[$field];
            }
            if ($type === 'groceries') {
                if (!is_int($item['qty'] ?? null) || $item['qty'] < 1 || $item['qty'] > 999
                    || !in_array($item['store'] ?? null, ['grocer', 'costco'], true)
                    || !in_array($item['cat'], ['Produce', 'Bakery', 'Meat & Fish', 'Dairy & Eggs', 'Pantry', 'Frozen', 'Snacks', 'Household', 'Other'], true)) {
                    throw new Problem('Invalid grocery quantity, store, or category.', 422);
                }
                $entry += ['qty' => $item['qty'], 'store' => $item['store']];
            } else {
                if (!in_array($item['who'] ?? null, $people, true) || !in_array($item['r'] ?? null, ['morning', 'after', 'evening'], true)) {
                    throw new Problem('Invalid chore person or routine.', 422);
                }
                $entry += ['who' => $item['who'], 'r' => $item['r'], 'emoji' => '✓', 'stars' => 0];
                if (isset($item['doneBy'])) {
                    if (!in_array($item['doneBy'], $people, true)) {
                        throw new Problem('Invalid chore completion person.', 422);
                    }
                    $entry['doneBy'] = $item['doneBy'];
                }
            }
            $out[$type][] = $entry;
        }
    }
    return $out;
}

function dashboard(string $root, array $c): array
{
    $out = ['people' => $c['people'], 'errors' => [], 'updated' => date(DATE_ATOM)];
    foreach (['calendar' => fn() => calendars($root, $c), 'weather' => fn() => weather($root, $c),
        'album' => fn() => photoIndex($root, $c)] as $name => $load) {
        try {
            $out[$name] = $load();
        } catch (\Throwable $e) {
            error_log('HomeHQ: ' . $name . ' failed (' . get_class($e) . ').');
            $out[$name] = null;
            $out['errors'][] = ucfirst($name) . ': ' . ($e instanceof Problem ? $e->getMessage() : 'Unavailable; check the NAS PHP log.');
        }
    }
    if ($out['album'] !== null) {
        $album = $out['album'];
        $out['photos'] = [];
        foreach ($album['photos'] as $id => $photo) {
            $out['photos'][] = ['seed' => $id, 'url' => 'photo.php?id=' . $id, 'o' => $photo['o'],
                'place' => '', 'date' => '', 'dateKind' => 'unknown', 'tag' => 'album', 'from' => 'family'];
        }
        if (!$out['photos']) {
            $out['errors'][] = 'Album: no supported photos found.';
        }
        if ($album['updated'] < time() - $c['photo_index_interval_hours'] * 2 * 3600) {
            $out['errors'][] = 'Album: using an older photo index. Check the NAS scheduled task and its output.';
        }
        $out['albumStatus'] = ['shown' => count($out['photos']), 'supported' => $album['total'] ?? count($out['photos']),
            'unsupported' => $album['unsupported'], 'limited' => $album['limited'], 'updated' => $album['updated']];
        unset($out['album']);
    }
    $out['lists'] = lists($root, $c);
    return $out;
}

function failure(\Throwable $e, bool $json = true): never
{
    $status = $e instanceof Problem ? $e->status : 503;
    $message = $e instanceof Problem ? $e->getMessage() : 'NAS backend unavailable. Check the PHP extensions, private folder permissions, and NAS PHP log.';
    error_log('HomeHQ: request failed (' . get_class($e) . '); HTTP ' . $status);
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
    echo $json ? json_encode(['error' => $message], JSON_THROW_ON_ERROR)
        : '<!doctype html><title>Home HQ setup</title><h1>Home HQ is not ready</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>See the Synology deployment guide.</p>';
    exit;
}
