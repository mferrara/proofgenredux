# Photo Pipeline Reference

> **Audience.** Future you, future agents, anyone trying to understand how a JPG moves through proofgen from "operator dropped it on the desktop" to "uploaded to ferraraphoto.com."
>
> **Scope.** Every entry point, every decision point, every service that touches a source-of-truth file. Cross-references file paths to make navigation easy.
>
> **Trust model.** This is a single-tenant macOS desktop app served by Laravel Herd. See `CLAUDE.md` for the full trust model — there are no hostile users, no auth boundaries, no defensive validation against malicious input.

---

## 1. Filesystem layout

Two local disks, configured in `config/filesystems.php` and overridable via DB-backed config (see `app/Providers/ConfigurationServiceProvider.php` + `app/Services/ImageDiskConfigurator.php`).

```
fullsize disk root  (env: FULLSIZE_HOME_DIR)
├── {show}/{class}/                          ← ingest landing zone
│   │   (operator drops camera files here)
│   │
│   ├── originals/{proof_number}.jpg         ← source-of-truth after import
│   ├── _import_conflicts/{stem}_{ts}_{sha8}.jpg
│   │   + .json sidecar                      ← quarantined incoming sources
│   │                                          (resolver couldn't safely import)
│   └── (no other "data" subdirs at this level)
│
├── proofs/{show}/{class}/{proof_number}{suffix}.jpg     ← derived; regeneratable
├── web_images/{show}/{class}/{proof_number}_web.jpg     ← derived; regeneratable
├── highres_images/{show}/{class}/{proof_number}_highres.jpg ← derived; regeneratable
│
└── _graveyard/{YYYY-MM-DD}/{original-relative-path}_{ts}_{sha8}.jpg
    + .json sidecar                          ← never-deleted source-of-truth files

archive disk root  (env: ARCHIVE_HOME_DIR)
├── {show}/{class}/{proof_number}.jpg        ← verified archive copy
├── {show}/{class}/_conflicts/{stem}_{ts}_{sha}.jpg  ← displaced archive copies
│                                              (different bytes were here previously)
└── _graveyard/{YYYY-MM-DD}/{...}            ← never-deleted archive copies
```

**Three FS quarantine concepts, do not confuse them:**

| Folder | Disk | What it holds | Created by | UI label |
|--------|------|---------------|------------|----------|
| `_graveyard/` | per-disk | "We were going to delete this; here it is" | `SafeFileMover::bury()` | "Graveyard" |
| `_import_conflicts/` | fullsize | "We couldn't decide whether to import this" | `SafeFileMover::quarantineImport()` | "Import Conflicts" |
| `_conflicts/` | archive | "An archive file with different bytes was already here" | `PhotoArchiveService::moveConflictingArchiveAside()` | "Archive Conflicts" |

All three write a sibling `.json` sidecar (schema `proofgen.graveyard.v1`).

---

## 2. High-level flow

