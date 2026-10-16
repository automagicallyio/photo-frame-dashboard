# Private Home HQ on Synology Web Station

This is an opt-in live-data version of the dashboard. The normal Vite/Cloudflare demo is
unchanged. Requires PHP **8.3 or newer** with `curl`, `gd`, `pdo_sqlite`, `dom`, `xmlreader`,
`xmlwriter`, and `mbstring` (the Composer dependencies check their PHP requirements).
`exif` is optional but recommended for JPEG orientation. Use a PHP memory limit of at least
256 MB, disable `display_errors`, and enable `log_errors`.

This is a **trusted home-LAN application, not an authenticated public website**. Anyone who
can reach it on your LAN can view the displayed calendar/photos and change chores/shopping.
Do not forward its port, publish it through QuickConnect/reverse proxies, or expose it to guests.
Restrict the portal to trusted LAN clients using the DSM firewall. An allowed-host list and
same-origin write protection do not replace authentication or firewall rules.

## Build an uploadable package

On the development computer, install PHP 8.3+ and Composer, then:

```sh
cd dashboard
npm ci
npm run test:synology
npm run package:synology
```

The result is `dashboard/build/synology/homehq.zip`. It includes the built page, locked PHP
dependencies, and an example configuration, **not your account information**. Generated files,
private configuration, and dependencies are git-ignored. No Node.js, npm, Composer, SSH, or
Microsoft app registration is needed on the NAS.

## Deploy using File Station

1. In DSM **Control Panel → Shared Folder**, create a dedicated `homehq` shared folder.
   **It must be outside every existing Web Station document root. Do not put the whole package
   in `web`, `web_packages`, an existing public site, or a publicly served home directory.**
   This is what keeps private calendar links, the database, cache, and source files off the web.
2. Upload and extract the archive using File Station. Arrange the contents so the dedicated
   shared folder directly contains `public`, `private`, `vendor`, and `server.php`.
   The filesystem path may be `/volume1/homehq`, but confirm your actual volume.
3. In Package Center, install PHP 8.3+ if necessary. In Web Station, create a PHP profile
   with the extensions/settings above. Create a PHP-backed web service with document root
   **`/volume1/homehq/public` only**, using that profile, and give it a LAN portal on an unused
   port such as **8088**. Ensure `index.php` is an index document.
4. Give Web Station's service account/group (usually `http`) read/traverse access to the
   application and read/write access **only to `private`**. Do not make the public code writable.
   Do not grant access to all of `homes`. Check the actual service identity for your PHP profile.
5. Copy `private/config.example.json` to `private/config.json` and edit it using DSM's Text
   Editor package. Keep both files outside the document root. Add the exact hostname/port
   you will use to `allowed_hosts`, for example `synology:8088` and your NAS's LAN IP with
   `:8088`. Confirm the NAS has a fixed DHCP reservation.
6. Open `http://synology:8088/` from a trusted LAN computer. An unconfigured calendar or album
   produces an explicit notice; no fake events, weather, photos, or home-security status appear.
   Request `/private/config.json` and `/server.php` through this portal: both **must return 404**.
   Also check that no other/default portal exposes the parent `homehq` shared folder.

The default Web Station welcome page can remain unchanged on port 80.
If you use HTTPS, use a certificate trusted by the frame; do not disable certificate validation.
Without trusted HTTPS, use HTTP **only on your trusted LAN**. Calendar/photo content is not
encrypted between the NAS and frame over HTTP; never use it on untrusted Wi-Fi.

## Google Calendar (read-only)

On a computer, open Google Calendar → Settings → select a calendar → **Integrate calendar** →
**Secret address in iCal format**. Put that link in the corresponding `calendars[].url` in
the NAS's private configuration. **Treat it like a password. Do not paste it in chat, git,
a public site, or a browser-side JavaScript file.** There is no need to make the calendar public.
Some managed Google Workspace accounts do not provide secret iCal links; ask the administrator
or use a different integration rather than publishing the calendar.

Create one entry per calendar; `people` contains the configured person IDs associated with it.
The initial `Household` person is only a label, not an invented family member. Configure
one to five display names and colors locally. They are visible on the LAN dashboard.

Feeds refresh every five minutes. The calendar library expands recurring events, exceptions,
and time zones. The visible data range is 45 days back to 135 days ahead, with at most 5,000
recurrence iterations per event. The calendar is read-only: add/edit events in Google Calendar.
Private feed URLs are never returned to the browser. With multiple feeds, a failed feed makes
the calendar unavailable rather than silently showing an incomplete family schedule.

## OneDrive photos already synced to the NAS

No cloud login or OneDrive sharing link is needed. `photo_directory` must be a NAS filesystem
path, **not an `smb://` URL**. In File Station, inspect the folder's properties to find its actual
volume/path; do not assume `/volume1`. The service only reads source photos, never deletes or
modifies them, and stores resized JPEG thumbnails in `private/cache`.

