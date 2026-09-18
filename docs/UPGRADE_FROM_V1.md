# Upgrading an existing v1.x install to v2

Runbook for a Claude Code session running **on the Mac that has the old install**
(for example the photographer's laptop), with the project owner watching. v1.1.8
(June 2025) to v2.0.2 or later is a major jump: Laravel 11 → 13, Livewire 3 → 4, PHP 8.2 →
8.4, new queue workers, new database columns, and the website (Gallery) API.
The old in-app updater is **not** used for this jump: it would run Composer and
migrations with whatever `php` is first on PATH.

If you are the Claude Code session: follow the phases in order. The rules below
override anything else in this file.

## Rules

1. **Stop and report on any failure or surprise.** Do not improvise a fix, retry
   with different flags, or skip a step. Say what happened and wait.
2. **Never print a secret.** For tokens, passwords, and key files report only
   "set" / "not set" / "file exists". Never `cat .env`; read single keys.
3. **Never delete or discard anything.** No `git reset --hard`, `git clean`,
   `git stash drop`, `migrate:fresh`, `migrate:rollback`, `db:wipe`, `rm -rf`,
   and no pruning of database tables. The photo folders and the archive are never
   touched by this upgrade. **One exception:** `public/build/` is generated
   frontend output. v1 and v2.0.0–v2.0.1 committed it, so an install that has
   ever run `npm run build` shows it as modified. Restoring it with
   `git checkout -- public/build` is allowed; Phase 4 rebuilds it. From v2.0.2 it
   is no longer in the repository, so pulling removes the stale committed copy and
   the build output is never reported as modified again.
4. **Stop at every line marked CHECKPOINT** and wait for the owner to say continue.
5. Use Herd's PHP for every PHP command (`herd php …`, `herd composer …`) so the
   version chosen for this site is the one that runs.
6. Do not upload, import, regenerate, or re-process any photos as a "test".

## Phase 1 — Survey (read-only)

Report all of this in one block:

- `pwd`, `git status -sb`, `git describe --tags --always`, `git log -1 --format="%h %ad %s"`,
  `git remote -v`. List uncommitted or untracked files by name (not contents).
- macOS version; `herd --version`; the PHP versions Herd has installed; `herd php -v`
  run inside this folder; `herd composer --version`; `node -v`; `npm -v`;
  `swift --version`; `redis-cli ping`; first line of `/opt/homebrew/bin/rsync --version`
  (or `/usr/local/bin/rsync`) and of `/usr/bin/rsync --version`.
- From `.env`, the **values** of: `APP_ENV`, `APP_URL`, `DB_CONNECTION`, `DB_DATABASE`,
  `QUEUE_CONNECTION`, `CACHE_STORE`, `FULLSIZE_HOME_DIR`, `ARCHIVE_HOME_DIR`,
  `TRANSPORT_DRIVER`, `SFTP_PORT`, `SFTP_USERNAME`, `SFTP_PROOFSPATH`,
  `SFTP_WEB_IMAGES_PATH`, `SFTP_HIGHRES_IMAGES_PATH`, `FERRARAPHOTO_API_BASE`.
  Only **set / not set** for: `SFTP_HOSTNAME`, `SFTP_PATHTOPRIVATEKEY` (and whether
  that file exists), `FERRARAPHOTO_API_TOKEN`, `APP_KEY`.
- The database file path and size; `herd php artisan migrate:status | tail -12`;
  row counts of `shows`, `show_classes`, `photos`; whether a `configurations` table
  exists and its row count.
- Whether `photos.sha1` exists. If it does, the count from:
  `SELECT COUNT(*) FROM (SELECT sha1 FROM photos WHERE sha1 IS NOT NULL GROUP BY sha1 HAVING COUNT(*) > 1);`
- Whether the `FULLSIZE_HOME_DIR` and `ARCHIVE_HOME_DIR` folders are present now;
  free space on their volumes and on the main disk.
- `herd php artisan horizon:status`; whether any queue jobs are waiting or running;
  the row count of `failed_jobs`.
- Whether Composer credentials for `composer.fluxui.dev` exist
  (`~/.composer/auth.json`, `~/.config/composer/auth.json`, or `./auth.json`): yes/no.

**CHECKPOINT 1.** End with a short "anything surprising" list.

## Phase 2 — Gates

Do not continue unless every one of these is true. If one is not, say which and stop.

- No import, generation, or upload is running or queued.
- The only modified tracked files are under `public/build/` (see Rule 3). Any
  other modified tracked file: stop and list it. Untracked files are fine and are
  left alone.
- Redis answers: `redis-cli ping` returns `PONG` (Herd's Redis service, or a
  Homebrew one). Horizon cannot run without it. If it does not answer, the owner
  starts Redis in the Herd app (Services); do not install Redis another way.
  Leave `QUEUE_CONNECTION` and `CACHE_STORE` in `.env` exactly as they are — an
  existing Horizon install uses `redis`, whatever `.env.example` suggests for new
  installs.
- Herd has PHP **8.4** installed. If not, the owner installs it in the Herd app
  (Herd → PHP); do not install PHP any other way.
- Duplicate `sha1` values are **not** a blocker from v2.0.1 on. Older installs
  legitimately hold the same frame in more than one class or show; the upgrade
  flags those photos as grandfathered and changes nothing else about them. Just
  make sure the count was reported in Phase 1.
- Flux Composer credentials exist.
- Homebrew rsync 3 exists at `/opt/homebrew/bin/rsync` or `/usr/local/bin/rsync`.
  If not, ask before running `brew install rsync`.
- `swift --version` works (Xcode command-line tools).
- At least 5 GB free on the main disk.

## Phase 3 — Stop workers and back up

If `horizon:terminate` says Horizon is not running, that is fine: continue.

```sh
herd php artisan horizon:terminate
backup=~/proofgen-backups/$(date +%Y%m%d-%H%M%S)-pre-v2
mkdir -p "$backup"
cp -p .env "$backup/env.backup"
cp -p "<the DB_DATABASE file from Phase 1>" "$backup/"
git rev-parse HEAD > "$backup/git-head.txt"
git describe --tags --always > "$backup/git-version.txt"
herd php -v | head -1 > "$backup/php-version.txt"
# For the record only. Old upload failures can explain past problems; do not prune them.
sqlite3 "<the DB_DATABASE file>" "SELECT COUNT(*) FROM failed_jobs;" > "$backup/failed-jobs-count.txt"
ls -la "$backup"
```

Confirm the database copy has the same size as the original. If a Core Image
daemon is running (`ps aux | grep -i coreimage | grep -v grep`), report it; it is
restarted in Phase 5.

**CHECKPOINT 2.** Report the backup folder path.

## Phase 4 — Update the code and dependencies

```sh
git status --short             # if only public/build/ is modified:
git checkout -- public/build   #   restore it (Rule 3); anything else: stop
git fetch origin --tags
git checkout main
git pull --ff-only origin main
git describe --tags            # expect v2.0.2 or later — stop if it is older
herd isolate 8.4               # this site only; other Herd sites are unaffected
herd php -v                    # must show 8.4.x — stop if it does not
herd composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

If `git checkout main` or the pull reports a conflict or refuses, stop. Do not
force it.

**CHECKPOINT 3.** Report versions and that Composer and the build succeeded.

## Phase 5 — Database, native renderer, caches

```sh
herd php artisan migrate --force
herd php artisan migrate:status | tail -15     # every row must say Ran
herd php artisan proofgen:swift-compile
herd php artisan proofgen:swift-check --force
herd php artisan config:clear
herd php artisan route:clear
herd php artisan view:clear
herd php artisan cache:clear
```

Then report how many photos were grandfathered (expect the Phase 1 duplicates,
counted per photo rather than per hash):

```sh
sqlite3 "<the DB_DATABASE file>" "SELECT COUNT(*) FROM photos WHERE sha1_grandfathered = 1;"
```

If a migration fails, stop: nothing was deleted, and the Phase 3 copy is intact.
A Swift compile failure is not fatal (the app falls back to GD) but report it.

## Phase 6 — Connect it to the website

The owner supplies the API token. **The owner types it into `.env` himself**; the
session never sees or echoes it.

1. Ensure `.env` has `FERRARAPHOTO_API_BASE="https://ferraraphoto.com"` and ask the
   owner to add `FERRARAPHOTO_API_TOKEN=…`. Leave every `SFTP_*` line exactly as it
   is: those are the fallback, and the private key path is still required.
2. Saved Settings can override `.env`. Report the `configurations` rows whose key
   starts with `ferraraphoto.` (value for `base_url`; set/not set for the token).
   If `ferraraphoto.base_url` exists and is not `https://ferraraphoto.com`, tell
   the owner — he changes it in the app's Settings page.
3. `herd php artisan config:clear`

**CHECKPOINT 4.**

## Phase 7 — Verify (all read-only)

```sh
herd php artisan app:version
herd php artisan tinker --execute='
$api = app(App\Services\Ferraraphoto\FerraraphotoApiClient::class);
echo "api base: ".config("proofgen.ferraraphoto.base_url")."\n";
$shows = $api->listShows(limit: 5);
echo "website shows: ".($shows === null ? "endpoint missing" : implode(", ", array_column($shows, "slug")))."\n";
$r = app(App\Services\Delivery\DeliveryTargetResolver::class);
foreach (App\Models\Show::orderByDesc("created_at")->take(3)->get() as $show) {
    $d = $api->deliveryTarget($show->ferraraphoto_slug);
    echo $show->id.": website says exists=".var_export($d["show"]["exists"] ?? null, true)
        ." proofs=".($d["destinations"]["proofs"]["directory"] ?? "-")
        ." | local settings proofs=".$r->local($show)->directory("proofs")."\n";
}'
```

Report whether, for each show, the website's proofs directory equals the local
settings directory. A difference is the drift this version exists to catch:
report it, change nothing. An empty local directory (for example
`SFTP_HIGHRES_IMAGES_PATH` was never set on this machine) is not a problem once
the website supplies the destination; it only matters against an older website.

Then the owner (not the session) opens the app in the browser, starts the
background workers from the page header, and the session confirms:

```sh
herd php artisan horizon:status
herd php artisan horizon:list        # expect a supervisor-uploads entry
```

The owner opens one show: the Ferraraphoto target panel should show
"from website" after the first delivery, or "local settings" before it, and an
"on website" badge. **Check** under Uploads runs a dry run and should report
nothing unexpected. Real uploads are the owner's call.

## Rollback

Only if the owner asks for it.

```sh
herd php artisan horizon:terminate
git checkout v1.1.8
herd isolate <the PHP version recorded in php-version.txt>
cp -p "$backup/env.backup" .env
cp -p "$backup/<database file>" "<the DB_DATABASE path>"
herd composer install --no-dev --optimize-autoloader
npm ci && npm run build
herd php artisan config:clear && herd php artisan view:clear && herd php artisan cache:clear
```

Then start the workers from the app. The database copy predates the v2
migrations, so restoring it is what makes v1.1.8 runnable again.
