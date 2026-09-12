# Core functionality review — 2026-09-11

Reviewed source: `main` at `11c6e80`. Four Pi agents used **deepseek/deepseek-flash**, high reasoning, with independent parent source review and synthetic reproduction of five findings. This is a review, not an implementation change.

**Follow-up, 2026-09-12:** the [file-safety round](../2026-09-12-file-safety.md) implements overwrite refusal, failed-reset record handling and ingest filtering. UI and image-output findings remain [queued](../../SHOW_PREP_TODO.md). The findings, source line numbers and probe output below describe the original reviewed commit; the probe is historical reproduction code, not a current acceptance test.

## Fix first

| Priority | Finding and trigger | Evidence | Smallest useful change |
|---|---|---|---|
| P1 | Moving a photo can overwrite an orphan original already at the destination. | `app/Services/PhotoMoveService.php:52` checks only the database; `:124` moves onto the destination without a disk collision check. Parent invoked the actual private file-moving method on temporary files: destination contents changed from `ORPHAN_DESTINATION` to `SOURCE`; the source path disappeared. | Check the destination before moving; refuse the move with a clear message when it conflicts. Preserve `original_filename` when creating the replacement row. |
| P1 | Opening table/grid with an uncached thumbnail can fail. | `resources/views/components/partials/photos-table.blade.php:105` and `photos-grid.blade.php:98` call `Image::read()`. Installed Intervention v4 exposes `decodeBinary()`, not `read()`. Parent reproduced `Error: Call to undefined method Intervention\Image\ImageManager::read()`. The view catches `Exception`, which does not catch this `Error`. | Use the current API, or simply serve/base64 the existing JPEG without re-encoding it. Fix the common thumbnail loader once. |
| P1 | Show-level import discovers quarantined files and their JSON sidecars as new input. | `app/Models/Show.php:334` recursively lists the show, excluding only `/originals/`. `app/Proofgen/Utility.php:50` matches `jpg`/`jpeg` anywhere in a path. Parent's actual discovery returned both `_import_conflicts/conflict.jpg` and `conflict.jpg.json`. | Share one nonrecursive per-class ingest discovery path; filter by actual extension and exclude housekeeping directories. |
| P1 | Reset deletes photo records even when releasing originals failed. | Parent addition: `app/Models/ShowClass.php:351` catches release failures, then `:365` fetches/deletes all class photos regardless of those failures. `releaseOriginal()` also does not check move return values. This is source-confirmed, not a runtime reset test. | Delete a row only after its corresponding reset file operations succeed. Check move results and report the failures. No generalized transaction/rollback framework needed. |
| P2 | Small and large proofs disagree when enhancement is enabled. | `app/Proofgen/Image.php:501` enhances the small proof; `:532` reloads the original for the large proof. Parent injected a red enhanced image over a blue original: small output was red `[254,0,0]`, large was blue `[0,0,254]`. | Derive both sizes from independent copies of the same enhanced image. Do not enlarge the already reduced small thumbnail. |
| P2 | Failed high-resolution watermarking leaves an unfinished file in the output directory. | `app/Proofgen/Image.php:373` saves before loading the watermark at `:380`. Parent reproduced missing-watermark failure with `output_exists=true`. `Photo::checkPathForHighresImage()` can recognize an existing file later. | Apply the web path's early watermark validation to highres. Finish composition before writing the final output. This is output correctness, not a new security control. |
| P2 | Import ignores the web/highres generation switches. | `app/Services/PhotoService.php:115` dispatches all three generation jobs whenever `dispatchJobs` is true. Neither product job checks its enable switch; manual regeneration paths do. | Apply the same enable checks during import; cover both disabled-product cases. |

The first four deserve attention before bulk move/reset/import operations or show use. The next three are small, concrete image-pipeline corrections. None needs a new subsystem.

## Correctness and consistency next

