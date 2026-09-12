# Proofgen Redux — Lane Review: Import / Scanning / File Lifecycle / Archive / Reset

**Snapshot:** source at `/private/tmp/proofgen-review-20260911/source`, described parent-side as main `11c6e80`.
**Method:** read/grep/find/ls only. No files modified, nothing executed (no tests, no artisan, no network). All behavior below is from source reading and call-site tracing; performance items are source-only hypotheses unless stated.
**Required reading done:** `CLAUDE_NOTES.md`, `CLAUDE.md`, `docs/photo-pipeline.md`, `docs/archive-backups.md`, `docs/SHOW_PREP_REVIEW.md`. `external-docs/fluxui/index.md` absent from snapshot, so no Flux API claims. S3 migration and updater omitted per brief.

---

## P1 findings

### P1-1 — Show-level "Import" re-quarantines already-quarantined conflict files and duplicates issue rows
- **Paths:** `app/Models/Show.php:334` (`Utility::getContentsOfPath($this->relative_path, true)`), `app/Models/Show.php:343-347` (only `'/originals/'` filtered); `app/Proofgen/Utility.php:26,50-53`; `app/Services/SafeFileMover.php:90-138`; `app/Services/PhotoImportIssueRecorder.php:27-67`.
- **Trigger:** operator clicks the show-level Import button (`app/Livewire/ShowViewComponent.php:368` → `Show::importPendingImages()`), or merely leaves the show page open (`resources/views/livewire/show-view-component.blade.php:1` has `wire:poll.5s`; `ShowViewComponent.php:295` calls `Show::getImagesPendingImport()` on every render).
- **Observed code behavior:** `Show::getImagesPendingImport()` lists the whole show tree **recursively** (`true`) and filters only paths containing `/originals/`. Files under `{show}/{class}/_import_conflicts/*.jpg` do not match that filter, so they are returned as "pending import". Each is dispatched via `ImportPhoto`. The resolver hashes it, finds the identical SHA already in `photos` → `DUPLICATE_CONTENT`, then `PhotoImportIssueRecorder::record()` calls `SafeFileMover::quarantineImport()`, which moves the already-quarantined file again under a new timestamp/SHA name and **creates a fresh `photo_issues` row** (`record()` always inserts). The previous issue's `quarantine_path` now points to a file that no longer exists.
- **Impact:** every show-level import pass multiplies quarantine files, creates duplicate/orphaned issue rows (which `PhotoAuditService::findOrphanQuarantineFiles()` then flags), and displays a bogus pending-import count. No source-of-truth file is destroyed, but the issues inbox and disk hygiene degrade, and the operator gets phantom work. This is reachable via a common button at exactly the horse-show moment.
- **Smallest fix:** in the `Show.php:345` filter, also exclude `/_import_conflicts/` and `/_graveyard/` (and hidden basenames). Better: make `Show::getImagesPendingImport()` aggregate the per-class, non-recursive `App\Models\ShowClass::getImagesPendingImport()` (`app/Models/ShowClass.php:209-219`), which already lists only top-level class files.
- **Verification:** create a class containing one `_import_conflicts/*.jpg`, assert `Show::getImagesPendingImport()` returns `[]`, and assert a second show-level import creates no new `photo_issues` row and no renamed quarantine file. (Not executed.)

---

## P2 findings

### P2-1 — `findOrphanOriginals()` still does a full-disk recursive scan
- **Paths:** `app/Services/PhotoAuditService.php:376` (`Storage::disk('fullsize')->allFiles()`), contrasted with the targeted `classDirectories()` generator at `:210-231`; the docblock at `:119` claims targeted walks.
- **Trigger:** `php artisan proofgen:audit` on an install with many classes/derivatives.
- **Observed code behavior:** `allFiles()` recursively walks the entire fullsize root (originals, proofs, web_images, highres_images, `_graveyard`, every class), then throws away all non-`/originals/` paths.
- **Impact:** unnecessary O(all files) I/O on large installs; audit latency grows with derivative count even though only originals matter. **Source-only performance hypothesis — not measured.**
- **Smallest fix:** iterate `classDirectories()` and scan `Storage::disk('fullsize')->allFiles($classDir.'/originals')` per class.
- **Verification:** compare audit wall time and resulting orphan set on a populated throwaway root before/after; assert identical findings.

