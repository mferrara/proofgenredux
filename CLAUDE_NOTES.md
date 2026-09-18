# Proofgen Redux project notes

## Start here

- [Documentation index](docs/README.md) — current guides versus historical plans.
- [Photo pipeline](docs/photo-pipeline.md) — import identity, originals/archive,
  quarantine, generation, uploads, and recovery invariants. Read before pipeline changes.
- **Inbox:** `gh issue list -R mferrara/proofgen-feedback --state open` — problem
  reports filed from installs with `php artisan proofgen:report`. See [Feedback](docs/FEEDBACK.md).
- [Testing](docs/TESTING.md) — isolated checkout and guarded SQLite `:memory:`.
- [Ferraraphoto integration](docs/FERRARAPHOTO_INTEGRATION.md) — local delivery and
  existing storage/API code; not a claim of production S3 cutover.
- [Show-prep next steps](docs/SHOW_PREP_TODO.md) and
  [latest local validation](docs/reviews/2026-09-13-show-prep-finish.md).

## Current state — September 15, 2026

Local `main` contains the core upload, file-safety, hash-uniqueness, queue/UI,
image-output and unified server-delivery fixes. See the
[delivery review](docs/reviews/2026-09-15-server-delivery.md).
The isolated suite passed 513 tests, with 22 skips and 2,208 assertions.
NASDEMO26 has 24 completed sample photos; NAS_LOAD26 adds 48 completed photos
across two classes. The latter's 192 generated JPEGs matched local delivery copies.
A real 45 MP EXIF-rotated original rendered in all four Settings tabs. Long proof
labels now fit both proof sizes. Existing proofs require regeneration to receive
new output behavior; final watermarked proof quality is 95.

The [beta smoke test](docs/reviews/2026-09-18-beta-smoke.md) passed September 18:
`26Test01`, one class/two photos, eight delivered files with matching SHA-256,
four working proof URLs, and matching Gallery web/highres download streams.
Local API/SSH configuration now targets beta and the shared production media
roots. Automatic uploads remain OFF; Horizon remains stopped. Manual Upload
now targets that server. The original 72 photos were not reuploaded.

The smoke exposed two local fixes: use the already-seeded legacy storage profile
without registering it, and cast SFTP environment ports to integers. Focused
isolated validation: 25 tests, 76 assertions. Dad's laptop, recognition,
encoding, S3 migration, and updater research remain deferred/separate.

**Gallery-owned delivery destination and show list (same day, flower briefs
3832/3833, branch `delivery-target-handshake`).** Proofgen asks Gallery where a
show's rsync delivery goes (`GET /api/v1/delivery-target`) once per class
delivery and saves the answer on the show (`app/Services/Delivery/`,
`shows.delivery_target`). Errors never fall back to local SFTP settings; a
Gallery outage reuses the answer Gallery already gave for that show; a
destination that changes after a show uploaded files stops delivery until the
operator accepts it on the show page. Shows are created on the website: once
`GET /api/v1/shows` answers, Proofgen never POSTs a new show, Create Show and
the slug override become pickers (`app/Services/Ferraraphoto/WebsiteShows.php`),
and a missing show fails with "create it on the website first". Against an older
Gallery (404 on either endpoint) everything behaves as before. Both endpoints
are live on beta; the [delivery smoke](docs/reviews/2026-09-18-delivery-target-smoke.md)
through the new path passed on `26Test01`. Details: [the handshake doc](docs/GALLERY_DELIVERY_HANDSHAKE.md).
Isolated validation: full suite 549 passed, 22 skipped. Adds two nullable columns
on `shows`; the in-app updater runs the migration, a manual checkout needs
`php artisan migrate`.

**Cutover to the main domain (same day).** The website moved from
`beta.ferraraphoto.com` to `https://ferraraphoto.com`. This Mac's API base was
switched in both places that hold it (`.env` `FERRARAPHOTO_API_BASE` and the saved
Settings value `ferraraphoto.base_url`); the token and SSH settings are unchanged.
Verified through the app: both endpoints answer, `26Test01` re-confirmed its
destination against the new origin with no "destination changed" (same host and
paths), pending dry runs 0/0/0. Every other Proofgen machine needs the same
one-value change (Settings → Ferraraphoto API base URL, and `.env` if set there).

**v2.0.1 (same day): legacy duplicate hashes no longer block an upgrade.** The
photographer's v1.1.8 database holds 223 duplicated source hashes (frames shared
between portrait sessions and the main show, across classes, and a few within a
class). v2.0.0's unique-`sha1` migration refused to run on it. Those photos are
published history, so they are now flagged `photos.sha1_grandfathered` and left
alone; the unique index is partial and still protects new imports. See
[photo pipeline §5](docs/photo-pipeline.md). The upgrade runbook for an old
install is [UPGRADE_FROM_V1](docs/UPGRADE_FROM_V1.md). Isolated suite: 552 passed,
22 skipped.

