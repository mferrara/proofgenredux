# Core Image enhancement on macOS

Proofgen uses `EnhancementServiceFactory` to select the Core Image daemon, with `ImageEnhancementService` (GD) as the fallback. `CoreImageDaemonService` communicates with the compiled Swift renderer on localhost port 9876 and starts it when needed. The duplicate standalone Swift renderer and PHP stdin service have been removed; the daemon source still provides stdin mode for isolated rendering tests.

## Settings and supported adjustments

Production jobs and Settings previews resolve the same parameter values: explicit preview values override saved `proofgen.*` configuration, then built-in defaults apply. The selectable methods are adjustable auto-levels and advanced tone mapping. Legacy `basic_auto_levels` and `percentile_clipping` remain supported for existing callers; the basic alias intentionally uses built-in defaults unless explicitly overridden.

- Auto-levels: target brightness, contrast threshold/boost, and black/white percentile points. Percentiles are percentages (for example, 5 and 95), not fractions. Histograms sample actual luminance pixels at up to 500 pixels on the longest side.
- Advanced tone mapping: percentile stretch, local shadow adjustment, highlight darkening, radius, and gamma. Shadow zero and highlight zero leave those controls neutral. Highlights accept -100 through 0; positive highlight brightening is unsupported and produces an explicit error. Gamma above 1 darkens midtones; below 1 brightens them.
- GD fallback: global auto-levels, percentile stretch, and gamma. Local shadow/highlight adjustments require Core Image; failure produces an actionable error instead of silently ignoring the settings. GD preserves EXIF orientation and processes pixels in memory without temporary JPEG passes. GD and Core Image are not guaranteed to be pixel-identical.

A failed Settings enhancement displays the unenhanced image with an error and does not label it Enhanced or expose a missing comparison image. Production jobs fail when requested adjustments cannot be applied. Daemon output temporary files are removed on success, daemon error, decode failure, and fallback failure.

## Encoding

Both proof sizes start from independent copies of the same enhanced image and fit within configured dimensions without upscaling. Proofs keep the intentional first JPEG pass at configured photo quality, followed by watermarking and a final quality-95 encode.

Web and high-resolution products, including Settings previews, share a watermark compositor and finish with one explicitly configured final JPEG encode. The daemon itself currently returns a JPEG intermediate; removing that is part of future pipeline/format research. Missing or corrupt paid-image watermark assets fail before the final output is written. Existing output is preserved in that case.

Settings previews size their temporary PHP memory allowance from the source
pixels, including EXIF rotation buffers. The previous limit is restored once
buffers are released; exceptions can retain the allowance until the request ends.
The actual 45 MP portrait rendered in all four tabs during the September 13 check.
Proof labels fit their canvas, reducing font size only when necessary. Existing
proofs need regeneration to receive output changes. See
[the final local validation](reviews/2026-09-13-show-prep-finish.md).

## Local operation

Swift and Metal require the macOS development tools. After changing Swift source, compile and refresh the daemon when local image jobs are idle:

```sh
php artisan proofgen:swift-compile
php artisan proofgen:coreimage-daemon restart
php artisan proofgen:coreimage-daemon status
```

The in-app updater already compiles registered Swift binaries and refreshes processes. Changing PHP/Swift source alone does not replace an already compiled/running daemon. Compilation now registers only `ProofgenImageEnhancerDaemon`.

## Validation

Use [the project's isolated test setup](TESTING.md); do not point tests at the operator database or photo directories. The normal suite covers real synthetic GD outputs, JPEG quality tables, EXIF orientation, saved parameters, preview failure state, missing watermarks, and temporary-file cleanup.

The macOS renderer test explicitly opts into compiling the actual daemon source and processing generated gradients/flat images through Metal. It uses its own temporary files and stdin process, without starting or stopping the application's daemon:

```sh
PROOFGEN_TEST_CORE_IMAGE=1 ./vendor/bin/pest tests/Feature/CoreImageRenderingTest.php
```

It checks brightness, contrast, percentile clipping, shadows, highlight darkening, gamma, flat-image behavior, and unsupported positive highlights. The separate performance test is also opt-in and needs representative sample images. No current production throughput or speedup claim follows from these correctness tests.
