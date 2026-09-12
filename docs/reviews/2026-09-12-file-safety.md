# File-safety fixes — 2026-09-12

Implemented the file-handling round from the [core review](2026-09-11-core-review/README.md), based on `main` at `11c6e80d7c5f93fd4c785b71500bc7341baa23ef`.

## Behavior

- Moving a photo refuses an existing destination original, including an orphan with no database row. A false filesystem move result is a failure. Successful moves preserve the original camera filename.
- Reset moves each original directly to a unique ingest filename, avoiding an intermediate filename that could overwrite new ingest. Only successfully released originals have their photo rows removed. Missing originals and failed releases retain their rows; generated timestamps are cleared after local derivative cleanup, while remote upload timestamps remain intact.
- An archive failure during reset attempts to restore the original and archive paths. Failures are counted and logged; the reset queue job reports a partial reset as failed.
- Show import scans immediate class folders for actual `.jpg`/`.jpeg` files, case-insensitively. Nested originals, quarantine/graveyard files and JSON sidecars are excluded. Unregistered class folders and legitimate underscore-prefixed class names remain discoverable.
- Class, move and archive paths use actual show/class fields. Reset looks up the class by name within its show instead of constructing an ID from the show's display name.

## Delegation and independent validation

Implementation used Pi with provider `deepseek`, model `deepseek-flash`, high reasoning. The worker completed successfully in 457.2 seconds using an allowlisted source snapshot, without application environment files, credentials, databases or photos. The parent reviewed every imported change, corrected reset timestamp handling and path assumptions, and ran the tests independently.

Validation ran in a separate temporary copy with SQLite `:memory:`, synthetic storage roots, guarded test configuration and no operator environment. PHP 8.4 and the installed dependencies were used.

- Full suite: **340 passed, 21 skipped, 1,425 assertions**. Fixture-dependent cases remain skipped in this isolated setup; this does not establish real-photo or live UI readiness.
- Original-code comparison: the five focused test files produced **16 failures and 18 passes** against the base production code. The validated production files were then restored byte-for-byte.
- Pint passed for all 11 changed PHP files; `git diff --check` passed.

Coverage includes destination collision bytes, false/throwing moves, archive failure, missing originals, mixed reset outcomes, ingest-name collision, exact import dispatch paths, sidecars, reserved folders and show/class identity.

## Limits and next work

Filesystem operations are not transactional with the database. Restoration after archive failure is best effort and logs any restoration failure. This change does not add generalized recovery for every later failure in a cross-class move or reset, and does not exercise live disks, real photos, remote upload destinations or the desktop UI.

UI and image-output work is queued in [SHOW_PREP_TODO.md](../SHOW_PREP_TODO.md). This includes the broader `Photo` path accessors, whose direct image-processing callers can run before a class row exists. Recognition, S3 migration and updater research remain separate.
