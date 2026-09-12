# Show-prep follow-ups

Queued on 2026-09-12 after the [core review](reviews/2026-09-11-core-review/README.md). The [file-safety round](reviews/2026-09-12-file-safety.md) and [UI round](reviews/2026-09-12-ui-fixes.md) are implemented and locally validated. The [image-output round](reviews/2026-09-12-image-output-fixes.md) is implemented; the end-to-end rehearsal is next.

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

## Next: end-to-end rehearsal (#3)

- [ ] Compile the updated Core Image binary and refresh the local daemon before testing the new adjustments.
- [ ] Rehearse import → adjustments/watermarks → proofs → upload → delivered-image inspection using a small representative batch. Include portrait/landscape, `.jpeg`, enabled/disabled paid products, and an underscore show ID. Inspect watermark readability and exposure before generating a whole show. This has not been performed in the image-output round.

## Later / separate

- [ ] Finish the separate underscore-ID fallbacks in quarantine replacement (`PhotoIssuesComponent`), the audit show-ID projection (`PhotoAuditService`), and the string-only class verifier (`FerraraphotoTargetVerifier`). The `Photo` derivative/import/move paths are fixed.

- [ ] Measure polling and image-processing cost on a representative show before further performance refactoring. Consolidate duplicate code only where it removes a demonstrated bug or worthwhile cost.
- [ ] Remove or update the dormant thumbnail branch in `images-table.blade.php` if that legacy pending-file table is retained; current callers do not enable its thumbnail option.
- Thumbnail encoding research: [Flower #3689](https://flower.legitphp.com/briefs/3689), comparing single-pass JPEG, JPEG XL and PNG for new/regenerated thumbnails.
- Updater research: [Flower #3682](https://flower.legitphp.com/briefs/3682), refining/undispatched when created.
- Simple S3 copy-and-switch migration: [Flower #3681](https://flower.legitphp.com/briefs/3681).
- Recognition remains deferred.
