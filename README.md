# Proofgen Redux

Proofgen is a macOS desktop application served by Laravel Herd. It imports event
photography originals, assigns proof numbers, generates watermarked proofs and
paid digital products, and delivers files to Ferraraphoto. The operators are the
owner, his father, and occasionally a known employee. Production is Dad's MacBook;
Ferraraphoto is the separate public website.

## Current status

Core file-safety, upload, queue/UI, and image-output fixes are on `main`.
The September 13 local rehearsal completed 48 additional NAS-backed imports and
192 generated/delivered JPEGs, with checksum verification and no failed jobs.
All four Settings previews also rendered a real 45 MP portrait. See the
[validation report](docs/reviews/2026-09-13-show-prep-finish.md) for scope and results.
The next check is a small class on Dad's MacBook with its actual upload targets.
Recognition is deferred.

## Requirements and setup

- macOS with Laravel Herd, Redis, and PHP 8.4 selected for this project and workers.
- Composer and Node/npm for dependencies and frontend builds.
- PHP GD, EXIF, Redis, fileinfo, pcntl, and SQLite/PDO support.
- Homebrew rsync 3 (`brew install rsync`). The upload runner selects Homebrew rsync;
  `RSYNC_BINARY` can override it. Apple's openrsync does not supply the expected
  upload-status evidence.
- Xcode command-line tools/Swift for the Core Image renderer. See
  [Core Image operation](docs/core-image-enhancement.md).

The locked stack is Laravel 13, Livewire 4, Flux 2, Tailwind 4, Intervention Image 4,
and Pest 4. PHP 8.4 is the validated runtime. The root Composer PHP constraint is
still `^8.2`, but locked Laravel/Intervention/Pest dependencies require PHP 8.3+;
that root constraint is not a guarantee of PHP 8.2 compatibility.

For a new installation, configure its own `.env`, application key, SQLite database,
image roots, and upload destinations before running migrations. Preserve existing
configuration and the database when updating an existing installation.

```sh
composer install
npm ci
php artisan migrate
npm run build
```

Herd serves the application; `npm run dev` is optional while editing frontend
assets. Start background workers using the page header or Settings → Services.
The header reports worker state and queued/running work. Source changes require
refreshing long-running workers to take effect.

## Normal workflow

1. Mount the working image drive and any enabled archive drive.
2. Create a show and class folders under the fullsize root; copy incoming JPEGs
   into the class folder. Keep a pristine sample library separate from this root.
3. Open the show/class and click **Import**. Import may move the ingest copy.
   Show/class discovery and live polling do not automatically import every file.
4. Watch import, proof, web, highres, and upload counts. Generation honors the web
   and highres enable switches. Busy actions are disabled; import and derivative
   jobs also reject duplicate queue admission.
5. Review any Issues before retrying. Identical content has one global `photos.sha1`
   identity: a same-class retry reuses the record/proof number, while cross-class
   duplicates are held for review.
6. Inspect delivered proofs/products. Storage usage retains the last calculation,
   marks it stale after ten minutes, and rescans on **Calculate/Refresh**.

```text
FULLSIZE_HOME_DIR/
├── {show}/{class}/                 incoming JPEGs
│   ├── originals/                 imported originals
│   └── _import_conflicts/         imports needing review
├── proofs/{show}/{class}/          small and large proof JPEGs
├── web_images/{show}/{class}/      paid web JPEGs
├── highres_images/{show}/{class}/  paid highres JPEGs
└── _graveyard/                    retained source files; hidden from show discovery
```

Database-backed Settings override the corresponding `proofgen.*` defaults. The
`fullsize_home_dir` and `archive_home_dir` settings also configure their filesystem
disks. Archives, when enabled, are verified second copies before the ingest file
is released. See [archive backups](docs/archive-backups.md) and the
[photo pipeline](docs/photo-pipeline.md) for recovery and conflict handling.

## Development and testing

Use [the isolated test procedure](docs/TESTING.md), not the live operator checkout.
It documents the SQLite guard, physical vendor copy, synthetic environment,
optional image tests, and commands. Do not copy a real `.env`, database, or image
library into the test checkout. Code formatting: `./vendor/bin/pint`.

Flux styles are customized in `resources/css/app.css`. The previously referenced
`external-docs/fluxui/index.md` is absent from this checkout; check existing views
and the installed Flux components, and consult version-matched documentation when
introducing unfamiliar components.

## Storage, samples, and updates

The validated local development setup uses TrueNAS for originals, generated files,
and local Ferraraphoto delivery. The Mac still performs processing. See the
[dataset guide](docs/datasets/README.md) for paths and reproducible manifests.

Storage profile, API client, and migration code exists, but the local rehearsal
used legacy/local rsync delivery. It does not establish S3 cutover or customer
purchase readiness. The intended storage migration remains: implement the needed
storage support, copy existing files into the bucket(s), then switch over. See
[integration status](docs/FERRARAPHOTO_INTEGRATION.md).

An in-app updater already exists in `UpdateService`; update research is a review
of that implementation. It pulls `main` with tags and may check out a tag, so a
new commit alone does not guarantee the updater selects it. See
[the show-prep checklist](docs/SHOW_PREP_CHECKLIST.md) before installation.

## Documentation

Start at [the documentation index](docs/README.md). [CLAUDE_NOTES.md](CLAUDE_NOTES.md)
is the short contributor handoff; [CLAUDE.md](CLAUDE.md) and [AGENTS.md](AGENTS.md)
contain project instructions. Dated files under `docs/reviews/` are historical
validation records, not continuously updated setup guides.