### P2-2 — `PhotoMoveService` can silently overwrite an existing destination original (and drops `original_filename`)
- **Paths:** `app/Services/PhotoMoveService.php:124` (`$disk->move($oldOriginal, $newOriginal)`); pre-check only looks for a Photo row at `:52-54`; new-row field copy at `:62-77`.
- **Trigger:** move/relocate a photo into a class where `originals/{proof}.{ext}` already exists on disk with no matching Photo row — precisely the "orphan original" state the audit is designed to detect.
- **Observed code behavior:** Flysystem local `move()` is a rename and overwrites the destination. There is no `exists()` guard or quarantine of the destination. Separately, the new Photo row never copies `original_filename`, so camera-filename metadata is lost on every move.
- **Impact:** potential silent loss of a source-of-truth original, plus loss of metadata used by `ImportConflictHintService`.
- **Smallest fix:** before the move, if `$disk->exists($newOriginal)` route the destination through `SafeFileMover` (or throw); add `$newPhoto->original_filename = $photo->original_filename;`.
- **Verification:** place an orphan at the destination, move a photo, assert the destination was buried rather than overwritten and that `original_filename` survives on the new row. (Not executed.)

### P2-3 — Whole-show recursive listing on every 5-second poll
- **Paths:** `app/Models/Show.php:334`; `app/Livewire/ShowViewComponent.php:295`; `resources/views/livewire/show-view-component.blade.php:1` (`wire:poll.5s`).
- **Trigger:** leaving a show page open during the show.
- **Observed code behavior:** each poll re-runs `listContents(show, true)` over all classes, originals, and conflicts, then filters in PHP. File attributes only, but the entry count is the whole show.
- **Impact:** continuous CPU/I/O on the operator's Mac, competing with import/derivative work. **Source-only hypothesis; not profiled.**
- **Smallest fix:** switch to per-class non-recursive listing (same change as P1-1) and/or cache the pending count briefly; the poll only needs a count and a badge.
- **Verification:** count `listContents` calls / measure render time on a large show before and after. (Not executed.)

### P2-4 — `ResetClassPhotos` resolves the class with `$show->name` instead of `$show->id`
- **Path:** `app/Jobs/ShowClass/ResetClassPhotos.php:44` (`$show->classes()->where('id', $show->name.'_'.$this->class)`), sibling `app/Jobs/ShowClass/ImportClassPhotos.php:46` correctly uses `$show->id`.
- **Trigger:** class reset from `ClassViewComponent.php:491` or `ShowViewComponent.php:506`.
- **Observed code behavior:** if `shows.name` ever differs from `shows.id`, the lookup misses, the job logs "ShowClass not found", and reset silently does nothing while the UI already toasted "Photos queued to reset".
- **Impact:** destructive maintenance action silently no-ops; operator believes the class was reset. **Uncertainty:** I could not confirm the `shows` schema — the snapshot has no `database/` directory — and `HomeComponent.php:159` sets `name = id` at creation, so on app-created shows they usually match. Treat as latent/config-dependent.
- **Smallest fix:** use `$show->id.'_'.$this->class`.
- **Verification:** unit-test the job with `name !== id`; assert the class is found and reset runs.

---

## P3 findings