Prefer a dedicated read-only shared album folder, with OneDrive/Cloud Sync downloading only
that album into it. You can also copy a selection there initially. If using an existing folder
inside `homes`, grant only the necessary traversal and read access for that album, not read
access to the whole home directory. If narrow permissions are not practical, use the dedicated
album folder instead.

JPEG, PNG and WebP are supported, up to 20 megapixels each. HEIC/HEIF/AVIF, videos, and larger
images are not converted; export resized JPEG copies into the dedicated album if needed.
Album scanning runs in a **scheduled CLI task**, never in a web request. Until its first run
finishes the dashboard shows an explicit "Photo index not ready" notice; calendar, weather and
lists still work. Later scans replace the index atomically, so the frame keeps showing the
previous sample while a scan runs. A failed scan preserves that sample; an index older than
two hours produces a warning on the dashboard.

In DSM **Control Panel → Task Scheduler**, create a scheduled **User-defined script** task:

1. Select an account with read/traverse access only to the album and application, and write
   access to `private`. Web Station also needs read access to the album to make thumbnails.
   Do not run as root or grant broad access to `homes`. Ensure both accounts can read/write
   files created in `private` by the other; use narrow inherited DSM ACLs.
2. Schedule every 30 minutes. Enable task output/error notifications so failures are visible.
3. Run the installed PHP 8.3+ CLI binary with the indexing script, for example:

   ```sh
   /usr/local/bin/php83 /volume1/homehq/index-photos.php
   ```

   Confirm the binary path/version on your NAS (the PHP profile for the website does not choose
   the scheduler's PHP version). Use the same required PHP extensions as the website.
4. Choose **Run** once and check the task result. The CLI removes its execution-time limit;
   indexing a large album can take minutes. Overlapping runs fail explicitly instead of
   scanning twice. A successful run reports sample size, candidate count, unsupported images,
   and whether a limit was reached.

Each scan considers up to 100,000 entries, then randomly samples up to 1,000 supported photos
across the scanned folders. A new sample is drawn on each run. The dashboard API's `albumStatus`
also reports counts and the last successful scan time. Synology `@eaDir` thumbnails and
symlinks are excluded. No capture date, location, or "on this day" story is inferred from
filenames or modified timestamps. The task never modifies source photos.

## Weather, chores, and shopping

The example uses Oakville, Ontario, `America/Toronto`, Celsius, and the public Open-Meteo
forecast API (no key). The provider receives the configured city-level coordinates, not
calendar/photo data. Forecasts refresh every 15 minutes; the page refreshes data every minute.
Keep the frame's automatic date/time and Eastern time zone enabled.

Shopping and daily chores start empty. Use **Shopping** to add/check items and **Chores →
Add chore** for recurring daily chores. Delete a chore with its × button. Chore completion
resets at midnight in the configured time zone; shopping survives day changes. Both are stored
in `private/lists.sqlite`, shared between LAN clients, and survive browser/frame/NAS restarts.
Concurrent edits produce a conflict notice instead of overwriting the other client's changes.
If saving fails, the page shows **SAVE NOT CONFIRMED**; reload only when ready to discard unsaved edits.

Back up `private/config.json` and `private/lists.sqlite` securely on the NAS. Thumbnails/cache
are disposable. When updating, replace `public`, `server.php`, `index-photos.php`, and `vendor`, not your `private`
folder. Stop editing on clients during updates; refresh them afterward.

## Point the frame at it (owner-approved write)

First, check the site from the frame's Wi-Fi and confirm the correct calendar and photos.
Then, with owner approval, save the NAS URL through USB ADB:

```sh
./scripts/set-kiosk-url.sh http://YOUR-NAS-LAN-IP:8088/
```

Keep USB ADB available until the owner has verified by touch that holding two fingers on the
page for 1.5 seconds opens the kiosk menu and reaches Android Settings → Apps.
Enable the optional Home HQ accessibility navigation service if the frame has no Back/Home
buttons (see the kiosk README). Do not expose ADB to the internet or enable Wi-Fi ADB here.

## Local checks

`npm run test:synology` exercises recurrence/DST/exception parsing, photo traversal confinement,
offline index creation/locking, list validation, restart persistence, concurrent editing, and
the live browser adapter.
`npm run package:synology` verifies the Composer platform requirements and builds the upload.
After packaging, `npm run test:synology:http` exercises the generated PHP site over HTTP,
including private-file isolation, API request validation, list persistence, and actual JPEG bytes.
For a local smoke test, copy the generated example to `private/config.json` in the generated
package, set a test photo directory, and run:

```sh
php -S 127.0.0.1:8088 -t build/synology/homehq/public
```

Run `php build/synology/homehq/index-photos.php` separately to populate the photo index.
Do not run indexing in the PHP web server process.

Do not add real private feed URLs to automated fixtures or commit local configuration.
