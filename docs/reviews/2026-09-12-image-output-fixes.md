# Image-output fixes — 2026-09-12

## Result

The two image-output implementation rounds are complete on top of `5416dab`. The next step is the small end-to-end import/output/upload rehearsal in [SHOW_PREP_TODO](../SHOW_PREP_TODO.md). No customer photos were uploaded or regenerated in this round. The existing local daemon has not been replaced or restarted, and the dad's MacBook has not been updated.

## Changes

- Both proof sizes clone the same enhanced source and fit configured dimensions without upscaling. Proofs retain configured first-pass quality followed by watermarking and final JPEG quality 95.
- Normal import honors the web/highres enable switches. Paid products and their Settings previews share watermark composition and save at the selected final quality. Missing/corrupt watermark assets fail before replacing the final output.
- Model checks and moves use configured suffixes and `.jpg` derivatives even when the original is `.jpeg`. Photo paths use actual show/class relations, with explicit identity supplied by the direct import path before its class exists. Proof checks match exact expected filenames, not incidental sidecars or suffix fragments.
- Production enhancements now read the same saved parameter values as previews. Core Image percentile units, histogram measurements, shadow/highlight neutral values, and flat-image stretching are corrected.
- The unused standalone PHP/Swift renderer pair is removed. The remaining GD fallback performs real auto-levels, percentile stretch, and gamma in memory, preserving EXIF orientation. Unsupported local adjustments fail clearly; Settings shows the unenhanced image with an error rather than claiming it is enhanced.
- Daemon temporary output files are cleaned up on success and error, including decode/fallback failure.

## Parent review and real rendering evidence

Three parallel Pi builders used the approved `deepseek/deepseek-flash` provider/model, high reasoning, fresh allowlisted source copies, and read/grep/find/ls/edit/write tools. No bash, repository environment files, credentials, databases, or photographs were included. Each lane exited successfully; the parent reviewed and integrated changes, ran tests independently, and made corrections before acceptance.

| Lane | Session | Elapsed | Review disposition |
| --- | --- | --- | --- |
| Derivatives and previews | `01a09715-aa69-7475-b1bd-0775388fbe74` | 489.0 s | Kept after sharing the paid-image writer, improving preview error state and correcting a portrait test expectation |
| Paths and import switches | `01a09715-aa7d-73ef-8465-5288e15782d8` | 282.5 s | Kept after wiring explicit import context, exact proof checks and stronger direct-import tests |
| Adjustments and fallback | `01a09715-aa6b-74fd-bbc8-b489a1e55b76` | 448.8 s | Kept after native histogram/shadow corrections, in-memory GD gamma, consolidation and real Metal tests |

The parent compiled the original and corrected Swift sources and rendered synthetic gradients. The original target-brightness control left a mean near 127.5 when set to 160; corrected output measured about 160. The original percentile clipping nearly whitened the gradient; corrected output retained the gradient and reached the intended black/white ends. Positive shadow adjustment previously darkened the highlight endpoint to about 135; corrected output retained it near 255. These defects were not discoverable from PHP mocks alone.

Installed Core Image filter attributes were inspected: shadow identity is 0; highlight identity is 1. Proofgen's highlight control therefore maps -100…0 to darkening…neutral. Positive highlight brightening is explicitly rejected, and Settings help/validation now matches that limitation.

## Validation

Tests ran in `/tmp/proofgen-output-validation-20260912`, with a physical dependency copy, synthetic configuration, an in-memory SQLite database and temporary image/storage fixtures. Operator databases, original photos, credentials and live queue workers were not used.

- Baseline `5416dab`: **360 passed, 21 skipped, 1516 assertions**.
- Final combined PHP suite: **393 passed, 22 skipped, 1669 assertions**, 17.73 seconds.
- Actual Swift/Metal regression: **1 passed, 25 assertions**, 40.63 seconds. Compiles the daemon source and uses a private stdin process with generated gradients/flat images; no application daemon start/restart. The test is opt-in in the regular suite.
- Pint and `git diff --check` passed.

Coverage includes source consistency, portrait dimensions, no upscaling, JPEG quality tables, missing/corrupt watermark and prior-output preservation, EXIF orientation, preview enhancement failures, import product switches, underscore IDs, `.jpeg` originals, custom suffixes, direct import metadata creation and temporary-file cleanup. Native rendering covers brightness, contrast, percentiles, shadows, highlight darkening, gamma, flat images and unsupported positive highlights.

## Remaining work and limits

Compile the new Swift binary and refresh the local daemon when image jobs are idle before the end-to-end rehearsal. Existing thumbnails receive changes only when regenerated. Corrected enhancement controls can change appearance, so inspect a small representative batch before processing an entire show.

The GD fallback is deliberately limited to global adjustments and is not pixel-identical to Core Image. Native shadow radius remains filter-dependent; no representative-show performance claim is made. The daemon still returns an intermediate JPEG. Thumbnail single-pass/format research stays in Flower #3689; S3 copy-and-switch migration, updater research and recognition remain separate.

Other underscore-ID fallback sites found in quarantine replacement, auditing and the string-only target verifier are queued separately in the checklist. They do not negate the tested Photo/import/move derivative paths in this change.
