# Watermark Behavior Review — Proofgen Redux, main `11c6e80`

Read-only, source-only review. No tests, commands, or filesystem mutations were run; no network, no parent directories. FLOWER and `external-docs/fluxui/` were unavailable as stated. Ranks: P1 = customer/paid-output integrity, P2 = concrete correctness/maintenance, P3 = low-blast-radius or uncertain.

---

## Findings

### 1. P1 — `createHighresImage` never validates the watermark before writing, and leaks/throws after the file exists

**Exact location**
- `app/Proofgen/Image.php:380` — `$watermark = imagecreatefrompng(storage_path().'/watermarks/web-image-watermark-2.png');`
- `app/Proofgen/Image.php:386-388` — unconditional `imagefilter()` + `insert()` + `save()`
- Contrast the deliberately-fixed web path: `app/Proofgen/Image.php:286-299` (pre-write `is_file`/`is_readable`/`GdImage` guard) and `:318-321` (`finally { imagedestroy() }`)
- Retry asymmetry: `app/Jobs/Photo/GenerateWebImage.php:26` (`$tries = 3`, `backoff()` at `:36`) vs `app/Jobs/Photo/GenerateHighresImage.php` (no `$tries`; thumbnails supervisor is `tries => 1` in `config/horizon.php`).

**Trigger.** `storage/watermarks/web-image-watermark-2.png` is missing, unreadable, or corrupt on a given Mac — exactly the failure that motivated the web guard. (`storage/` is absent from this snapshot, so the asset could not be inspected; it is git-tracked per `tests/Unit/Proofgen/ImageWebWatermarkTest.php:16`.)

**Observed code behavior.** Highres generates with no guard: it first saves the scaled JPEG at `:373-374`, then decodes the PNG. If `imagecreatefrompng` returns `false`, `imagefilter(false, …)`/`$image->insert(false, …)` raises a `TypeError` (not `Exception`), and there is no `finally`/cleanup. Result: an **un-watermarked highres JPEG remains on disk** at the destination, plus an unreleased GD resource.

**Impact.** The file is not registered as generated in this attempt (`PhotoService::generateHighresImage` only stamps `highres_image_generated_at` after success), but it can be picked up by the self-healing path `Photo::checkPathForHighresImage()` (`app/Models/Photo.php:317-330`), which back-stamps `highres_image_generated_at` from `filemtime` whenever a file is present, and it can be swept by a show-level highres rsync. That is a path to uploading/delivering a paid highres image without its watermark. The job also gets only one attempt (worker default), unlike web's 3.

**Smallest fix.** Extract the web guard into a shared `resolveWatermarkGdImage()` helper and call it at the top of `createHighresImage` before any write; wrap insert/save in `try/finally { imagedestroy($watermark); }`. Optionally add `$tries`/`backoff()` to `GenerateHighresImage` matching web.

**Verification.** Mirror `tests/Unit/Proofgen/ImageWebWatermarkTest.php` for `createHighresImage`: missing asset and corrupt asset must throw with no output file created; valid asset must produce a watermarked output.

---

### 2. P2 — Normal import dispatches web/highres generation regardless of the configured enable toggles

**Exact location**
- `app/Services/PhotoService.php:112-116` — unconditional `GenerateThumbnails`/`GenerateWebImage`/`GenerateHighresImage` dispatch in `runImport()`
- Toggles respected elsewhere: `app/Models/ShowClass.php:455-459` and `:474-479`; `app/Livewire/ClassViewComponent.php:269,287,324,378,444,468`; UI toggles at `resources/views/livewire/config-component.blade.php:95-96,219,242`.

**Trigger.** Operator disables `generate_web_images.enabled` and/or `generate_highres_images.enabled` (DB config or `GENERATE_*_ENABLED=FALSE`), then imports photos. `ImportPhoto` → `PhotoService::processPhoto(..., dispatchJobs=true)` reaches `runImport`.

**Observed code behavior.** All three derivative jobs are queued every time; only the manual/bulk regeneration paths check the toggles (`tests/Unit/ToggleImageGenerationTest.php` only exercises `queueWebImageGeneration`/`queueHighresImageGeneration`, never the import path).

