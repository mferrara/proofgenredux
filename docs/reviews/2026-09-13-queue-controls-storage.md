# Queue controls and retained storage usage

The show/class action cards previously disabled only the button whose Livewire request was running. Other actions and later clicks could enqueue work again while the original jobs were still waiting or processing. Storage totals expired after ten minutes and were hidden on a new visit until Calculate was clicked.

## Changes

- Native Laravel `ShouldBeUnique` on ImportPhoto, ImportClassPhotos, GenerateThumbnails, GenerateWebImage and GenerateHighresImage. Import identity excludes proof-number overrides; generation identity is the photo within its output type. Locks persist through processing/retries and release on completion or final failure.
- `QueuedWorkStatus` reads waiting/reserved/delayed jobs from the configured local Horizon Redis queues. Serialized public identifiers and chained jobs associate work with a show/class, including upload chains that have not reached the uploads queue yet. It does not instantiate serialized commands or maintain a separate activity ledger.
- Show/class actions check current queue activity on the server. The shared cards disable their action group during an action request and while scoped jobs remain; individual import/generation buttons receive the same UI state. The show-level class Import button uses live status instead of a one-way Alpine "Queued" flag. Another class remains available. Queue outages display unavailable and block enqueue actions until status recovers.
- Storage snapshots persist across visits and beyond the freshness window. The panel displays the last measurement, marks it stale at ten minutes, and offers Refresh. Page loads/polls only read cached totals. Refresh measures before replacing the snapshot, preserving the previous figures if measurement fails. Show refresh also updates class snapshots. Home sample/backups caching remains unchanged.

## Validation

Two scoped DeepSeek Flash builders through Pi implemented native uniqueness and retained storage caching. Parent reviewed their actual diffs, integrated queue/UI handling, and corrected cache reads to avoid rewriting snapshots on every poll. Agent inputs excluded environment files, credentials, databases and photographs.

- Targeted isolated tests: 32 passed, 180 assertions. Includes real PendingDispatch/database queue/Worker admission and success/retry/final-failure lifecycle; exact class matching, queued upload chains, delayed work, class/server guards, poll-driven re-enabling, stale totals on fresh page visits, refresh and failed-refresh retention.
- Full suite in `/tmp/proofgen-output-validation-20260912` with its own vendor, synthetic environment and guarded SQLite `:memory:`: **463 passed, 22 skipped, 1,991 assertions**. Pint and `git diff --check` passed.
- Live NASDEMO26/001 check: temporarily stopped idle local workers and queued regeneration of eight existing sample photos. Browser showed eight waiting jobs and disabled Generate, Regenerate, upload/check and Reset controls. An additional native dispatch of an identical thumbnail job left the queue at eight. Direct component Import/Web calls were blocked server-side. Class 002 remained idle/available.
- Resumed workers: eight photos regenerated and uploaded; browser returned to zero queued/processing and enabled actions automatically. All sixteen regenerated proof JPEGs matched the local Ferraraphoto delivery copies by SHA-256. No failed jobs, unfinished photos or leftover unique locks.
- Storage: calculated NASDEMO26 at **264.15 MB / 120 files**, retained it through polling, and showed the already-calculated class snapshot on navigation. Stale timing and refresh behavior verified with controlled time in isolated tests.

This is scoped protection for routine local UI actions plus native deduplication of import/generation jobs. It does not introduce distributed coordination, change recognition, or dispatch/deploy to another machine. Local sample originals and their proof numbers were preserved. All three demo classes now contain eight complete photos each.

Artifacts: `/tmp/proofgen-queue-ui-20260913/` (builder receipts/source manifests, full-suite log, live duplicate-queue check and final local state).
