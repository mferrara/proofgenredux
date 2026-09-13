# Final local show-prep fixes — 2026-09-13

The remaining small correctness fixes are implemented on local main, and a new
48-photo NAS-backed rehearsal completed through generation and local delivery.
Recognition remains deferred. Nothing was installed on Dad's MacBook.

## Changes

- Settings previews receive a request-local memory allowance based on source
  dimensions. GD's EXIF rotation holds multiple full-size canvases and flood-fill
  scratch space; a 45 MP portrait exceeded the normal 128 MiB request limit.
  The comparison is reduced before enhancement so only its small canvas remains.
  The previous memory limit is restored when buffers are released. If an exception
  trace retains buffers, the allowance lasts through that request rather than
  masking the original error with a failed memory-limit change.
- Small and large proof labels shrink only when necessary to fit the available
  width/height. Glyph bearings and padding prevent clipped ends and descenders.
  Existing font, opacity, placement, and final JPEG quality 95 remain in use.
- Home omits the internal `_graveyard` folder, preserving legitimate show names
  beginning with underscores. Empty classes display pending imports, and active
  classes display Working. Disabled paid-product generation is not pending work.
- Quarantine replacement, audit filters/show projection, and string class target
  verification resolve actual ShowClass records. Missing identities stop replacement
  before removing the original; quarantine paths are checked against the issue's
  class first. Target checks honor the show's Ferraraphoto slug override.

## Validation

- Isolated test checkout `/tmp/proofgen-output-validation-20260912`, physical
  vendor directory, synthetic environment, guarded SQLite `:memory:`:
  **486 passed, 22 skipped, 2,105 assertions**, 33.03 seconds. No tests ran against
  the development database. Pint and `git diff --check` passed.
- The new preview regression starts a fresh child process at 128 MiB with a
  synthetic 8192×5464 JPEG carrying EXIF orientation 6. It exercises enhanced and
  comparison output, watermark success/failure, and higher/unlimited limits.
  Core Image alone is mocked in the test; full-size PHP decoding/rotation is real.
- The actual 8192×5464 NAS portrait previously failed with an out-of-memory error.
  The fixed native-enhancement probe completed in 5.14 seconds at a measured
  PHP peak of 795,623,424 bytes (about 759 MiB).
- All four Settings tabs rendered that real portrait in Chrome: large 5,487 ms,
  small 4,697 ms, web 4,965 ms, highres 5,286 ms. Small and large proofs with a
  27-character label were visually inspected; complete labels fit. The original
  modest Settings sample was restored afterward, without changing saved settings.
- Home, pending-import class status, advancing live counters, disabled action
  buttons during work, and return to completion were checked in the browser.

## NAS rehearsal

`NAS_LOAD26` has classes `class_A` and `class_B`, 24 photos each. These 48 SHA1-
distinct samples differ from the existing 24 NASDEMO26 photos. Their originals
occupy 479,474,931 bytes (about 457 MiB) on the NAS. The pristine backup source
library was only read; staged originals, working files, and delivered files all
remain under `/Volumes/Public/proofgen-dev`.

Before importing, the harness verified the main local SQLite path, NAS working
root, legacy storage profile, local delivery driver, and all three Ferraraphoto
paths resolving into the NAS delivery folder. Idle workers were refreshed; the
final worker start used the browser control and workers remain running.

Import was queued at 13:09:44 UTC; the last upload timestamp is 13:11:37 UTC,
approximately **113 seconds** for the complete 48-photo workflow. All originals
had imported by the 32-second observation. Verification found:

- 48 records with 48 distinct SHA1 values matching the selected source manifest.
- 48 working originals matching their stored SHA1.
- 96 proofs, 48 web JPEGs, and 48 high-resolution JPEGs, all decodable.
- All 192 delivered files checksum-identical to their generated counterparts.
- All six generation/upload timestamps populated on all 48 records.
- No open issues or failed jobs; no queued, active, or delayed work remained.
- Existing NASDEMO26 retained; local catalog now contains 72 photos.

Five show-status data collection calls during processing took 77.30, 22.01,
3.62, 3.34, and 3.38 ms. These measure component data collection including NAS
scans/cache reads and queue scoping, not browser round-trip or Blade rendering.
A two-class, 48-photo run is not evidence about thousands of photos/many classes;
there is no demonstrated need for a performance rewrite from this sample.

## Delegation and remaining scope

Two bounded DeepSeek Flash lanes ran through native Pi using approved source,
tests, and instructions only. No environment files, database, credentials, or
photos were transferred. The parent reviewed and corrected the returned diffs,
rejected an unnecessary global enhancement refactor, reproduced the real 45 MP
failure, added EXIF rotation/memory-error coverage, implemented watermark fitting,
and ran the isolated tests and real-file rehearsal independently.

Artifacts: `/tmp/proofgen-show-finish-20260913/` (temporary harnesses, source-only
agent workspaces, test logs, sample manifest and verification receipt).

The next operator step is a small-class rehearsal on Dad's MacBook using its
actual storage and upload destinations. This local test used local rsync delivery;
it does not validate his remote upload connection or machine. Single-pass encoding,
format selection, S3 migration, and updater research retain their separate briefs.