**Impact.** Disabling a product line does not stop new imports from producing that product (and, for web, always with a watermark baked in): wasted CPU/disk at a show, extra upload payloads, and `web_image_generated_at`/`highres_image_generated_at` set on photos the operator intended to skip.

**Smallest fix.** Guard the two dispatches in `runImport()` with the same `config('proofgen.generate_*.enabled', true)` checks used by `ShowClass`.

**Verification.** Feature test: `Bus::fake()`, `Config::set('proofgen.generate_web_images.enabled', false)`, call `processPhoto(..., dispatchJobs: true)`, assert `GenerateWebImage` not dispatched (and highres equivalent). Add a case to `ToggleImageGenerationTest`.

---

### 3. P2/P3 — `.jpeg` originals: proof derivative names use `file_type`, generators always write `.jpg`

**Exact location**
- `app/Models/Photo.php:216-226` (`expectedThumbnailFilenames()` → `proof_number.$suffix.'.'.$this->file_type`)
- `app/Models/Photo.php:344` (`checkPathForProofs()` looks for `..._std.jpeg` etc.)
- Generators that always write `.jpg`: `app/Proofgen/Image.php:519-520,536-537`; uploads already fixed to `.jpg`: `app/Traits/RsyncHandlerTrait.php:238-243` ("Image's derivative generators always encode JPEGs with .jpg; file_type describes the original and can instead be 'jpeg'"). `.jpeg` is an accepted ingest extension (`app/Proofgen/Utility.php:50`, `:131`; `app/Proofgen/Image.php:112-116`).

**Trigger.** Import a source named `*.jpeg`, then run any `Photo` helper that builds proof paths (e.g. `deleteLocalProofs()` via `ShowClass::regenerateProofs()`).

**Observed code behavior.** Generated proofs are `{proof}{suffix}.jpg`; `checkPathForProofs()` and `expectedThumbnailFilenames()` look for `.jpeg`, so `checkPathForProofs()` returns `false`, `deleteLocalProofs()` unlinks nothing, and `Photo::created` (`app/Models/Photo.php:76`) cannot back-stamp `proofs_generated_at` for `.jpeg` sources. Regeneration still overwrites the `.jpg` files, which limits the practical damage.

**Impact.** Derivative bookkeeping/backfill is wrong for `.jpeg` sources; "regenerate proofs" silently skips deleting the old files it claims to remove; the remaining half of the `.jpeg` fix documented in `docs/SHOW_PREP_REVIEW.md` is un-done in the `Photo` model.

**Smallest fix.** Introduce one canonical derivative-basename helper (`.jpg`) and use it in `expectedThumbnailFilenames()`, `checkPathForProofs()`, and `RsyncHandlerTrait`.

**Verification.** Unit test: create a photo with `file_type = 'jpeg'`, write `{proof}_std.jpg`/`_thm.jpg`, assert `checkPathForProofs()` finds both; assert `deleteLocalProofs()` removes them.

---

### 4. P3 — Configured quality is dropped on the final watermarked save (production) and never applied in previews

**Exact location**
- Production: first save passes quality, final post-watermark save does not — `app/Proofgen/Image.php:303` vs `:316` (web); `:374` vs `:388` (highres); `:518` vs `:526` (small proofs); `:534` vs `:545`/`:559` (large proofs).
- Preview: `$quality` is computed at `app/Livewire/ConfigComponent.php:1194`/`:1199` and echoed in `input_settings`, but `:1206-1208` and `:1227-1229` call `->save($destPath)` with no quality; watermark re-saves at `:1492,1499,1515,1551` also omit it.

**Trigger.** Change `WEB_QUALITY`/`HIGHRES_QUALITY`/`THUMBNAILS.*.quality` and regenerate. The web/highres/proof output is decoded from the first save, watermarked, and re-saved.

**Observed code behavior (source-only hypothesis on library defaults).** The final `Intervention\Image\Image::save()` calls pass no quality; unless the library carries the prior quality forward, the delivered file is re-encoded at the encoder default rather than the configured value, and the content is JPEG-encoded twice. `vendor/` is not in this snapshot, so the default-quality behavior is **not verified** — flagged as a hypothesis. The preview branch is unambiguous in source regardless of library defaults: the computed `$quality` is never passed to any `save()`.

