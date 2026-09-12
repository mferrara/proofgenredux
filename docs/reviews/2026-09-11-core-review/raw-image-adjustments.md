# Image Enhancement & Derivative Generation Review — main 11c6e80

Scope: adjustment correctness/order, preview vs generated consistency, encoding/orientation, dimensions, resource lifecycle, repeated processing, consolidation. Source-only; nothing was executed and no tests were run. Watermark product decisions, S3, and updater are out of lane.

## Covered files (read)
- `app/Proofgen/Image.php` (all)
- `app/Services/ImageEnhancementService.php`, `CoreImageEnhancementService.php`, `CoreImageDaemonService.php`, `SwiftCompatibilityService.php` (partial), `PhotoService.php`
- `app/Helpers/EnhancementServiceFactory.php`
- `app/Services/CoreImage/ProofgenImageEnhancer.swift`, `ProofgenImageEnhancerDaemon.swift`
- `app/Jobs/Photo/GenerateThumbnails.php`, `GenerateWebImage.php`, `GenerateHighresImage.php`
- `app/Livewire/ConfigComponent.php` (preview/adjustment plumbing, lines ~1000–1560)
- `config/proofgen.php`, `app/Console/Commands/CoreImageDaemonCommand.php`, `app/Providers/ConfigurationServiceProvider.php`
- Tests only as coverage evidence: `tests/Unit/Proofgen/ImageWebWatermarkTest.php`, `tests/Unit/Services/ImageEnhancementServiceTest.php`

## Findings

### P1 — Large proof thumbnail is generated without enhancement while the small one and the preview are enhanced
- **file:line**: `app/Proofgen/Image.php:501-504` (small path uses enhanced `$image`) vs `app/Proofgen/Image.php:532` (`$image = $manager->decodePath($full_system_path);` for large).
- **Trigger**: `proofgen.image_enhancement_enabled` and `enhancement_apply_to_proofs` on, any proof generation (`PhotoService::generateThumbnails` → `Image::createThumbnails`).
- **Observed code behavior**: When enabled, `createThumbnails` stores the enhanced result in `$image` and saves the **small** proof from it, then `unset($image)` and **re-decodes the original file** for the **large** proof (line 532), scaling/saving that original. The ConfigComponent large-tab preview (`ConfigComponent::createPreviewThumbnail`) *does* run the enhancement service. So preview ≠ generated large proof, and small ≠ large proof from the same photo.
- **Impact**: Operator tunes enhancement, sees it in preview and in the small proof, but the customer-facing large proof is unenhanced. This is the clearest preview/generated inconsistency in the lane.
- **Smallest fix**: Produce both sizes from one enhanced `$image` before unhooking it (move the large scale/save up next to the small one and watermark both after), or re-run `EnhancementServiceFactory::getService('thumbnails')->enhance(...)` on the second decode. The first option also removes a redundant full decode+enhance per proof.
- **Verification**: With enhancement on, generate one proof from a low-contrast synthetic JPEG and assert the large proof differs from a no-enhancement render / is byte-identical to a preview equivalent; or assert `small` and `large` were both derived from the enhanced pipeline. Do not treat the existing `assertNotNull` tests as coverage (see P3).

### P1/P2 — Configured JPEG quality is discarded on the final watermarked write (and in previews)
- **file:line**: `Image.php:302-303` then `:316`; `:373-374` then `:388`; `:517-518` then `:526`; `:533-534` then `:545,557-559`. Preview: `app/Livewire/ConfigComponent.php:1206-1208` and `:1227-1229`.
- **Trigger**: Any change to `web_images.quality`, `highres_images.quality`, `thumbnails.*.quality`; also normal generation.
- **Observed code behavior**: The scale/save passes `quality:` on the first write, but then the code re-decodes that file and re-inserts the watermark with a bare `->save()` (no `quality:`) on the same path, overwriting the first encode. Preview does `$image->scale(...)->save($destPath)` with the configured `$quality` variable computed but never passed. Whatever Intervention's default encoder quality is, the configured value does not reach the file that remains on disk.
- **Impact**: The "Quality" setting is effectively non-functional; changing it produces no file-size/quality change (the preview reports size via `getFileInfo`, so the mismatch is visible to the operator). JPEG content is also encoded twice (scale save, then watermark save), which is unnecessary generation loss.
- **Smallest fix**: Pass `quality: (int) config('proofgen.<type>.quality')` (and the per-size value for thumbnails) on the final watermark `save()` calls, and pass `$quality` on both preview saves. Avoid the intermediate write if feasible.
- **Verification**: Generate one web image at quality 30 and one at 95 and compare `filesize()`; add a unit test asserting a low-quality render is smaller than a high-quality one. Currently `ImageWebWatermarkTest` only checks dimensions.

