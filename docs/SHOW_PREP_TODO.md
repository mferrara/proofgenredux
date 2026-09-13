# Show-prep follow-ups

Queued on 2026-09-12 after the [core review](reviews/2026-09-11-core-review/README.md). The [file-safety round](reviews/2026-09-12-file-safety.md) and [UI round](reviews/2026-09-12-ui-fixes.md) are implemented and locally validated. The [image-output round](reviews/2026-09-12-image-output-fixes.md) is implemented; the [local end-to-end rehearsal](reviews/2026-09-12-show-rehearsal.md) has also passed.

## Completed: everyday UI fixes

- [x] Fix uncached thumbnail loading in table/grid and the detail modal. Share the existing-JPEG loader and configured proof paths.
- [x] Keep missing-thumbnail state local to each row, so one missing file does not hide subsequent thumbnails.
- [x] Build proof-search labels and redirects from actual show/class relations, including show IDs containing underscores.
- [x] Quote filename/path values correctly in Alpine/Livewire expressions so apostrophes do not break rename/reveal actions. Verify Flux attributes after rendering.
- [x] Reproduce the watermark-preview checkbox's double update in the browser and replace it with one model update and regeneration hook.
- [x] Fix the grid Select all binding being evaluated as a PHP constant; verify selection in the browser.
- [x] Fix the settings preview's endless spinner after generation failure. Repair the local stale font path, show per-tab errors with retry, and test real small/large watermark generation after a missing-font failure.

Acceptance: test uncached and missing thumbnails, underscore show IDs and apostrophe filenames; spot-check table/grid/search flows in the UI. Keep this a correctness pass, not a view/framework rewrite.

## Completed: image-output fixes

- [x] Generate both proof sizes from the same enhanced source without enlarging the small thumbnail.
- [x] Honor the web/highres generation enable switches on normal import.
- [x] Validate the highres watermark before creating the final output; reuse the working web guard and finish composition before the final save.
- [x] Set the final watermarked proof JPEG encode to quality 95 for small/large production proofs and Settings previews, preserving the existing first pass.
- [x] Address remaining configured-quality omissions in previews and paid-image outputs. Single-pass thumbnail encoding and format selection are tracked in Flower #3689.
- [x] Standardize derivative filenames as `.jpg` independently of original `.jpeg` extension, respecting configured suffixes in model checks, moves and UI paths.
- [x] Finish relation-based original/proof paths in `Photo` for underscore show IDs, including the direct image-processing path that can return a photo before its class record exists. Move/reset/archive class paths are addressed in the file-safety round; the broader image-model contract remains here.
- [x] Correct daemon black/white-point percentile units and verify adjustment controls with synthetic images.
- [x] Resolve the divergent Swift/PHP fallback methods; keep only useful paths and make unsupported/fallback adjustments visible rather than silently ineffective.
- [x] Clean up Core Image temporary files on success and failure.

Acceptance: generated-image checks for enhancement consistency, disabled products, missing/corrupt watermark, quality, suffixes and `.jpeg` inputs.

## Completed: local end-to-end rehearsal (#3)

- [x] Compile the updated Core Image binary and refresh the local daemon before testing the new adjustments.
- [x] Rehearse import → adjustments/watermarks → proofs → upload → delivered-image inspection locally: four photo copies, 12 rsync-delivered images, eight successful native renders, 117 assertions. Portrait/landscape, `.jpeg`, all paid-product switch combinations, underscore identity, archive checksums and HTTP-served proof bytes verified. All four live Settings previews loaded. See the rehearsal report for scope and remaining limits.

## Completed: final local show checks — September 13

- [x] Hide `_graveyard` from Home and show pending imports on empty classes.
- [x] Render Settings previews from 45 MP originals, including EXIF portrait rotation.
- [x] Fit long proof-number watermarks inside both proof sizes without changing final quality 95.
- [x] Resolve quarantine replacement, audit filters/projections, and string class target checks through actual show/class records.
- [x] Run a larger NAS-backed rehearsal and measure show-status data collection. See [the final local review](reviews/2026-09-13-show-prep-finish.md) for results and limits.

## Later / separate

- [ ] Rehearse a small class on Dad's MacBook with its actual storage and upload targets before using it for the show.
- [ ] Measure a full show with many classes if polling becomes noticeably slow. The two-class local rehearsal does not justify a performance rewrite.
- [ ] Remove or update the dormant thumbnail branch in `images-table.blade.php` if that legacy pending-file table is retained; current callers do not enable its thumbnail option.
- Thumbnail encoding research: [Flower #3689](https://flower.legitphp.com/briefs/3689), comparing single-pass JPEG, JPEG XL and PNG for new/regenerated thumbnails.
- Updater research: [Flower #3682](https://flower.legitphp.com/briefs/3682), refining/undispatched when created.
- Simple S3 copy-and-switch migration: [Flower #3681](https://flower.legitphp.com/briefs/3681).
- Recognition remains deferred.