**Impact.** Paid web/highres size/quality knobs may not govern the final artifact; the "preview quality" UI value does not match the preview file; extra generational JPEG loss.

**Smallest fix.** Pass `quality: $quality` / `quality: (int) config(...)` on every post-watermark `save()` and in `createPreviewThumbnail`.

**Verification.** Generate web images at quality 40 and 95 and compare byte sizes/bytes for identical input; assert preview file differs when only quality changes.

---

### 5. P3 — Watermark preview checkbox is double-bound and the handler negates it, so it can flip back

**Exact location**
- `resources/views/livewire/config-component.blade.php:148-153` — `wire:model="previewWatermarkEnabled"` **and** `wire:change="togglePreviewWatermark"`
- `app/Livewire/ConfigComponent.php:514-518` — `$this->previewWatermarkEnabled = ! $this->previewWatermarkEnabled;`

**Trigger.** Click the "Show watermarks on preview images" checkbox (thumbnails tabs only).

**Observed code behavior.** The property is two-way bound and the change handler also negates it. On the change request, the bound value is applied and then negated, so the effective value returns to the previous state (or the regenerated preview uses the pre-toggle value while the checkbox shows the new one). Net: the toggle cannot reliably turn preview watermarking on/off. **Uncertainty:** Livewire 4/Flux event ordering could not be verified — `external-docs/fluxui/index.md` and the Flux assets are not in the snapshot — but the unconditional negation of an also-bound property is unsafe under any ordering.

**Impact.** Operator cannot trust the preview when validating proof watermark settings, which is the main way to sanity-check a show's proofs before upload.

**Smallest fix.** Keep one mechanism: either drop `wire:change` and make the handler `generateThumbnailPreviews()` only (triggered via `wire:model.live` + `updatedPreviewWatermarkEnabled`), or drop `wire:model` and let the method flip from the current server value.

**Verification.** Livewire component test: toggle the checkbox, assert final `previewWatermarkEnabled` and that the preview generation ran with that value.

---

### 6. P3 — `watermark_proofs` env fallback is not boolean-coerced, unlike sibling flags

**Exact location**
- `config/proofgen.php:65` — `'watermark_proofs' => getenv('WATERMARK_PROOFS')`
- Consumed truthily at `app/Proofgen/Image.php:514` — `$do_we_watermark = config('proofgen.watermark_proofs');`
- Sibling flags do coerce: `config/proofgen.php:52,60` (`=== 'TRUE'`); DB override/casting at `app/Models/Configuration.php:120-135,262-289`.

**Trigger.** Fresh install / deleted config row where `WATERMARK_PROOFS=FALSE` is the only source (the DB `Configuration` row is normally the effective source and is `boolean`-typed).

**Observed code behavior.** `getenv()` returns the non-empty string `"FALSE"`, which is truthy in PHP, so `createThumbnails()` applies proof watermarks despite an explicit `FALSE`. When a DB row exists, `Configuration::overrideApplicationConfig()` converts the string and sets the boolean, so the bug is only reachable in the no-DB-row fallback.

**Impact.** Proofs could be watermarked against explicit operator intent on an unseeded/degraded config, producing customer-visible wrong proofs.

**Smallest fix.** `getenv('WATERMARK_PROOFS') === 'TRUE'` (or `filter_var(..., FILTER_VALIDATE_BOOLEAN)`), matching the other env flags; ideally default false.

**Verification.** Config unit test with the DB row removed and `putenv('WATERMARK_PROOFS=FALSE')`, asserting `config('proofgen.watermark_proofs') === false`.

---

### 7. P3 (source-only performance hypothesis) — `determineAverageColor` fully decodes and pixel-walks the bottom 20% on high-res files

**Exact location** `app/Proofgen/Image.php:399-431` (`imagecreatefromjpeg` at `:401`, nested `imagecolorat` loop at `:410-421`). Called for web at `:311`, for highres at `:384`, and in previews at `app/Livewire/ConfigComponent.php:1537`.

