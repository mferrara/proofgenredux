# Large JPEG import memory failure and recovery

NASDEMO26/002 imported seven photos fully, then left IMG_0001.JPG pending with
photo NASDEMO26_00010 already inserted. The 31,457,024-byte source and verified
original were intact; its metadata and three derivative stages were missing.
Horizon logged a 128 MiB PHP memory exhaustion in Photo::getFileContents(), then
recorded a max-attempts failure when the abandoned job returned to the queue.

The importer retained the source and a full verification copy, then metadata
creation read the entire JPEG a third time solely to calculate its byte count.
A same-class retry previously skipped metadata repair and derivative dispatch,
so it could not finish this partial state.

The fix streams original verification (retaining both SHA and byte-count
checks), releases the source buffer before model callbacks, and uses filesize
for metadata. Same-class retries repair missing metadata and queue unfinished
outputs while preserving identity/proof number. Completed retries dispatch
nothing. New/replacement imports still regenerate outputs, including when old
derivative files remain; product switches and dispatchJobs=false are respected.
The worker memory limit was not raised.

Validation:

- A synthetic 30 MiB JPEG reproduced the exact old metadata-read OOM under
  128 MiB; the corrected importer passes. This regression runs in a fresh Pest
  subprocess so other tests' retained allocations don't contaminate the cap.
- Pi DeepSeek Flash built six source-only recovery tests. Parent corrected its
  reserved CLASS constant and added a replacement-output regression plus the
  memory test; no photographs, database or environment files went to Pi.
- Final isolated suite: 437 passed, 22 skipped, 1,844 assertions. Pint and
  git diff --check passed.
- Retried only failed job f9c32883-9885-4ca4-bd0c-d8cb79009a3a after checking
  both source/original hashes. Proof NASDEMO26_00010 was preserved; metadata,
  both proof JPEGs, web and high-resolution image completed.
- All eight class-002 originals match the curated SHA manifest. All 32 generated
  JPEGs match their delivered local Ferraraphoto copies by SHA-256. All eight
  records have generation/upload timestamps for every product; no failed jobs
  or pending class-002 imports remain. Browser shows the class complete.
- Class 003 retains eight pending images. Workers were refreshed with the fix.
