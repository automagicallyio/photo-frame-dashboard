import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const source = dirname(fileURLToPath(import.meta.url));
const dashboard = resolve(source, '..');
const output = join(dashboard, 'build', 'synology');
const target = join(output, 'homehq');
const privateDir = join(target, 'private');
if (existsSync(privateDir) && readdirSync(privateDir).some(name => name !== 'config.example.json')) {
  throw new Error('Existing package contains private configuration/data. Move it somewhere safe before repackaging.');
}
mkdirSync(output, { recursive: true });
rmSync(target, { recursive: true, force: true });
mkdirSync(privateDir, { recursive: true });
cpSync(join(dashboard, 'dist'), join(target, 'public'), { recursive: true });
for (const file of ['api.php', 'photo.php']) cpSync(join(source, file), join(target, 'public', file));
cpSync(join(source, 'server.php'), join(target, 'server.php'));
cpSync(join(source, 'index-photos.php'), join(target, 'index-photos.php'));
cpSync(join(source, 'vendor'), join(target, 'vendor'), { recursive: true });
cpSync(join(source, 'config.example.json'), join(privateDir, 'config.example.json'));
cpSync(join(source, 'README.md'), join(target, 'README.md'));

const prefix = `<?php
declare(strict_types=1);
require dirname(__DIR__) . '/server.php';
try {
    if (!in_array(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), ['/', '/index.php'], true)) {
        throw new HomeHQ\\Problem('Page not found.', 404);
    }
    $config = HomeHQ\\config(dirname(__DIR__));
    HomeHQ\\guard($config);
    $bootstrap = HomeHQ\\dashboard(dirname(__DIR__), $config);
} catch (Throwable $e) {
    HomeHQ\\failure($e, false);
}
?>
`;
const data = `<script id="homehq-data" type="application/json"><?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>`;
const html = readFileSync(join(target, 'public', 'index.html'), 'utf8');
writeFileSync(join(target, 'public', 'index.php'), prefix + html.replace('<body>', '<body>\n' + data));
rmSync(join(target, 'public', 'index.html'));
const zip = join(output, 'homehq.zip');
rmSync(zip, { force: true });
execFileSync('zip', ['-qr', zip, 'homehq'], { cwd: output });
console.log('Upload package: dashboard/build/synology/homehq.zip');