```mermaid
flowchart TD
    Drop[Operator drops file in<br>fullsize/{show}/{class}/] --> Discover

    subgraph Discovery
        Discover[ShowClass::getImagesPendingImport]
        Discover --> Dispatch[ImportPhoto::dispatch<br>NO proof number passed]
    end

    Dispatch --> Job[ImportPhoto::handle]
    Job --> Service[PhotoService::processPhoto]
    Service --> Bypass{bypassResolver?<br>set by UI resolution<br>actions only}

    Bypass -->|true| RunImport[runImport<br>private]
    Bypass -->|false| Resolver[PhotoImportIdentityResolver::resolve]

    Resolver --> Decision{decision}
    Decision -->|IMPORT_NEW| Allocate{allocates<br>new proof#?}
    Decision -->|IDEMPOTENT_EXISTING| Idempotent[handleIdempotentRetry<br>repair + bury source]
    Decision -->|DUPLICATE_CONTENT<br>PROOF_COLLISION<br>NEEDS_REVIEW| Issue[PhotoImportIssueRecorder<br>quarantine + create photo_issues row]

    Allocate -->|true raw camera filename| Redis[Show::getNextProofNumber<br>Redis LPOP]
    Allocate -->|false rehydrated already-numbered| Embedded[Use embedded<br>proof number from filename]
    Redis --> RunImport
    Embedded --> RunImport

    RunImport --> Image[Image::processImage]
    Image --> Hash[1. Read source bytes + sha1]
    Hash --> Archive{archive<br>enabled?}
    Archive -->|yes| ArchiveWrite[2. PhotoArchiveService::storeContents<br>write + verify]
    Archive -->|no| OriginalWrite
    ArchiveWrite --> OriginalWrite[3. Write original<br>verify sha1 + size]
    OriginalWrite --> DB[4. Image::importPhoto<br>upsert photos row]
    DB --> Bury[5. SafeFileMover::bury<br>source → _graveyard/]

    Bury --> Dispatch2[Service: dispatch derivative jobs<br>if dispatchJobs=true]
    Idempotent --> End([end])
    Issue --> End
    Dispatch2 --> Thumbs[GenerateThumbnails]
    Dispatch2 --> Web[GenerateWebImage]
    Dispatch2 --> Highres[GenerateHighresImage]

    Thumbs --> Upload[UploadProofs<br>when class fully proofed]
    Web --> Upload2[UploadWebImages<br>when class fully done]
    Highres --> Upload3[UploadHighresImages<br>when class fully done]

    Upload --> Remote[(ferraraphoto.com<br>via rsync)]
    Upload2 --> Remote
    Upload3 --> Remote
```

---

## 3. Entry points

A photo enters the pipeline through one of these:

| Entry point | Triggered by | Where | Notes |
|-------------|-------------|-------|-------|
| **Polled discovery** | `ClassViewComponent` polling | `app/Livewire/ClassViewComponent.php` → `importPendingImages()` | Every Livewire request scans for new files |
| **Manual import button** | UI click | `ClassViewComponent::importPendingImages` | Same path as polled |
| **Show-level import** | UI click | `app/Models/Show.php::importPendingImages` | Walks all classes |
| **CLI / dev manual** | Direct call | `app/Proofgen/ShowClass.php::processImage($path)` | Routes through `PhotoService::processPhoto` |
| **Resolution: Re-import quarantined** | UI click in Issues panel | `app/Livewire/PhotoIssuesComponent.php::reimportQuarantinedSource` | Uses `bypassResolver: true` |
| **Resolution: Replace existing** | UI click + confirm | `PhotoIssuesComponent::replaceExistingWithIncoming` | Buries existing first, then bypass-imports |
| **Audit/repair** | `php artisan proofgen:audit --repair` | `app/Console/Commands/AuditPhotoArchivesCommand.php` | Doesn't move files unless repairing |

All file-writing entry points (the first six) eventually call `PhotoService::processPhoto`.

---

## 4. Core import flow (step-by-step)

### 4.1 Discovery → dispatch

`ShowClass::getImagesPendingImport()` (`app/Models/ShowClass.php`) lists `images` in the class folder (excluding `originals/`, `_import_conflicts/`, `_graveyard/`).

For each image:

```php
ImportPhoto::dispatch($image->path())->onQueue('processing');
```

**Critical**: no proof number is passed. This is intentional — pre-allocating from Redis here would burn proof numbers on duplicates. The resolver inside the job decides whether to allocate.

### 4.2 The job

`app/Jobs/Photo/ImportPhoto.php`:

```php
public function handle(): void
{
    app(PhotoService::class)->processPhoto($this->image_path, $this->proof_number_override);
}
```

`$proof_number_override` is null in normal flows. Only tests (and certain manual flows) pass an override.

### 4.3 PhotoService — orchestrator

`app/Services/PhotoService.php::processPhoto($imagePath, $proofNumberOverride, $debug, $dispatchJobs, $bypassResolver)`

Step-by-step:

1. **Parse path** — `{show}/{class}/{filename}` → `$show`, `$class`. Throws if shape is wrong.
2. **Branch on `$bypassResolver`**:
   - **true** → require `$proofNumberOverride`, jump straight to `runImport()`. (See §5.)
   - **false** → call resolver.