**Trigger.** Generate web/highres for camera-size images (highres is scaled to the configured highres width/height and then decoded a second time for the average).

**Observed code behavior.** The saved derivative is decoded again and every pixel in the bottom 20% is read through `imagecolorat()`; the GD handle is never `imagedestroy()`d. For a 6000×4000 highres that is ~4.8M slow calls per photo, on top of a second full JPEG decode, inside a job that already sets `ini_set('memory_limit', '-1')` (`app/Jobs/Photo/GenerateHighresImage.php:38`).

**Impact.** Potentially throughput-limiting on a full class at a show; not verified by execution. Note the loop's result only needs a coarse light/dark decision.

**Smallest fix.** Compute the average from the already-in-memory `$image` before the first save (or sample a sparse grid, e.g. stride 4), and `imagedestroy()` the temporary GD handle.

**Verification.** Time `createHighresImage` at representative camera sizes before/after; assert the chosen light/dark branch is unchanged on a fixture set.

---

## Consolidation opportunities

1. **Single watermark renderer/inserter.** Watermark placement is duplicated 4 ways: `Image::createWebImage` (`app/Proofgen/Image.php:286-321`), `Image::createHighresImage` (`:377-390`), `ConfigComponent::applyWebImageWatermark`/`applyHighresImageWatermark` (`app/Livewire/ConfigComponent.php:1524-1562`), and `ConfigComponent::applyWatermarkToPreview` (`:1482-1516`) which re-implements the proof watermark block in `Image::createThumbnails` (`:521-560`). Practical benefit: the Finding 1 guard, the Finding 4 quality argument, and any asset/geometry change become one code path; preview and production cannot drift.

2. **One canonical derivative-filename helper.** The same "proof derivative basename" is built three different ways: `app/Models/Photo.php:216-226,344` (uses `file_type`), `app/Traits/RsyncHandlerTrait.php:238-243` (forces `.jpg`), and `app/Proofgen/Image.php:519-520,536-537,369-371,293-295` (forces `.jpg`). Practical benefit: prevents the Finding 3 `.jpeg` drift and any future extension-related regression.

---

## Covered files and gaps

**Read in full or in relevant part:** `CLAUDE.md`, `CLAUDE_NOTES.md`, `docs/SHOW_PREP_REVIEW.md`, `docs/CUSTOMER_DELIVERABLES.md`, `docs/photo-pipeline.md` (§1-4, 8-11), `docs/image-enhancement.md`; `app/Proofgen/Image.php`; `app/Services/PhotoService.php`; `app/Jobs/Photo/{GenerateWebImage,GenerateHighresImage,GenerateThumbnails,ImportPhoto}.php`; `app/Models/{Configuration,Photo,ShowClass}.php`; `app/Livewire/ConfigComponent.php`; `resources/views/livewire/config-component.blade.php` (watermark/preview region); `resources/views/components/partials/photos-table.blade.php`; `config/proofgen.php`; `config/horizon.php`; `app/Traits/RsyncHandlerTrait.php`; `tests/Unit/Proofgen/ImageWebWatermarkTest.php`; `tests/Feature/RealImageProcessingTest.php` (watermark test); `tests/Unit/ToggleImageGenerationTest.php`; `tests/Feature/ConfigComponentSampleImagesTest.php`. `Show.php`, upload jobs, and move/rename services were only grepped for watermark/derivative references.

**Gaps / not verifiable in this snapshot:** `storage/watermarks/web-image-watermark-2.png` and the watermark font are absent, so asset dimensions, placement geometry, and opacity values were not inspected (I make no geometry claims beyond source structure). `vendor/` is absent, so Intervention Image `save()` default-quality behavior is a marked hypothesis. `database/` (migrations/seeders) is absent, so the `watermark_proofs` DB row type/seed and the watermark config rows could not be confirmed; Finding 6 is conditional on that row being absent. `external-docs/fluxui/` is absent, so Flux/Livewire checkbox event ordering in Finding 5 is marked uncertain. No execution, database inspection, or test run was performed; real show timing, Dad's Mac worker state, and highres memory behavior remain unverified as noted in `SHOW_PREP_REVIEW.md`. S3 migration and updater areas were deliberately not reviewed.