### P3-1 — `PhotoService::handleIdempotentRetry` uses `Log::debug` without importing the facade
- **Path:** `app/Services/PhotoService.php:166`; no `use Illuminate\Support\Facades\Log;` in the file.
- **Trigger:** an idempotent re-import (`IDEMPOTENT_EXISTING`) with `$debug = true`.
- **Observed code behavior:** inside `namespace App\Services`, unqualified `Log` resolves to `App\Services\Log`, which does not exist → fatal error mid-retry (after archive repair / `original_filename` backfill, before `bury()`). Default callers pass `debug: false` (`ImportPhoto::handle()`), so it is latent.
- **Impact:** debug-mode retries crash and leave the duplicate source unburied; can also abort a repair path mid-way.
- **Smallest fix:** add the `Log` facade import (or use `logger()`).
- **Verification:** unit-test `handleIdempotentRetry` with debug on, or static check for the missing import. (Not executed.)

### P3-2 — `ShowClass::importPendingImages()` references an undefined `$photo`
- **Path:** `app/Models/ShowClass.php:230` (`if (isset($photo->is_new) && $photo->is_new === true)`); `$queued` is never populated.
- **Trigger:** `ImportClassPhotos` job (`app/Jobs/ShowClass/ImportClassPhotos.php:50-53`) after the show-level per-class import button.
- **Observed code behavior:** jobs are dispatched correctly, but `$queued` always returns `[]`; the job's "Queued N photos" log never fires. `isset()` masks the undefined variable rather than erroring.
- **Impact:** misleading/absent import reporting; dead branch suggesting a synchronous import that no longer exists.
- **Smallest fix:** return a count of dispatched jobs (or the dispatched paths) and delete the `$photo` branch.
- **Verification:** job/unit test asserting the returned count equals the number of images found. (Not executed.)

### P3-3 — PNG/TIFF/HEIC/RAW in the ingest folder are neither imported nor flagged
- **Paths:** `app/Services/PhotoAuditService.php:108` (`IMAGE_EXTENSIONS` includes png/tif/heic/raw), `:126-129` (skips them as "pending import"); `app/Proofgen/Utility.php:50-53` (discovery only collects paths containing `jpg`/`jpeg`).
- **Trigger:** operator drops a `.png`, `.heic`, `.tif`, or camera RAW file into a class folder.
- **Observed code behavior:** discovery never returns them (not jpg/jpeg), and the audit's straggler check deliberately suppresses them as if they will be imported. They are silently invisible.
- **Impact:** honest operator mistake (wrong export format) produces no warning and no processing; operator may believe the class is fully imported. No data loss.
- **Smallest fix:** either add supported extensions to discovery, or narrow `IMAGE_EXTENSIONS` to what discovery actually handles so the rest are flagged as stragglers.
- **Verification:** drop a `.png` in a class folder and assert the audit reports a straggler (or discovery picks it up). (Not executed.)

### P3-4 — Legacy `App\Proofgen\ShowClass` retains an unguarded Redis allocator and a wrong-arg regen path
- **Paths:** `app/Proofgen/ShowClass.php:281-283` (`return $proof_number;` with a `: string` return type, no false/empty guard) and `:200` (`GenerateThumbnails::dispatch($this->show_folder.'_'.$this->class_folder, ...)` — a class id passed where `PhotoService::generateThumbnails` does `Photo::find($photo_id)`).
- **Call-site trace:** grep found no app callers of `App\Proofgen\ShowClass::getNextProofNumber()`, `regenerateProofs()`, or `regenerateWebImages()`; the live paths use `App\Models\Show::getNextProofNumber()` (which has the guard) and `App\Models\ShowClass::regenerateProofs()`. `processPendingImages/getImportedImages/getImagesPendingProcessing/getImagesPendingWeb` are still live (`ShowViewComponent.php:263-265`, `ClassViewComponent.php:245`).
- **Observed code behavior:** dead-but-callable methods remain; the allocator would TypeError on an empty Redis pop, and the regen path would dispatch a job with an invalid photo id.
- **Impact:** future caller or copied snippet reintroduces a fixed bug. Low current impact.
- **Smallest fix:** delete the dead legacy methods (or delegate to the model methods) so there is one allocator and one regen path.
- **Verification:** `grep` for usages after deletion; run the existing suite. (Not executed.)

---

## Consolidation opportunities