3. **Resolver runs** — `PhotoImportIdentityResolver::resolve($imagePath, $show, $class)` returns a `PhotoImportPlan`.
4. **Branch on `$plan->decision`**:
   - `requiresReview()` (DUPLICATE_CONTENT / PROOF_COLLISION / NEEDS_REVIEW) → `PhotoImportIssueRecorder::record()`. Returns immediately with `['photo' => null, 'issue' => $issue]`.
   - `isIdempotent()` (IDEMPOTENT_EXISTING) → `handleIdempotentRetry()`. Returns with `['photo' => $existing, 'issue' => null]`.
   - `shouldImport()` (IMPORT_NEW) → continue.
5. **Resolve final proof number** for IMPORT_NEW:
   ```
   $finalProofNumber = $proofNumberOverride
       ?? $plan->intendedProofNumber           ← already-numbered filename
       ?? Show::find($show)->getNextProofNumber();  ← raw camera filename
   ```
6. Call `runImport($imagePath, $finalProofNumber, $debug, $dispatchJobs, $plan)`.

### 4.4 runImport (private) — the verified write path

```php
private function runImport($imagePath, $finalProofNumber, $debug, $dispatchJobs, $plan): array
{
    $imageObj = new Image($imagePath, $this->pathResolver);
    $photo = $imageObj->processImage($finalProofNumber, $debug);

    // ... look up class paths, dispatch derivative jobs if requested ...

    return ['photo' => $photo, 'plan' => $plan, 'issue' => null, ...paths];
}
```

### 4.5 Image::processImage — the actual write

`app/Proofgen/Image.php::processImage($proof_number, $debug)` follows a strict order:

```
1. Read source bytes once + compute sha1 + size.
2. If archive_enabled: PhotoArchiveService::storeContents
       → writes archive copy, verifies sha1 + size after write.
3. Write imported original to {show}/{class}/originals/{proof}.{ext}
       → re-reads the written file and re-verifies sha1 + size.
4. Image::importPhoto upserts the photos row
       (sha1, original_filename, archive metadata).
5. SafeFileMover::bury removes the ingest source LAST.
       → goes to _graveyard/{date}/{show}/{class}/{stem}_{ts}_{sha8}.jpg
```

**Why this order is invariant.**
- If anything before step 4 fails, the source still exists in the ingest folder (recoverable).
- If step 4 (DB write) fails, we have an archive copy + original on disk but no DB row — the next audit pass detects "orphan original" and operator can investigate.
- If step 5 fails, source is still there but archive + original + DB row all present — next import attempt hits IDEMPOTENT_EXISTING and re-buries.

---

## 5. Resolver decision matrix

`app/Services/PhotoImportIdentityResolver.php::resolve()` returns a `PhotoImportPlan` with one of six decisions.

The classifier looks at **two things**:
1. Does the basename strictly match `{SHOW_UPPERCASE}_{5-digit}.{ext}` for the current show? (Already-numbered vs raw)
2. SHA-1 lookup in `photos.sha1` and proof-number lookup in `photos.proof_number`.

`IMG_02631.jpg` is **raw** even though it has digits. `22BUCK_00093.jpg` for show `22Buck` is **already-numbered** (case-insensitive on the prefix).

### Decision table

