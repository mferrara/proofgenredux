# Local NAS test dataset

`nasdemo26.json` selects 24 distinct JPEG originals from the existing TrueNAS
sample library. Eight files per class: larger horse-show camera originals in
001 and 002, smaller camera originals in 003. Files span roughly 2.5–31.5 MB;
combined source size is 213 MB. The manifest records exact source paths, byte
sizes, SHA-1 hashes, and destination names so this dataset can be reproduced.
No images are stored in Git.

- Pristine library: `/Volumes/Public/_Backups/sample_show_photos` — never import
  directly from this tree: importing moves the source.
- Working image root: `/Volumes/Public/proofgen-dev/shows`.
- Show: `NASDEMO26`, classes `001`, `002`, `003`.
- Local Ferraraphoto delivery files also live on the NAS through filesystem
  links at its existing proof/web/highres paths.
- Settings sample previews use `/Volumes/Public/proofgen-dev/sample-previews`
  via the local `SAMPLE_IMAGES_PATH` setting.

Mount Public in Finder before using this development setup. Processing still
runs on the Mac; originals and outputs are read/written over SMB. This is a
local development arrangement, not a change to Dad's desktop storage workflow.

After staging, import through Proofgen's usual class/show controls. Do not copy
files over existing imported originals or reset catalogs automatically. A later
same-class re-import should reuse the existing photo and proof number; a copy
into a different class should be flagged for review, never create a second row.

The sample library contains years of reused images, so do not use its full
folder tree as a supposedly unique test set. The manifest was selected with
content-hash deduplication across all three test classes.

## Verified setup, 2026-09-13

Class 001 has been imported: eight originals, sixteen proof JPEGs, eight web
images and eight high-resolution images. All 32 output files were delivered to
local Ferraraphoto's NAS-backed directories and verified byte-for-byte. Classes
002 and 003 each retain eight pending imports. The normal browser controls
started Horizon and updated the status live; workers remain running.

A real same-class retry kept the existing photo ID/proof number and did not
consume another proof number. The unique SHA index is installed in the local
catalog. Settings uses the smaller 20 MP sample; the 45 MP preview failure is
tracked in `docs/SHOW_PREP_TODO.md`. Large originals remain in the test dataset.

The reset preserved login, configuration, watermarks and storage profiles.
Old working images and preview caches were checksum-verified into
`/Volumes/Public/proofgen-dev/_reset-backups/20260913` before being removed from
the laptop. The small SQLite snapshot remains local at
`storage/dev-reset-backups/20260913/before-reset.sqlite` (ignored by Git).
Neither the database nor photographs are included in the dataset manifest.

Uploads require rsync 3. The runner selects Homebrew rsync directly because
Herd's PATH can select Apple's openrsync, which copies files without the
itemized completion evidence needed for upload timestamps. `RSYNC_BINARY` can
specify another rsync 3 executable.

This verifies Proofgen's import, image generation and local file delivery. It
does not create a new public-facing show record in Ferraraphoto or exercise a
customer purchase. Garage/S3 migration remains a separate task.

Class 002 was subsequently imported by the operator and completed after the
[large-import recovery fix](../reviews/2026-09-13-large-import-recovery.md). It
also has eight fully generated/delivered photos; only class 003 remains pending.