### P2 — Highres watermark is unvalidated and uncleaned; can leave an unwatermarked paid file and crash after writing
- **file:line**: `app/Proofgen/Image.php:380` (`$watermark = imagecreatefrompng(...)`), used at `:386-388`; contrast the hardened web path at `:289-320`.
- **Trigger**: `storage/watermarks/web-image-watermark-2.png` missing/corrupt/undecodable while generating a highres image.
- **Observed code behavior**: Highres scales and writes the output at `:373-374`, then loads the watermark with no `is_file`/`is_readable`/GdImage check, no `try/finally`, and no `imagedestroy`. A false watermark hits `imagefilter()`/`insert()` and raises a TypeError on PHP 8.4.
- **Impact**: The same failure mode the web path was explicitly fixed for (SHOW_PREP_REVIEW records the web watermark guard): the scaled highres file is already on disk unwatermarked and the job fails. Highres is a deliverable/paid artefact, so this is a real leak rather than just a confusing error. SHOW_PREP_REVIEW already lists "highres watermark handling" as follow-up, which matches this.
- **Smallest fix**: Mirror the web guard (validate decode before scaling/writing; throw a clear RuntimeException naming output and watermark) and wrap insertion in `try/finally { imagedestroy($watermark); }`.
- **Verification**: Temporarily rename the watermark and run `GenerateHighresImage`; assert no output file and a watermark-specific exception, analogous to `ImageWebWatermarkTest`'s missing/corrupt cases.

### P2 — The stdin/stdout Swift enhancer doesn't implement the two methods the UI actually offers
- **file:line**: `app/Services/CoreImage/ProofgenImageEnhancer.swift:75-98` (switch handles `basic_auto_levels`, `percentile_clipping`, `percentile_with_curve`, `clahe`, `smart_indoor`) vs daemon `ProofgenImageEnhancerDaemon.swift` switch which handles `adjustable_auto_levels` and `advanced_tone_mapping`. Caller/fallback: `CoreImageEnhancementService.php:90-152` (`enhance()` catches and calls `parent::enhance()`, with only an `error` log) and factory order `EnhancementServiceFactory.php:22-41`.
- **Trigger**: `CoreImageDaemonService` unavailable (daemon down / binary missing) but `swift` present, and the operator selected `adjustable_auto_levels` or `advanced_tone_mapping` (the only two methods `ConfigComponent` validation allows, `ConfigComponent.php:660`).
- **Observed code behavior**: PHP sends the method; the Swift stdin tool throws `unknownMethod`; `CoreImageEnhancementService::enhance()` logs and silently delegates to `ImageEnhancementService` (GD/Imagick). The "GPU accelerated" path therefore never applies the configured method on this fallback.
- **Impact**: Enhancement output silently changes between preview and generation if daemon availability flips, and on a Mac without the daemon the two supported methods are computed by the much slower/softer GD path. Source-read only; the failure ordering is inferred from the switch, not executed.
- **Smallest fix**: Add the two cases to `ProofgenImageEnhancer.swift` mirroring the daemon (and align parameter keys), or remove `CoreImageEnhancementService` from the factory fallback chain if the stdin tool is deprecated.
- **Verification**: Feed one JSON request for each current method to the stdin tool and assert `success:true`; add a service test that `enhance('adjustable_auto_levels', …)` does not log a fallback.

