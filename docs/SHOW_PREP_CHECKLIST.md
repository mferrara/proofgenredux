# Show-prep checklist

Core upload, proof-number, and test-isolation checklist, refreshed 2026-09-13.
These changes have not been installed or exercised against Dad's real upload targets.
Horse recognition remains deferred.

## Prepare the release

The core fixes are now cherry-picked onto local `main`. Recognition and WIP
planning remain on `feature/reid-pipeline`; use `main` for the next core release.
The in-app updater pulls `main` and selects a release tag when one is available.
Finish current imports/uploads before installing the update; then verify the
new worker is running. Local workers have been restarted during validation; no
release or installation on Dad's MacBook has been performed.

## Local UI follow-up — September 13

The active layout now shows live worker status and queue activity on every page.
An idle browser stop/start cycle passed without refreshing; local workers were
left running. Class views distinguish missing originals, import issues, and
recent failed jobs from pending generation counts. See
[the live-processing review](reviews/2026-09-13-live-processing-fixes.md).
The old Redux24 catalog was subsequently reset with operator approval. Local
working data now lives under `/Volumes/Public/proofgen-dev`; the pristine NAS
backup library is unchanged. `NASDEMO26` contains 24 imported sample photos and
`NAS_LOAD26` contains the additional 48-photo rehearsal. See
[the final local review](reviews/2026-09-13-show-prep-finish.md).

## Verify the workers after installation

Use Herd PHP 8.4 and modern Homebrew rsync (README.md already requires
`brew install rsync`). The upload runner selects `/opt/homebrew/bin/rsync` or
`/usr/local/bin/rsync`
unless `RSYNC_BINARY` is configured. Verify that selected binary's version; the
locally verified version is Homebrew rsync 3.4.3. Apple's openrsync emits a
different itemize format and does not provide the required completion evidence.

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
2. Import a handful of photos. Originals and enabled archive copies should be present,
   followed by both proof sizes and the enabled web/high-resolution products.
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

`php artisan proofgen:audit --show=YOUR_SHOW_ID --format=table` inspects originals
and archives and can persist issue rows. The advertised `--persist-issues=false`
option is not wired up; it is **not** a read-only mode. Use the audit deliberately.
`--repair` additionally writes repairs. See [archive backups](archive-backups.md).

## Local regression gate

Follow [TESTING.md](TESTING.md) from a disposable checkout. The last recorded gate
at `2aae180` passed 486 tests with 22 skips; the separate local rehearsal completed
48 additional photos and 192 verified delivered JPEGs.

Still unverified on Dad's machine: actual targets, SSH/rsync versions at both ends,
show-network throughput, and the customer workflow after installation. Locally,
the tested 45 MP files and all Settings preview tabs passed. The existing
60-second web-generation worker timeout and unlimited web-job memory setting
remain; recognition is outside this release scope.
