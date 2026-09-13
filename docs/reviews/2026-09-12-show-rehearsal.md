# Local show-prep rehearsal — 2026-09-12

The local import → enhancement/watermark → output → rsync → delivered-file inspection passed against `30c90e6`. The Core Image binary was rebuilt and the local daemon restarted. All four Settings preview tabs also loaded in the signed-in browser.

## What ran

The active application uses local rsync into the sibling Ferraraphoto checkout. The rehearsal used exactly that destination layout, under the new show directory `PG_REHEARSAL_20260913`. It did not connect to the public server.

Four existing Redux24 sample-photo copies were imported through `PhotoService::processPhoto`, with the actual identity resolver, original/archive writes, derivative job dispatch, refreshed native renderer, and upload jobs. The rehearsal database was SQLite in memory in the isolated validation checkout. Jobs executed synchronously, and proof numbers were supplied explicitly to avoid consuming the operator's Redis number pool. Source photos and the operator database were unchanged. Image dimensions, quality, font and enhancement parameters came from a narrow snapshot of current image settings. Archive writing was explicitly enabled for the rehearsal; the operator's saved archive setting was unchanged.

| Case | Source | Products delivered |
| --- | --- | --- |
| Landscape, all enabled | `IMG_0062.JPG` | Small proof, large proof, web, highres |
| EXIF portrait, imported as `.jpeg`, web disabled | `IMG_0088.JPG` | Small proof, large proof, highres |
| EXIF portrait, highres disabled | `IMG_0074.JPG` | Small proof, large proof, web |
| EXIF portrait, both disabled | `IMG_0071.JPG` | Small proof, large proof |

Result: **1 rehearsal passed, 117 assertions, 7.91 seconds; 12 delivered images and 8 successful native renders.** The renderer responses were recorded so fallback could not masquerade as a successful Metal check.

Checks confirmed original/source/archive SHA-1 equality, ingest-source removal after successful import, successful upload timestamps, correct portrait orientation, configured size limits, absent disabled products, SHA-256 equality for every generated/delivered pair, and completion retained on repeated proof uploads.

The delivered landscape proof returned **HTTP 200, image/jpeg** from local Ferraraphoto. Its downloaded SHA-256 matched the on-disk derivative: `ab04c32e5771f2b9db5a14f7c0c9b2640979e4914202a43e5be74652c92ea246`.

## Visual/browser results

Directly inspected the delivered landscape/portrait large proofs, portrait small proof, and web product. Orientation, enhancement consistency and the signature watermark looked correct. The deliberately long rehearsal proof identifier clipped against the fixed watermark width, so that layout defect is queued in [SHOW_PREP_TODO](../SHOW_PREP_TODO.md).

The live Settings browser used the existing horse-show sample `22BUCK_00021`. Its ordinary proof-number label was fully readable. All tabs loaded without endless spinners:

| Preview | Dimensions | Reported generation time |
| --- | --- | --- |
| Large thumbnail | 950 × 633 | 1,561 ms |
| Small thumbnail | 320 × 213 | 1,381 ms |
| Web image | 1600 × 1067 | 1,545 ms |
| High resolution | 3000 × 2000 | 2,063 ms |

These are one sample's observed times, not a throughput benchmark. Browser actions changed tabs only; no settings were saved.

## Evidence and scope

Local artifacts are retained in `/tmp/proofgen-rehearsal-20260913/`: `run.log`, `manifest.json`, the image-only settings snapshot, imported originals/archive copies and downloaded HTTP proof. The one-off runner is `/tmp/proofgen-output-validation-20260912/tests/Feature/ShowRehearsalTest.php` and uses the guarded test bootstrap. Delivered files remain under the rehearsal show directory in local Ferraraphoto's proofs/web/highres roots for inspection; no Ferraraphoto database show/class rows were created.

The initial setup attempt stopped at the missing temporary archive directory before import; that fixture was corrected and the complete run above passed. Swift compilation initially needed access to its normal module cache outside the sandbox, then succeeded and the daemon restart succeeded.

This proves the local processing/transport path and browser previews. It does not exercise asynchronous Horizon scheduling, Redis proof-number allocation, public-server SSH transport, customer checkout/email delivery, or the dad's MacBook. Background Workers was already stopped and remains stopped. No production update or bulk regeneration was performed. No application code changed during this rehearsal; the earlier full-suite and Metal regression results remain recorded in the image-output review.