### P2 (uncertain) — No explicit EXIF orientation step anywhere in the PHP derivative pipeline
- **file:line**: `app/Proofgen/Image.php:256-262`, `:482-485` (TODO comments where `orientate()` was removed) and the decode calls at `:273`, `:364`, `:504`, `:532`. The Swift paths *do* orient explicitly: `ProofgenImageEnhancer.swift:344-355`, `ProofgenImageEnhancerDaemon.swift` `loadImage`.
- **Trigger**: Any camera JPEG with a non-1 EXIF `Orientation`, when processing through the GD/Intervention paths.
- **Observed code behavior**: The PHP paths decode and re-encode to JPEG, stripping EXIF, without any visible orientation call; the snapshot's own TODO says the previous `orientate()` call was removed and behavior is unverified. Whether the installed Intervention Image 4 decoder auto-applies orientation cannot be determined here.
- **Impact (conditional)**: If the decoder does not auto-orient, portrait-tagged originals produce rotated proofs/web/highres. That is a show-blocking correctness issue for a horse show, and the check is cheap. **Marked uncertain** — I am not asserting the decoder's behavior, and `vendor/` is absent from the snapshot so I could not read it.
- **Smallest fix (after verifying)**: If orientation is not applied, call the v4 orientation API immediately after decode in `createThumbnails`/`createWebImage`/`createHighresImage` (and in the preview path) and delete the stale TODOs.
- **Verification**: Create a JPEG with `Orientation=6` (or shoot one portrait), run proof/web/highres generation, and compare rendered dimensions to `PhotoMetadata`'s `orientation` classification (`app/Models/PhotoMetadata.php:160-176`). If correct, replace the TODO with a one-line note.

### P3 — CoreImage temp files leak (`tempnam(...)` + `.jpg`) and are not cleaned on the failure path
- **file:line**: `app/Services/CoreImageDaemonService.php:243` (unlink only at `:270`), `app/Services/CoreImageEnhancementService.php:112` (unlink only at `:137`).
- **Trigger**: Every enhanced image through either CoreImage service.
- **Observed code behavior**: `tempnam(sys_get_temp_dir(), 'coreimage_enhance_').'.jpg'` creates a real file **without** the `.jpg` suffix and returns a path with that suffix appended; the originally-created file is never unlinked. On any exception/daemon-fallback path, the `.jpg` file is also not unlinked.
- **Impact**: One orphaned temp file per enhanced photo plus leak under failure; `/tmp` clutter over a multi-thousand-shot show. Local desktop, so low severity, but trivially avoidable.
- **Smallest fix**: `$base = tempnam(...); $tempPath = $base.'.jpg'; @unlink($base);` and wrap request/response handling in `try/finally { if (is_file($tempPath)) unlink($tempPath); }`.
- **Verification**: Count `sys_get_temp_dir()/coreimage_enhance_*` before/after a small batch and assert no leftovers.

### P3 — `advanced_tone_mapping` is a silent no-op when ImageMagick is unavailable
- **file:line**: `app/Services/ImageEnhancementService.php:176-215` (`applyPercentileClipping`; the `if ($this->imagickAvailable)` block is skipped and `return $image;` at the end), routed from `:40-46`.
- **Trigger**: CoreImage unavailable (both services) and `imagick` not loaded, with the UI-permitted `advanced_tone_mapping` method.
- **Observed code behavior**: The image is returned unchanged with no log and no error surfaced. `ConfigComponent::createPreviewThumbnail` then shows an unchanged image while reporting `enhancement.enabled = true`. (Basic auto-levels does have a manual fallback; tone mapping does not.)
- **Impact**: Operator believes tone mapping is active; previews and generated files are both unenhanced. Unlike the GD path, the daemon implements this method, so behavior also depends on which host/service is active.
- **Smallest fix**: Log a warning when `imagick` is missing, and either implement the manual percentile fallback or return a surfaced error so the preview can show "not applied".
- **Verification**: Run with `imagick` disabled, call `enhance(..., 'advanced_tone_mapping')`, and assert the result is unchanged **and** a warning/error status is produced; today `ImageEnhancementServiceTest` only asserts non-null.

