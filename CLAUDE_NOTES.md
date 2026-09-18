# Proofgen Redux project notes

## Start here

- [Documentation index](docs/README.md) — current guides versus historical plans.
- [Photo pipeline](docs/photo-pipeline.md) — import identity, originals/archive,
  quarantine, generation, uploads, and recovery invariants. Read before pipeline changes.
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
isolated validation: 25 tests, 76 assertions. See the
[proposed Gallery handshake contract](docs/GALLERY_DELIVERY_HANDSHAKE.md) for
next-session coordination; no handshake implementation has begun. Dad's laptop,
recognition, encoding, S3 migration, and updater research remain deferred/separate.

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
