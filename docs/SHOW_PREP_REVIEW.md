# Core show-prep review — 2026-09-11

Upload reliability and test isolation are implemented and locally verified.
The existing import, archive, issue-resolution, and derivative workflows remain
the core application. Horse recognition is deferred; this work adds no recognition
changes and does not establish readiness on Dad's Mac.

## Changes accepted

- Active show/class uploads share an argv-based rsync runner. Key paths, spaces,
  apostrophes, and SSH ports survive command construction. Nonzero exits and
  timeouts throw; failures stop chained metadata work. SSH connection attempts
  and authentication prompts are bounded.
- Upload stamps come from rsync's own reported transferred/unchanged file set.
  Dry-runs never stamp success. Proofs require both configured variants; retries
  reconcile missing stamps, preserve unchanged timestamps, and advance them on
  a content retransfer. JPEG originals with a `.jpeg` extension correctly match
  their generated `.jpg` derivatives.
- Uploads use one dedicated Horizon worker and Redis queue connection. At default
  settings the process/job/combined-job/retry budgets are 1800/2100/6600/7200
  seconds. Ordinary Redis jobs keep their existing recovery window. Both local
  and production Horizon plans include the new worker.
- Ambiguous proof-number filenames and empty/failed Redis pops produce useful
  exceptions while retaining the original. Allocation still preserves the
  uppercase prefix, padding, and extra-number convention.
- Web generation has bounded retries and validates its watermark before writing
  output. Temporary watermark resources are released on error.
- Tests use synthetic image fixtures and SQLite in memory, reject cached or unsafe
  database configuration before providers boot, intercept the named upload queue
  before providers register, and reject unfaked Laravel HTTP requests.

## Independent validation

- Full Pest suite: **337 passed, 7 skipped, 1421 assertions**, 30.42 seconds.
  Skips are existing optional Jetstream API-token, email-verification, and
  registration-mode tests.
- Changed-file Pint: **38 PHP files passed**. `git diff --check` passed.
- Real rsync through a local fake SSH receiver: key/port/path quoting and file
  bytes verified without network access.
- Parent regressions reproduced six `.jpeg` upload-status failures and two
  environment/filename-discovery failures before correction, then passed.
- Inherited instruction edits and draft documents match their initial SHA-256
  hashes. No recognition files were changed.

Validation receipts are `/private/tmp/proofgen-final-tests-20260911.log`,
`/private/tmp/proofgen-final-tests-20260911.xml`, and
`/private/tmp/proofgen-core-showprep-receipt-20260911.json`.

## Delegation and review

Four fresh, persisted Pi workers used provider **deepseek**, model
**deepseek-flash**, with `thinking=max`: isolation, transport, transport
corrections, and queue/proof-number/watermark work. Parent Codex reviewed the
source and tests, requested transfer-evidence corrections, fixed compatibility
and configuration details, and ran the final independent gates. Reviewed
outcomes are recorded in the shared Pi run log.

Worker session IDs:

- `01a08f91-05d0-73d3-aa58-a47432362227`
- `01a08f9d-3ac8-766b-b194-0f4e5fec9afd`
- `01a08fab-84d1-7177-9e67-3efba0ed62e0`
- `01a08fb5-874f-7740-bac5-f34f37111aed`

## Release boundary

The core fixes are committed separately on `feature/reid-pipeline`, based on
`e58c88b258c575f32fa7e3c447010318358569c1`, so that commit can be cherry-picked
without the branch's recognition history. The pre-commit core patch is
`/private/tmp/proofgen-core-showprep-20260911.patch`; it excludes inherited
instruction/draft changes and recognition work. Its application was checked
against a temporary copy of **local** main
`b5557596693371a04bb8565ae0fc1def6af8c89c`. This checks patch compatibility;
it is not a full test run on a new release checkout or verification of remote main.

The commit contains only the reviewed core fixes, tests, and related documentation.
No merge, push, release tag, deployment, or service restart was performed.
Use [SHOW_PREP_CHECKLIST.md](SHOW_PREP_CHECKLIST.md) for installation verification,
archive inspection, and the small real import/upload/retry/customer-flow smoke.
Actual transfer targets and timing, Dad's worker state, and camera-size performance
remain unverified. Existing image memory limits, highres watermark handling,
and the legacy proof-number allocation architecture remain follow-up work.