1. **Quality controls do not reach the final encode.** Production initially saves at configured quality, then watermarking uses bare `save()` (`Image.php:316,388,526,545,559`). Installed `JpegEncoder` defaults to **75**, and `Image::save()` creates a new encoder from supplied options. Preview computes `$quality` but never supplies it (`ConfigComponent.php:1195-1208,1229`). Compose in memory and encode once at the chosen quality. Correction to an agent's wording: the initial encode still affects pixels and potentially size; outputs at different settings are not necessarily identical.

2. **Adjustment implementations have diverged.** The UI offers `adjustable_auto_levels` and `advanced_tone_mapping`. The daemon handles them; the stdin Swift tool's method switch does not, so that fallback throws and falls through to PHP. PHP's auto-level path ignores the adjustable parameters, and its tone-mapping path returns the input unchanged without Imagick. See `EnhancementServiceFactory.php:22-41`, `CoreImageEnhancementService.php:101`, `ImageEnhancementService.php:33-46,176-215`, and both Swift switches. Decide whether to keep the stdin fallback; either align the supported settings or remove that extra implementation and accurately report fallback behavior.

3. **Daemon black/white point units are wrong.** Parent addition, source-confirmed: `ProofgenImageEnhancerDaemon.swift:118-122` divides 0–100 settings by 100 before passing them to `calculatePercentileStats()`, which divides by 100 again at `:389-390`. A white point of 99 becomes the 0.99th percentile instead of the 99th. Fix the units and check output with a synthetic gradient before relying on these controls. This was not GPU-executed in this review.

4. **`.jpeg` originals still have inconsistent derivative paths.** Generation writes `.jpg`, but `Photo.php:216-226,344` and `PhotoMoveService.php:139` derive proof extensions from the original's `file_type`. Table/grid also replace literal `.jpg` and hardcode `_thm`. This can miss, fail to delete, or fail to move existing proofs. Use `PathResolver`/one derivative-name helper throughout; include configurable suffixes and `.jpeg` originals.

5. **Proof-search links break for show IDs containing underscores.** `ProofSearchComponent.php:66-73` and its view split a composite ID at the first underscore. Show creation permits underscores. `PhotoMoveService::showClassParts()` uses the same parsing assumption. Use the actual class relation's `show_id` and `name`.

6. **One missing thumbnail hides later table thumbnails.** The table mutates the shared `$display_thumbnail` option inside its loop (`photos-table.blade.php:110`); a missing file can turn off all following rows. Use a separate per-row flag. This remains relevant after fixing the removed image API.

7. **Apostrophes in folder names break rename/reveal expressions.** `show-view-component.blade.php:112-115,206-209`, `home-component.blade.php:143`, and `partials/path-row.blade.php:58` interpolate strings into Alpine/Livewire expressions. HTML escaping does not produce a JavaScript string literal. Use the existing `@js` convention for honest filenames such as `Dad'sClass`; this is basic UI correctness, not hostile-user hardening.

## Optimization and consolidation

These are source-supported opportunities, not measured performance defects. Profile a representative show before committing to a larger change.