### P3 (performance hypothesis) — Daemon response wait is capped at 10s while the configured socket timeout is 30s
- **file:line**: `app/Services/CoreImageDaemonService.php:20` (`$timeout = 30.0`), `:295` (`stream_set_timeout`), `:308` (`$maxAttempts = 100; // 10 seconds max`).
- **Trigger**: A single CoreImage enhancement whose daemon-side processing exceeds ~10s (large camera files or advanced tone mapping), possibly amplified by Horizon concurrency >1 serialized behind the daemon's single main-queue connection handler.
- **Observed code behavior**: PHP stops reading after 100 × 0.1s regardless of `$timeout`, throws `Invalid or incomplete response from daemon`, and falls back to the GD service; the daemon continues the abandoned request on its main queue, delaying the next client.
- **Impact**: On a busy show import, large images could silently drop to the slow GD path or hit job timeouts, while the daemon serializes work. **Source-only hypothesis** — no timings were measured, and actual per-image daemon times are unknown.
- **Smallest fix**: Derive the loop bound from `$this->timeout` (e.g. `max(1, (int) ($this->timeout * 10))`) so the documented timeout is the real one, and consider whether the daemon should handle connections concurrently.
- **Verification**: Log daemon `processingTime` on real camera-size files and compare against the 10s cap; or temporarily make the daemon sleep and confirm the client honors the configured timeout.

## Consolidation opportunities

1. **Two divergent Swift enhancers** — `app/Services/CoreImage/ProofgenImageEnhancer.swift` and `ProofgenImageEnhancerDaemon.swift` duplicate `EnhancementRequest`/`EnhancementResponse`, `loadImage`/`saveImage`, histogram, and the enhancement algorithms, but their method switches and parameter names have already drifted (the P2 missing-methods bug). Extracting a shared enhancement core with two thin entry points (stdin vs TCP) would make that class of drift impossible and halve the surface to update when a method changes.

2. **Duplicated scale → save → watermark pipeline** — near-identical blocks in `Image::createWebImage` (`Image.php:302-324`), `createHighresImage` (`:373-390`), `createThumbnails` (`:517-562`), plus a second copy of the same watermark logic in `ConfigComponent::applyWatermarkToPreview`/`applyWebImageWatermark`/`applyHighresImageWatermark` (`ConfigComponent.php:1206-1235`, `1484-1545`). A single helper (enhance → scale → save at configured quality → guarded watermark) would fix the quality/guard findings once and keep preview and production watermark placement from diverging.

3. **Legacy/unreachable enhancement methods** — `applyPercentileWithCurve` (`ImageEnhancementService.php:217`), `applyCLAHE` (`:249`), `applySmartIndoorEnhancement` (`:284`), and `getAvailableMethods` (`:354`) are unreachable via `enhance()` (its switch handles only the four modern names), yet are listed as "available" and are only referenced by `tests/Unit/Services/ImageEnhancementServiceTest.php:52-90`, which merely asserts `assertNotNull` even when the `default:` branch returns the input unchanged. Deleting the dead methods/tests (and the matching Swift cases if the methods are retired) removes vacuous coverage and the false impression that CLAHE/smart-indoor are selectable.

## Gaps
- `vendor/` is not present in the snapshot, so Intervention Image 4 decoder/encoder behavior (`decodePath` auto-orientation, default JPEG quality when `quality:` is omitted, `scale()` semantics for upscaling) could not be read; the orientation and quality findings are stated at the code level only, with uncertainty marked.
- I did not read `docs/photo-pipeline.md` in depth or the preview blade templates, so whether the UI hides `*PreviewUnenhanced` when enhancement is disabled/unavailable (a potential broken-image edge at `ConfigComponent.php:1032-1065`) is unverified.
- Watermark product semantics, S3 migration, and updater paths were intentionally left to their lanes.
- No code was executed, no tests were run, and no performance numbers were measured.
