# Server delivery fixes — September 15, 2026

Both automatic and explicit uploads now use `DeliverClassOutputs`: ensure the
Ferraraphoto show/profile, transfer files using the pinned profile and destination
slug, then push photo metadata. A failed transfer stops the metadata step. Legacy
storage without an API token continues to deliver files without API calls.

The saved Uploads switch is read fresh when generation finishes and again when
an automatic delivery starts. Turning it OFF skips queued automatic deliveries;
explicit Upload still works, and an already-running transfer finishes. Re-enabling
does not replay skipped work: use Upload for the pending files.

Automatic delivery selects only image kinds whose generation has finished for
every photo in the class. This prevents a proofs completion from uploading web
or highres files still being written. Later completions retain their own queued
delivery requests. The existing single upload worker serializes those requests;
existing UI busy guards remain in effect. Manual partial-output uploads and the
proof-only action remain supported. Already-stamped cloud outputs are skipped on
retry, while photo metadata can still be retried after files succeeded.

## Delegation and review

- Pi builder: `legitphp/glm-5.3-flash`, thinking high; route verified in the actual
  transcript. Session `01a0a703-8336-7033-820b-69a4114b4ae5`.
- The builder implemented both fixes and their initial regression tests in the
  disposable checkout. The parent reviewed the source and added the fresh saved
  setting check, execution-time automatic gate, per-kind readiness filtering,
  cloud retry optimization, and proof-only routing through the shared job.
- Builder artifacts/report: `/tmp/proofgen-delivery-20260915/`.

## Validation

Herd PHP 8.4, physical vendor copy, synthetic environment and SQLite `:memory:`
in `/tmp/proofgen-output-validation-20260912`:

- Parent focused run: **99 passed, 479 assertions**.
- Parent full suite: **513 passed, 22 skipped, 2,208 assertions** in 33.74 seconds.
- Targeted Pint and `git diff --check` passed.
- Tests cover automatic OFF at all three generation entrypoints despite stale
  worker config, all six completion orders retaining dispatches, manual Upload
  with automatic OFF, `checkForUpload=false`, legacy/cloud delivery, late web
  completion, destination slugs, failed-transfer ordering and queue busy mapping.

No real server connection, API sync, bucket transfer, operator database change,
NAS image operation, or worker restart was performed. Restart existing Horizon
workers to load the changed code before a real-server rehearsal. Pre-existing
legacy jobs already queued retain their original behavior.

Next: deliver a small dedicated test show to the actual server and verify its
catalog, proof display, web/highres file resolution and interrupted-transfer retry.
Dad's laptop rollout, recognition, the audit brief and S3 migration remain separate.