- **Reduce repeated work during five-second polling.** Show rendering recursively discovers pending files, scans class directories and repeatedly counts relations. Class rendering loads all photos/metadata and builds their thumbnail content. Home loads full photo collections merely to count them. Start with `withCount`/aggregate counts and shared count results; then measure whether status-only polling or pagination is needed. Do not declare a P1 latency incident from source estimates alone.
- **Use one thumbnail loader and one preview/production image composition path.** Table/grid/legacy image-table loaders duplicate path construction, caching and image encoding. Preview and production duplicate resizing/watermark composition with actual behavioral drift. These are worthwhile consolidations because they eliminate demonstrated bugs.
- **Use one import discovery implementation.** The legacy Proofgen class, Eloquent class and show-level import implement overlapping discovery/dispatch behavior. Keep one definition of an eligible ingest file and derive both counts and queued work from it.
- **Avoid unnecessary image work.** The watermark color decision decodes and visits the bottom 20% of every derivative pixel; a small sampled region should be sufficient if visual checks agree. Current image pipelines also repeatedly write/decode JPEG intermediates. Measure after the correctness fixes. GD objects are reclaimed when references go out of scope; lack of `imagedestroy()` alone does not prove a persistent leak on current PHP.
- **Remove concrete temp-file leaks.** Both Core Image PHP clients append `.jpg` to `tempnam()` and leave the original empty file. Their exception paths can leave the output too. Use one temporary path and `finally` cleanup.
- **Remove truly unused legacy methods once call sites are accounted for.** The legacy class still has live discovery callers, so deleting the entire class immediately is inappropriate. Unused allocator/regen methods and unreachable enhancement methods can be pruned individually.

## Parent adjudication and limits

- **Rejected:** missing explicit PHP EXIF orientation is not an established bug. Installed `Config.php` defaults `autoOrientation=true`; GD's `FilePathImageDecoder.php:79` applies `OrientModifier`. Old TODOs are stale. A real orientation fixture remains useful when changing the pipeline, but no extra rotation should be added blindly.
- **Rejected:** the daemon client is not demonstrably capped at ten seconds. `fread()` is blocking with a 30-second stream timeout; `100 × usleep(0.1s)` is not its total duration. A proper deadline may be worth checking, but the agent's specific timeout diagnosis/fix is wrong.
- **Corrected:** merely opening/polling a show lists quarantined files; it does not import them. Clicking Import queues them. Re-quarantine moves the image and can leave stale sidecars/issues; it does not necessarily multiply image copies. The reproduced discovery of JSON sidecars is an additional failure path.
- **Not promoted to confirmed fixes:** duplicate AppStatusBar listeners, watermark checkbox event ordering, config fallback booleans and show name versus ID assumptions need targeted follow-up. Do not turn these into a broad framework-upgrade rewrite.
- Minor source issues retained in raw reports include missing `Log` import on a debug-only retry, a dead `$photo` branch in class import reporting, full-disk orphan audit scanning, and unsupported ingest formats being hidden by the audit. They are lower priority than the accepted findings above.
- No live application/browser session, real photo collection, database, queue, SFTP endpoint or bucket was exercised. No full test suite was run for this review. The parent probe uses generated images and temporary files, without loading the app environment or booting its providers. The move reproduction covers the actual file-moving method, not a full database transaction.
- Agents had an allowlisted tracked-source snapshot, no database directory, vendor, credentials, `.env`, photos, or watermark/font assets. Flux's referenced external documentation is absent. Pixel-perfect watermark geometry and real-world throughput therefore remain unverified.

## Suggested implementation order

1. File correctness: overwrite refusal, failed-reset record handling, ingest filtering.
2. UI correctness: current thumbnail API/shared loader, row-local state, relation-based links and filename quoting.
3. Image correctness: shared enhanced source, enable switches, watermark validation, final quality, derivative paths, adjustment units/fallback behavior.
4. Measure polling and image processing; perform only the consolidations that remove duplication or demonstrable cost.

The separate updater research is [Flower brief #3682](https://flower.legitphp.com/briefs/3682), refining and undispatched. S3 remains separate in #3681; recognition remains deferred.

## Evidence artifacts

- `parent-probe.php` and `parent-probe-output.txt`: reproducible temporary-file checks.
- `agent-receipts.json`: exit codes, actual session/model metadata and source SHA.
- `raw-views-livewire.md`, `raw-file-handling.md`, `raw-image-adjustments.md`, `raw-watermarking.md`: original agent reports. They include unverified/rejected claims; this parent-reviewed summary governs the recommendations.
- Full Pi sessions/prompts and allowlist manifest: `/tmp/proofgen-review-20260911/` (temporary artifacts, not committed).
