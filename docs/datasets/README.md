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

## Verified setup, September 13, 2026

All three NASDEMO26 classes are imported and fully generated/delivered: 24 photos,
96 output JPEGs. The subsequent `NAS_LOAD26` rehearsal added 48 distinct originals
across `class_A` and `class_B`, with 192 generated/delivered JPEGs. Both shows remain
available; the local catalog contains 72 photos. See
[the final rehearsal](../reviews/2026-09-13-show-prep-finish.md).

[nas_load26.json](nas_load26.json) preserves the second selection manifest: source
paths, byte sizes, SHA1 values and camera-style staging filenames. Its 48 originals
occupy about 457 MiB and do not repeat the first dataset's hashes. Neither manifest
contains photographs, credentials or a database. Copy only selected files into a
new working show; do not re-stage over these imported shows automatically.

A same-class retry kept the photo ID/proof number without consuming another
number. Global SHA1 uniqueness is installed. The 45 MP preview failure and long
watermark clipping were fixed; Settings retains a modest 20 MP sample for routine
use. Large originals remain in the import dataset.

The authorized reset preserved login, configuration, watermarks and profiles.
Old working files were checksum-verified into
`/Volumes/Public/proofgen-dev/_reset-backups/20260913` before removal from the laptop.
The ignored SQLite snapshot is
`storage/dev-reset-backups/20260913/before-reset.sqlite`. Archives were disabled for
the latest NAS rehearsal; do not mistake a configured archive root for enabled backups.

Uploads require rsync 3; the runner selects Homebrew rsync because Herd's PATH can
select Apple's openrsync. `RSYNC_BINARY` can specify another rsync 3 executable.

This validates import, generation and local file delivery. It does not create a
public-facing show in Ferraraphoto or exercise a purchase. S3 migration remains
separate. Optional sample-bucket commands (`proofgen:download-samples` and
`proofgen:upload-samples`) still exist, but are not used by this NAS setup or the
regular isolated test suite. Bucket configuration is in `config/filesystems.php`;
normal tests force automatic downloads off.
