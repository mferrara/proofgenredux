# Live processing and worker startup — 2026-09-13

## Findings from Redux24 / 001

The queue was empty and Horizon was running. All 56 thumbnail jobs had failed
because their original files were absent. The records were created on April 5,
2025; pending generation counts were unfinished records, not queued jobs.

Today's 20 source files went to `_import_conflicts` as `duplicate_content`.
A read-only SHA-1 comparison found that these 20 distinct files match 53 old
records: this test class contains repeated imports under different proof numbers.
Three records have no matching quarantined file. Recovery was initially left
pending; the subsequently approved restoration is recorded below.

One import job failed creating the quarantine directory, and the second import
click completed it. Flysystem's createDirectory checks existence before mkdir
but throws when mkdir loses a concurrent creation race. SafeFileMover now
accepts that exception only when the target directory actually exists.

The old Horizon launcher left its shell attached to the running child. The
request could stay open while Horizon was already processing work. Also, the
status bar was only included in the old layout, not Livewire's active layout.

## Changes

- Fully detach Horizon's standard streams and poll readiness for at most eight
  seconds; starting an already running instance is a no-op. Stop reports that a
  graceful stop was requested and respects the Artisan exit code.
- Include the status bar and toast outlet in the active layout. Worker status
  and queue totals refresh every three seconds; Settings services refresh every
  five seconds. Queue totals cover default, processing, imports, thumbnails,
  and the configured uploads queue/connection.
- Show missing originals, open import issues, and recent class-specific failed
  jobs above the class processing counts. These refresh with the existing
  five-second poll. Failure history is explicitly separate from current work.
- Skip missing originals when queuing class generation. Disable Generate when
  none are available. Preserve existing derivatives when regeneration cannot
  read their original. Avoid repeated Import clicks during dispatch.
- Cache the status bar's graveyard scan for 60 seconds rather than scanning it
  on every status poll. Upload text now describes pending generated files,
  without presenting an empty pending set as proof that everything is complete.

## Verification

DeepSeek Flash via Pi supplied the bounded HorizonService implementation and
seven tests. Parent checked the source manifest: only the two assigned files
changed. Parent tightened the real synthetic process test to prove launch
returns before a two-second child finishes, corrected stop reporting, and
implemented/reviewed the remaining changes.

Disposable validation checkout: `/tmp/proofgen-output-validation-20260912`,
physical dependencies, synthetic environment and image roots, guarded SQLite
`:memory:`, fake uploads and outbound HTTP. The prior one-off real-photo
rehearsal test was removed from this checkout before the full suite.

- Focused startup/file-move/generation tests: 28 passed, 97 assertions.
- Additional status/reactivity/preservation tests: 10 passed, 29 assertions
  (overlaps the generation tests above).
- Full suite: **412 passed, 22 skipped, 1,727 assertions**.
- Pint and `git diff --check`: passed.
- Chrome against Herd: class displayed 56 missing originals, 20 issues, 57
  recent failures, zero queued/active jobs. Stop changed the badge to Stopped;
  Start changed it back to Running with a success toast, without a refresh.
  Workers were left running. Final live queue totals were all zero.

The initial full-suite command selected Apple's rsync and produced 42 transport
failures. Re-running with the required Homebrew PATH passed; no parser behavior
was changed. Use:

```sh
env PATH=/opt/homebrew/bin:/usr/bin:/bin \
  '/Users/mikeferrara/Library/Application Support/Herd/bin/php84' \
  vendor/bin/pest --compact
```

An initial focused invocation inadvertently ran from the main checkout. Its
SQLite guards and fake/temporary disks remained active; it was repeated in the
disposable checkout. No operator database migration ran during testing.

## Approved local restoration

The user approved restoring 20 originals while preserving old proof numbers.
Preflight verified all source SHA-1 hashes against the class records and issue
hashes, and confirmed each destination was absent. One issue linked a duplicate
in a different class: recovery selected the earliest checksum-matching record
inside Redux24/001, leaving that other class untouched. The other 19 used their
issue-linked records. No new photo records or proof numbers were created.

Copied and verified 69,933,432 bytes across 20 originals, retaining quarantine
copies and sidecars. Marked all 20 import issues resolved with recovery notes.
Manifest and prior issue values: `/tmp/proofgen-live-20260913/restore-twenty-plan.json`.

Clicked Generate proofs in the browser. All 20 completed through the real
Horizon workers, producing 40 valid JPEGs (small and large). Original and retained
copy hashes and proof numbers were checked again afterward. Without refreshing,
the class view updated to 20 proofs complete, 36 pending/missing, zero open
issues, and 20 proofs pending upload. Queues drained; the failed-job count stayed
57, so this generation introduced no additional failures. Web/highres generation
and uploads were not requested in this recovery step.

## Remaining

36 originals are still absent: 33 are additional old duplicate records covered
by the same source hashes; three have no matching quarantine source. Those
records were left unchanged, as requested. Failed-job history remains until
cleared by the operator or ages out of the 24-hour class view. Recognition
remains deferred. No changes were pushed, installed on Dad's Mac, or sent to the
public Ferraraphoto server.