1. **Three overlapping import-discovery paths.** `App\Proofgen\ShowClass::processPendingImages()` (`app/Proofgen/ShowClass.php:230-243`, used by `ClassViewComponent.php:245`), `App\Models\ShowClass::importPendingImages()` + `ImportClassPhotos` (`app/Models/ShowClass.php:221-234`, used by `ShowViewComponent.php:364`), and `Show::importPendingImages()` (`app/Models/Show.php:353-368`). Each re-implements "list ingest files and dispatch `ImportPhoto`" with different recursion/filtering and different return shapes. Consolidating on one per-class non-recursive discovery that excludes `_import_conflicts`/`_graveyard` fixes P1-1 and P3-2 together and makes the count trustworthy.
2. **Legacy `App\Proofgen\ShowClass` path/allocator methods duplicate `App\Models\ShowClass` + `Show`.** `getNextProofNumber`, `regenerateProofs`, `regenerateWebImages` are unused by the app and carry stale assumptions (wrong job argument, missing Redis guard). Pointing remaining live callers at the model methods removes a second source of truth for proof-number allocation.
3. **Hardcoded tree prefixes in `ClassRenameService`.** `app/Services/ClassRenameService.php:104-105,113-114,122-123,167-169` embed `'proofs/'`, `'web_images/'`, `'highres_images/'`, while `PathResolver` owns the same layout everywhere else. Routing these through `PathResolver` means a future layout change (or a new tree) can't silently diverge between rename and normal operation.

---

## Covered files

- Models/jobs: `app/Models/Show.php`, `app/Models/ShowClass.php`, `app/Models/Photo.php`, `app/Jobs/Photo/ImportPhoto.php`, `app/Jobs/ShowClass/ImportClassPhotos.php`, `app/Jobs/ShowClass/ResetClassPhotos.php`
- Services: `app/Services/PhotoService.php`, `app/Services/PhotoImportIdentityResolver.php`, `app/Services/PhotoImportIssueRecorder.php`, `app/Services/PhotoArchiveService.php`, `app/Services/PhotoMoveService.php`, `app/Services/ClassRenameService.php`, `app/Services/SafeFileMover.php`, `app/Services/PhotoAuditService.php`, `app/Services/PathResolver.php`
- Legacy/utility: `app/Proofgen/Image.php`, `app/Proofgen/ShowClass.php`, `app/Proofgen/Utility.php`
- Console/Livewire/views touched for call-site tracing: `app/Console/Commands/AuditPhotoArchivesCommand.php`, `app/Livewire/ShowViewComponent.php`, `app/Livewire/ClassViewComponent.php`, `app/Livewire/PhotoIssuesComponent.php`, `app/Livewire/HomeComponent.php`, `resources/views/livewire/show-view-component.blade.php`, `resources/views/livewire/class-view-component.blade.php`, `resources/views/livewire/home-component.blade.php`, `resources/views/components/partials/action-panel.blade.php`
- Docs: `CLAUDE_NOTES.md`, `CLAUDE.md`, `docs/photo-pipeline.md`, `docs/archive-backups.md`, `docs/SHOW_PREP_REVIEW.md`

## Gaps / limits

- **No `database/` directory in the snapshot**, so I could not confirm the `shows`/`photos` schema (notably whether `shows.name` can differ from `id` for P2-4, and `photo_issues` columns for P1-1).
- **No execution:** no Pest, no artisan, no Tinker, no network/rsync; all verification steps are proposed, not performed. No claim that tests pass or that these bugs reproduce.
- **Performance claims (P2-1, P2-3) are source-only hypotheses**, not measurements.
- **Excluded by brief:** S3 migration, updater research, upload transport reliability (covered by the separate SHOW_PREP_REVIEW work), Flux UI API questions (docs unavailable), recognition.
- **Not deeply reviewed:** `StorageUsageService` scanning, `ImportConflictHintService`, `ImageDiskConfigurator`, and the gd/Intervention derivative writers beyond the import/identity boundary.
