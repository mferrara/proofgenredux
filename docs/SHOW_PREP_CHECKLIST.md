# Show-prep checklist

Core upload, proof-number, and test-isolation fixes, reviewed 2026-09-11.
These changes have not been installed or exercised against Dad's real upload targets.
Horse recognition remains deferred.

## Prepare the release

The working checkout is `feature/reid-pipeline`. Its recognition commits should
stay separate from the core fixes when preparing the next `main` release. The
in-app updater pulls `main` and selects a release tag when one is available.
Finish current imports/uploads before installing the update; then verify the
new worker is running. No release, update, or restart was performed by this work.

## Verify the workers after installation

The updater clears/rebuilds configuration and restarts Horizon. If installing
manually, refresh any cached configuration and restart Horizon through the
existing Configuration / background-worker controls (or the usual local
Herd/Solo workflow).

```bash
php artisan horizon:status
php artisan horizon:list
```

Look for `supervisor-uploads`, consuming queue `uploads` on connection `uploads`,
with one worker. Old workers will not consume the new queue until restarted.

| Budget | Default |
|---|---:|
| Each rsync process | 1800s |
| Individual show/class upload job | 2100s |
| Combined class upload and Horizon upload worker | 6600s |
| Upload connection retry_after | 7200s |
| Ordinary Redis connection retry_after | 90s, unchanged |

`SFTP_TRANSFER_TIMEOUT` and `UPLOAD_JOB_OVERHEAD` derive these budgets together
in `config/proofgen.php`; refresh configuration and restart workers after changing
them. Uploads have 5 attempts with delays of 60, 300, 900, then 1800 seconds.

## Run a small class through the actual workflow

1. Confirm the full-size and archive drives are mounted, the show slug is correct,
   and the configured proof/web/highres destinations match Ferraraphoto.
2. Import a handful of photos. Originals and archive copies should be present,
   followed by both proof sizes, web images, and high-resolution images.
   An ambiguous JPEG name in `originals/` now stops number allocation with the
   show and filename. Resolve it while retaining the original. Use proof-number
   renaming for this workflow; raw filenames in `originals/` remain incompatible
   with later pool reconstruction.
3. Once derivatives are ready, run the class upload. That chain sends proofs,
   web, then highres, and pushes metadata last. Automatic uploads triggered by
   separate generation jobs can be queued in their completion order.
4. Check the real remote files and the customer-facing gallery/digital downloads.
   **Check Uploads** performs rsync dry-runs and reports pending files; it may
   create target directories and clear stale upload stamps. It is not wholly
   read-only. Missing destination settings now fail an actual upload visibly.
5. Re-run the class upload. An unchanged transfer should preserve upload times
   and fill any missing stamps. If a transfer fails, metadata must wait; restore
   connectivity/configuration and retry the failed job or run the class upload
   again. Partial prior transfers should reconcile on success.
6. Regenerate one web image. A missing or corrupt watermark now fails before
   writing/replacing its output. Web generation has 3 attempts with 60/300-second
   delays. Do not remove a working watermark just to test this on a real show.

## Check archive coverage

This form inspects files without persisting issue records or repairing anything:

```bash
php artisan proofgen:audit --show=YOUR_SHOW_ID --persist-issues=false --format=table
```

Resolve missing originals/archives and hash mismatches before clearing cards or
removing source copies. The normal audit can record issues; `--repair` additionally
writes repairs. Use those deliberately after reviewing the findings.

## Local regression gate

```bash
./vendor/bin/pest
```

`phpunit.xml` and the test bootstrap pin SQLite in memory, disposable infrastructure,
synthetic image fixtures, and a sync replacement for the named upload connection.
They reject unsafe database/cached configuration before providers boot and block
unfaked Laravel HTTP requests. Transport regressions use local/fake rsync and SSH.

Still unverified: actual targets, SSH/rsync versions on Dad's Mac and the receiver,
show-network throughput, largest camera files, and the customer workflow after
installation. The existing 60-second web-generation worker timeout and unlimited
image memory setting remain; highres watermark handling and the legacy allocation
architecture were not redesigned. Recognition is outside this release scope.