| Filename type | SHA found? | Proof# found? | Same photo? | Decision | What happens |
|---|---|---|---|---|---|
| numbered | yes | yes | yes (same row) | `IDEMPOTENT_EXISTING` | repair archive metadata if drifted, bury source |
| numbered | yes | * | * | `DUPLICATE_CONTENT` | quarantine source, create issue |
| numbered | no | yes | n/a | `PROOF_COLLISION` | quarantine source, create issue |
| numbered | no | no | n/a | `IMPORT_NEW` (use embedded #) | import; do NOT consume Redis proof number |
| raw | yes | n/a | n/a | `DUPLICATE_CONTENT` | quarantine source, create issue |
| raw | no | n/a | n/a | `IMPORT_NEW` (allocate next #) | import; consume next Redis proof number |

`INVALID_NUMBERED_FILENAME` and `NEEDS_REVIEW` are reserved decision codes; nothing currently emits them. If/when classification gets more nuanced, those slots are pre-wired into `PhotoImportPlan::requiresReview()` and `PhotoImportIssueRecorder::DECISION_TO_TYPE`.

### What the plan carries

```php
PhotoImportPlan {
    decision                    // enum string
    sourcePath                  // disk-relative
    originalFilename            // basename
    extension                   // lowercased
    sha1, size                  // of source bytes
    filenameIsNumberedForShow   // bool
    intendedProofNumber         // ?string — only set if filename was numbered
    allocatesNewProofNumber     // bool — true if IMPORT_NEW from raw filename
    existingByContent           // ?Photo — sha1 match
    existingByProofNumber       // ?Photo — proof# match in same show
    evidence                    // array — extra context for issues
}
```

The resolver **does not mutate**. It reads bytes, hashes, queries the DB. Test: `tests/Unit/Services/PhotoImportIdentityResolverTest.php`.

---

## 6. Resolution flows (post-decision)

### 6.1 IDEMPOTENT_EXISTING — `PhotoService::handleIdempotentRetry()`

The exact same image was dropped again. We don't re-import; we:
1. Run `PhotoArchiveService::auditPhoto($photo)`. If `archive_missing` / `metadata_stale` / `archive_mismatched` → call `repairPhoto()`.
2. Backfill `original_filename` if it was empty (legacy rows pre-`original_filename` migration).
3. `SafeFileMover::bury` the duplicate source with `reason: post_import_source` and `idempotent_retry: true` in context.

### 6.2 DUPLICATE_CONTENT / PROOF_COLLISION — `PhotoImportIssueRecorder::record()`

`app/Services/PhotoImportIssueRecorder.php`:

1. `SafeFileMover::quarantineImport()` moves source from `{show}/{class}/{file}.jpg` → `{show}/{class}/_import_conflicts/{stem}_{ts}_{sha8}.jpg` + `.json` sidecar.
2. Inserts a `photo_issues` row with:
   - `issue_type` (mapped from decision via `DECISION_TO_TYPE`)
   - `quarantine_path`, `incoming_sha1`, `incoming_size`
   - `existing_photo_id`, `existing_proof_number`, `existing_sha1` (from the matched photo)
   - `evidence` (serialized resolver evidence + sidecar path)
3. Returns the issue. Operator sees it in `/photo-issues`.

### 6.3 IMPORT_NEW — main verified write path

See §4.5. Photo created, source buried, derivative jobs dispatched (when `dispatchJobs=true`).

### 6.4 Operator resolution actions (in `PhotoIssuesComponent`)

`app/Livewire/PhotoIssuesComponent.php`:

| Action | Issue types it applies to | What it does |
|---|---|---|
| **Discard incoming** | duplicate_content, proof_collision | `SafeFileMover::bury()` the quarantined source. Resolve issue. |
| **Assign next proof number** | duplicate_content | `Show::getNextProofNumber()` then `PhotoService::processPhoto(quarantine_path, $newNumber, bypassResolver: true)`. Resolve. |
| **Replace existing with incoming** | proof_collision, duplicate_content | Modal confirm → bury existing's original + archive → `$existing->delete()` → relocate quarantined bytes if needed → `processPhoto(..., bypassResolver: true)` under existing's proof number. Resolve. |
| **Move existing photo to this class** | duplicate_content (cross-class) | `PhotoMoveService::movePhotos([$existing->id], $thisClassId)` → bury quarantined incoming. Resolve. |
| **Run safe repair** | missing_archive, metadata_mismatch | `PhotoArchiveService::repairPhoto($photo)`. Resolve if status now `ok`. |
| **Mark ignored** | any | `status = ignored` + notes. No file changes. |

---

## 7. Audit flow

`php artisan proofgen:audit [--repair] [--show=X] [--class=Y]`

`app/Console/Commands/AuditPhotoArchivesCommand.php` → `app/Services/PhotoAuditService.php::auditAll()`.

Per-photo checks (in `auditOnePhoto()`):
- SHA consistency: `photos.sha1` vs sha1 of local original bytes.
- Archive presence + verification: delegates to `PhotoArchiveService::auditPhoto()`.
- Repair (if `--repair`):
  - `archive_missing` / `metadata_stale` → `PhotoArchiveService::repairPhoto()` (write archive, refresh metadata).
  - `photos_sha1_missing` with local original → backfill sha1 from file.
- **Never auto-resolved**: `photos_sha1_mismatch_with_original`, `archive_mismatched` (renames archive copy aside, leaves both visible).

Cross-table checks:
- `findDuplicateSha1Groups()` — multiple photos with identical sha1.
- `findDuplicateProofNumberGroups()` — multiple photos with same proof number (within or across shows).
- `findOrphanOriginals()` — files in `{show}/{class}/originals/` with no Photo row.
- `findPhotosWithoutOriginalOrArchive()` — high-risk: DB row only, both file copies missing.

All non-OK findings are persisted as `photo_issues` rows via `recordOrUpdateIssue()` (idempotent upsert keyed on `issue_type` + `existing_photo_id` + `status=open`, so re-runs don't duplicate rows).

Test: `tests/Feature/PhotoAuditServiceTest.php`.

---

## 8. Move / rename flows

### 8.1 PhotoMoveService — single-photo move between classes

`app/Services/PhotoMoveService.php::movePhotos(array $photoIds, string $targetClassId)`.

Per photo, in a DB transaction:
1. Move original via `Storage::disk('fullsize')->move($oldRel, $newRel)`. Throws + rolls back if missing or move fails.
2. For each derivative (proofs small + large, web image, highres image):
   - If file exists at source path → `Storage::disk('fullsize')->move()` it to target path.
   - If file missing AND `*_generated_at` is non-null → dispatch the corresponding regen job (`GenerateThumbnails` / `GenerateWebImage` / `GenerateHighresImage`) for the new photo id at the target class.
   - "Move success doesn't depend on derivatives existing" — derivatives are always optional.
3. Move archive copy via `PhotoArchiveService::movePhotoArchive()`.
4. Insert new Photo row (saveQuietly, copy all timestamps), update metadata FK, delete old row.

### 8.2 ClassRenameService — rename a whole class folder

`app/Services/ClassRenameService.php::renameClass($showClass, $newName)`.

Pre-flight checks → renames each tree:
- `{show}/{oldClass}` → `{show}/{newName}` (originals + ingest landing zone, fullsize)
- `web_images/{show}/{oldClass}` → `web_images/{show}/{newName}` (fullsize)
- `highres_images/{show}/{oldClass}` → `highres_images/{show}/{newName}` (fullsize)
- `proofs/{show}/{oldClass}` → `proofs/{show}/{newName}` (fullsize)
- `{show}/{oldClass}` → `{show}/{newName}` (archive)

Then DB updates (new ShowClass row, photos `show_class_id` updated, archive_path updated, old ShowClass deleted).

Tests: `tests/Feature/PhotoMoveServiceTest.php`, `ArchiveBackupTest::test_class_rename_*`.

---

## 9. Graveyard retention

`_graveyard/` is the **only place** in the app where source-of-truth files exit normal lifecycle. Nothing else deletes them.

`SafeFileMover` is the only writer. The only deleter is `GraveyardService::purgeOlderThan()` (`app/Services/GraveyardService.php`), which is called only from the operator-facing UI:
- `/graveyard` page (`app/Livewire/GraveyardComponent.php`) shows per-disk summary + paginated entries + a "Purge older than 90 days" button.
- `app/Livewire/AppStatusBar.php::gravyardAlert()` surfaces an amber banner when aged files exist; "Snooze 24h" caches a snooze key.

Aged threshold is `proofgen.graveyard.aged_days` (default 90).

Sidecar JSON shape (`proofgen.graveyard.v1`):

```json
{
  "schema": "proofgen.graveyard.v1",
  "disk": "fullsize",
  "original_path": "22Buck/007/IMG_02631.jpg",
  "sha1": "...",
  "size": 23456789,
  "reason": "post_import_source",
  "buried_at": "2026-05-09T14:30:25-04:00",
  "context": { "photo_id": "22Buck_007_22BUCK_00093", "original_filename": "IMG_02631.jpg" }
}
```

Reasons (from `SafeFileMover::REASON_*`): `post_import_source`, `photo_deleted`, `displaced_original`, `redundant_archive_source`, `import_conflict`.

---

## 10. Database tables

### `photos`

Migration: `database/migrations/2025_*` (originals) + `2026_05_03_000001_add_archive_fields_to_photos_table.php` + `2026_05_09_000001_add_original_filename_to_photos_table.php`.

| Column | Type | Notes |
|---|---|---|
| `id` | string | `{show_id}_{class}_{proof_number}` |
| `show_class_id` | string | FK → `show_classes.id` |
| `proof_number` | string | e.g. `22BUCK_00093` |
| `file_type` | string | `jpg`, `jpeg`, etc. |
| `sha1` | string(40) | of source bytes — file identity |
| `original_filename` | string nullable | camera filename at import time (forward-only since 2026-05-09) |
| `archive_path` | string nullable | relative to archive disk root |
| `archive_sha1` | string(40) nullable | of archive copy bytes |
| `archive_size` | bigint nullable | |
| `archived_at` | timestamp nullable | |
| `proofs_generated_at`, `proofs_uploaded_at` | timestamps | derivative state |
| `web_image_generated_at`, `web_image_uploaded_at` | timestamps | |
| `highres_image_generated_at`, `highres_image_uploaded_at` | timestamps | |

### `photo_issues`

Migration: `database/migrations/2026_05_09_000002_create_photo_issues_table.php`.

Single typed table for all issue kinds. Both import-time conflicts (duplicate_content, proof_collision) and audit-time findings (missing_archive, metadata_mismatch, archive_conflict, orphan_original, missing_original) live here. UI inbox at `/photo-issues` filters by `issue_type` + status.

| Column | Notes |
|---|---|
| `status` | `open` / `resolved` / `ignored` |
| `issue_type` | enum-string; constants on `PhotoIssue::TYPE_*` |
| `show_id`, `show_class_id` | scoping |
| `source_path` | original ingest path (before quarantine) |
| `quarantine_path` | where the source went (`_import_conflicts/...`) — null for audit findings |
| `intended_proof_number` | what the resolver was about to use |
| `incoming_sha1`, `incoming_size`, `incoming_mtime` | of the rejected source |
| `existing_photo_id`, `existing_proof_number`, `existing_sha1` | the matched/conflicting photo |
| `evidence` | JSON; resolver evidence + extras |
| `resolved_at`, `resolved_by_user`, `notes` | resolution metadata |

### `photo_metadata`

Pre-existing. EXIF + size info, FK on `photo_id`.

---

## 11. Key invariants

> If you change anything in the import pipeline, check these still hold.

1. **Never delete source-of-truth files.** Originals, archive copies, ingest sources never go through `unlink()` or `Storage::delete()`. They go through `SafeFileMover::bury()` or `quarantineImport()`. The one exception is `GraveyardService::purgeOlderThan()`, which deletes from the graveyard itself. Derived files (proofs / web / highres) are exempt — they regenerate.
2. **Write order in `Image::processImage` is locked.** hash → archive (verified) → original (verified) → DB row → bury source. Source removal is always last.
3. **Don't allocate proof numbers before the resolver.** Dispatchers must dispatch `ImportPhoto::dispatch($path)` with no proof number. The resolver inside the job decides whether to allocate.
4. **`bypassResolver` requires an explicit proof number.** It exists for operator-driven resolution actions where the resolver would re-flag the same conflict that produced the issue. Never use it for normal imports.
5. **A filename is only "numbered" if it strictly matches `{SHOW_UPPERCASE}_{5-digit}`.** Class names containing underscores are safe — only the show prefix matters. `extractEmbeddedProofNumber()` is the canonical check.
6. **Issue rows are upserted, not appended, by the audit.** `PhotoAuditService::recordOrUpdateIssue()` is idempotent on (`issue_type`, `existing_photo_id`, `status=open`) so re-running audit doesn't duplicate.
7. **Storage facade only — no raw `rename()`/`unlink()`/`file_*()` on data files.** This keeps every move/delete observable and disk-aware. Direct filesystem calls are restricted to test scaffolding and the `Image::create*` derivative writers (which work on derived files, not originals).

---

## 12. Service catalog

Alphabetical reference. File paths are absolute from repo root.

| Service / class | File | Role |
|---|---|---|
| `App\Console\Commands\AuditPhotoArchivesCommand` | `app/Console/Commands/AuditPhotoArchivesCommand.php` | `proofgen:audit` CLI; thin wrapper over `PhotoAuditService` |
| `App\Jobs\Photo\ImportPhoto` | `app/Jobs/Photo/ImportPhoto.php` | Queue job that runs `PhotoService::processPhoto` |
| `App\Jobs\Photo\GenerateThumbnails` | `app/Jobs/Photo/GenerateThumbnails.php` | Derivative regen |
| `App\Jobs\Photo\GenerateWebImage` | `app/Jobs/Photo/GenerateWebImage.php` | Derivative regen |
| `App\Jobs\Photo\GenerateHighresImage` | `app/Jobs/Photo/GenerateHighresImage.php` | Derivative regen |
| `App\Jobs\ShowClass\UploadProofs` | `app/Jobs/ShowClass/UploadProofs.php` | rsync proofs → ferraraphoto |
| `App\Jobs\ShowClass\UploadWebImages` | `app/Jobs/ShowClass/UploadWebImages.php` | rsync web → ferraraphoto |
| `App\Jobs\ShowClass\UploadHighresImages` | `app/Jobs/ShowClass/UploadHighresImages.php` | rsync highres → ferraraphoto |
| `App\Livewire\AppStatusBar` | `app/Livewire/AppStatusBar.php` | Top status bar; surfaces issue count + graveyard alert |
| `App\Livewire\ClassViewComponent` | `app/Livewire/ClassViewComponent.php` | Per-class UI; ingest discovery; per-photo actions; deletion routes through `SafeFileMover` |
| `App\Livewire\GraveyardComponent` | `app/Livewire/GraveyardComponent.php` | `/graveyard` page |
| `App\Livewire\PhotoIssuesComponent` | `app/Livewire/PhotoIssuesComponent.php` | `/photo-issues` inbox + resolution actions |
| `App\Livewire\ShowViewComponent` | `app/Livewire/ShowViewComponent.php` | Per-show UI; class summary + badges |
| `App\Models\Photo` | `app/Models/Photo.php` | Photo Eloquent model; derived-file delete helpers |
| `App\Models\PhotoIssue` | `app/Models/PhotoIssue.php` | Issue model; constants for all types/statuses; `severityColor()` for UI |
| `App\Models\Show`, `App\Models\ShowClass` | `app/Models/{Show,ShowClass}.php` | `getNextProofNumber()`, `importPendingImages()` discovery |
| `App\Proofgen\Image` | `app/Proofgen/Image.php` | Lower-level image worker; `processImage()` is the verified write path; static methods for derivative generation |
| `App\Proofgen\ShowClass` | `app/Proofgen/ShowClass.php` | Legacy "show class" wrapper still used by some callers; routes through `PhotoService` |
| `App\Services\GraveyardService` | `app/Services/GraveyardService.php` | Read-only graveyard inspection + the only purge entry point |
| `App\Services\ImageDiskConfigurator` | `app/Services/ImageDiskConfigurator.php` | Applies DB-backed disk root overrides at boot |
| `App\Services\ImportConflictHintService` | `app/Services/ImportConflictHintService.php` | UI-only placement hints for cross-class duplicates (ordinal proximity + EXIF time delta) |
| `App\Services\PathResolver` | `app/Services/PathResolver.php` | All path-shape logic (originals/proofs/web/highres/archive) |
| `App\Services\PhotoArchiveService` | `app/Services/PhotoArchiveService.php` | Archive copy writes + verification + audit + move + repair |
| `App\Services\PhotoAuditService` | `app/Services/PhotoAuditService.php` | Aggregate audit checks + issue persistence |
| `App\Services\PhotoImportIdentityResolver` | `app/Services/PhotoImportIdentityResolver.php` | The pure classifier; returns `PhotoImportPlan` |
| `App\Services\PhotoImportIssueRecorder` | `app/Services/PhotoImportIssueRecorder.php` | Quarantines source + creates `photo_issues` row for resolver-flagged imports |
| `App\Services\PhotoImportPlan` | `app/Services/PhotoImportPlan.php` | Pure data; resolver output |
| `App\Services\PhotoMoveService` | `app/Services/PhotoMoveService.php` | Move single photos between classes; derivative follow-through |
| `App\Services\PhotoService` | `app/Services/PhotoService.php` | The orchestrator — every import call lands here |
| `App\Services\ClassRenameService` | `app/Services/ClassRenameService.php` | Rename whole class folder; bulk directory moves |
| `App\Services\SafeFileMover` | `app/Services/SafeFileMover.php` | The single legitimate "delete" path. `bury()` + `quarantineImport()` + `buryAbsolute()` |

---

## 13. Test surface

The full Phase 1–6 test sweep (sanity check):

```bash
./vendor/bin/pest \
  tests/Feature/PhotoImportResolverMatrixTest.php \
  tests/Feature/PhotoAuditServiceTest.php \
  tests/Feature/PhotoImportIssueTest.php \
  tests/Feature/PhotoIssuesComponentTest.php \
  tests/Feature/ArchiveBackupTest.php \
  tests/Feature/GraveyardComponentTest.php \
  tests/Feature/PhotoMoveServiceTest.php \
  tests/Feature/ImageProcessingWorkflowTest.php \
  tests/Unit/Services/SafeFileMoverTest.php \
  tests/Unit/Services/PhotoImportIdentityResolverTest.php \
  tests/Unit/Services/ImportConflictHintServiceTest.php \
  tests/Unit/Services/GraveyardServiceTest.php \
  tests/Unit/Proofgen/ImageTest.php
```

When this passes, the import/audit/move/quarantine/graveyard pipeline is healthy. Pre-existing pollution-related failures elsewhere in the suite (`AuthenticationTest`, `RegistrationTest`, alias-mock interactions) are unrelated to this pipeline.

---

## 14. Glossary / mental model

- **Source of truth**: the imported original (`originals/{proof}.jpg`) is the canonical file. The archive copy is a verified backup. Derived files (proofs/web/highres) are regenerable from the original.
- **Proof number**: business identity. Show-scoped (Redis pool keyed on show id). Format `{SHOW_UPPERCASE}_{5-digit}`.
- **SHA-1**: file identity. The audit and resolver both treat sha1 as authoritative for "is this the same image?"
- **Original filename**: camera-given basename (e.g. `IMG_02631.jpg`). Captured at import time, used by `ImportConflictHintService` to suggest where a duplicate naturally fits among siblings.
- **Idempotent retry**: same image dropped into ingest folder again. Detected by sha1 + proof# match → repair-only path (no new photo, source buried).
- **Quarantine** vs **Bury** vs **Conflict-aside**: see §1 table. They are NOT interchangeable.
- **The resolver is pure**. It reads bytes and queries the DB. It does not move, write, or delete anything. All side effects happen downstream of it.
- **The graveyard is the only delete path**. If you find yourself reaching for `unlink()` or `Storage::delete()` on an original or archive copy, you're doing it wrong — go through `SafeFileMover`.

---

*Last updated: 2026-05-09. If this doc drifts from the code, the code wins — but please update this doc when you change the pipeline so the next agent doesn't have to reverse-engineer it again.*