**v2.0.2: `public/build/` is no longer committed.** The committed copy was v1
assets from April 2025 and every install showed it modified after a build. It is
now git-ignored and built per install; tests call `withoutVite()` in the base
`TestCase`. An install still on v2.0.0/v2.0.1 must run
`git checkout -- public/build` before pulling v2.0.2 (its in-app updater does not),
then `npm run build`.

**v2.1.0–v2.2.0: feedback from installs.** `php artisan proofgen:report` files a
redacted problem report (diagnostics attached, saved locally first, sent as an
issue to the private repo `mferrara/proofgen-feedback`, duplicates become
comments). `CLAUDE.md` / `AGENTS.md` tell any LLM session on an install to report
instead of editing code there. v2.2.0 adds Sentry (`sentry/sentry-laravel`), off
unless `SENTRY_LARAVEL_DSN` is set, with every event passed through the same
redactor and tracing disabled. Guide: [docs/FEEDBACK.md](docs/FEEDBACK.md).
v2.0.8 fixed the parallel-import folder race (`App\Services\SafeDirectory`) and
returns a pool proof number when its import fails.

**v2.2.1: first batch of reports from the photographer's install (proofgen-feedback
#2–#8).** Proof watermark text is now rendered by glyph coverage on a truecolor
canvas: the old palette canvas gave text a dark outline that grew as the
background was made more transparent; the text itself is exactly as bright as
before. Automatic delivery runs that have nothing left to upload stop before any
website or SSH work (each generation kind announces completion, so a class used
to get up to three full runs). A red status-bar warning appears when the working
folder is managed by iCloud or holds evicted files (`App\Services\WorkingFolderHealth`).
A migration clears the junk `"0"` SFTP path a 2025 migration saved. The normal
"remote directory does not exist yet" line is DEBUG, not WARNING. The upgrade
runbook reports effective settings (saved rows override `.env`) and gates on iCloud.

## Stack and local paths

Laravel 13 · Livewire 4 · Flux 2 · Tailwind 4 · Intervention Image 4 · Pest 4.
Use Herd PHP 8.4; Composer now requires PHP `^8.4`.

- Checkout: `/Users/mikeferrara/Documents/code/proofgenredux`.
- Operator DB: `database/database.sqlite` under that checkout; never a test target.
- Local URL: `http://proofgenredux.test`.
- Working images: `/Volumes/Public/proofgen-dev/shows`.
- Archive root: `/Volumes/Public/proofgen-dev/archive`; archives were disabled for
  the latest NAS rehearsal. A configured root alone does not mean backups are enabled.
- Settings sample: `/Volumes/Public/proofgen-dev/sample-previews`, configured by
  `SAMPLE_IMAGES_PATH`; modest sample retained for routine previews.
- Pristine samples: `/Volumes/Public/_Backups/sample_show_photos`; copy selected
  files into the working tree, never import directly from the backup library.
- Local Ferraraphoto: `/Users/mikeferrara/Documents/code/ferraraphoto`; its three
  delivery paths resolve into `/Volumes/Public/proofgen-dev/delivery`.

These are this development Mac's paths, not installation defaults. Mount Public
in Finder before using this setup. The Mac processes images; NAS placement keeps
working originals and outputs off its internal disk. No SQLite MCP connection is
assumed; use tools actually available in the session.

## Operating behavior worth preserving

- Import scans immediate class-folder `.jpg`/`.jpeg` files. It is operator-triggered.
- Non-null `photos.sha1` is globally unique; same-class retries reuse identity.
- Show/class identity comes from actual records, not splitting underscore IDs.
- Originals/archive/ingest files are retained through the graveyard/conflict flows.
- Import/derivative jobs use native uniqueness; queue-scoped UI and server guards
  prevent repeated overlapping actions, including delayed retries.
- Show/class storage usage keeps stale results visible until manual refresh.
- Core Image is preferred; GD fallback cannot supply local shadow/highlight adjustments.
- Settings reports per-tab failures and allows retry. Its large-image memory
  allowance is request-local; it does not require increasing all Herd requests.
- The legacy upload path uses rsync 3 and stamps only rsync-confirmed files.
- Automatic/manual uploads share profile-aware delivery. The saved Uploads switch
  gates automatic dispatch and job start; manual uploads bypass it. Automatic
  transfers wait for class completion per image kind, avoiding unfinished files.

## Samples and tests

Normal tests force `AUTO_DOWNLOAD_SAMPLE_IMAGES=false` and block unfaked HTTP.
Optional sample-bucket commands remain available, but do not configure bucket
credentials or auto-download for the regular suite. See [TESTING](docs/TESTING.md)
and [datasets](docs/datasets/README.md) instead of old S3 test-setup instructions.

The generated [FLOWER.md](FLOWER.md) describes recall tools. Recall may lag current
commits; verify branch, source and dated validation before treating old open loops
as unfinished work. Do not hand-edit the generated sidecar.
