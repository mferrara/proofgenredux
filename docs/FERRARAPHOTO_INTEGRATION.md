# Proofgen and Ferraraphoto integration

## Verified scope — September 13, 2026

Proofgen runs on the operator's MacBook via Herd. Ferraraphoto is the separate
public photography website. The latest local rehearsal used rsync's **local**
transport into the sibling Ferraraphoto checkout's NAS-backed directories.
It verified file generation/delivery, not public show creation, purchases, email,
production deployment, or S3 cutover.

The checkouts on this Mac are `Documents/code/proofgenredux` and
`Documents/code/ferraraphoto` under `/Users/mikeferrara`. Older references to
`Herd/proofgenredux` and April branch-ahead counts are not current setup guidance.
Ferraraphoto's Laravel 4.2/PHP 7.4 design documents are retained below; this pass
does not re-audit that repository or claim its deployed version.

## Current legacy file delivery

Proofgen generates derivatives in separate top-level trees under the fullsize
root. `proofgen.sftp.driver=sftp` uses rsync over SSH; `local` uses plain local
rsync. Both require rsync 3 output for upload tracking. `RsyncRunner` selects
Homebrew rsync unless `RSYNC_BINARY` is configured.

| Source under fullsize root | Destination root setting | Ferraraphoto layout |
|---|---|---|
| `proofs/{show}/{class}/` | `proofgen.sftp.path` | `public/proofs/{slug}/{class}/` |
| `web_images/{show}/{class}/` | `proofgen.sftp.web_images_path` | `app/storage/web_images/{slug}/{class}/` |
| `highres_images/{show}/{class}/` | `proofgen.sftp.highres_images_path` | `app/storage/high_res_images/{slug}/{class}/` |

Local paths use the Proofgen show ID. Destination paths use
`Show.ferraraphoto_slug`, which honors `ferraraphoto_show_slug` or falls back to
that ID. Target verification resolves string class IDs through ShowClass records;
underscores in show/class names are not separators to guess from.

Derivative filenames are always JPEGs: `{proof_number}{suffix}.jpg`, including
when the original is `.jpeg`. Default suffixes are `_thm`, `_std`, `_web`, and
`_highres`; Proofgen reads all four from configuration. Confirm Ferraraphoto's
expectations before changing suffixes.

Upload controls live on show/class pages; configuration is in Settings → Legacy
SFTP and `/config/server`. Explicit combined class uploads send proofs, then web,
then highres. Automatic generation can enqueue each kind as its class completes.
Uploads run on the dedicated `uploads` connection/queue. Successful rsync output
is the completion evidence; non-zero exits fail visibly and prevent subsequent
metadata steps. See [the pipeline](photo-pipeline.md) and
[worker checklist](SHOW_PREP_CHECKLIST.md) for budgets and timestamp semantics.

This development setup resolves all three destination roots into
`/Volumes/Public/proofgen-dev/delivery`. Originals and outputs also live on the
NAS. [The dataset guide](datasets/README.md) describes the 72 imported sample photos.

## Storage and API code already present

These are implemented source paths, not work that must be started from scratch:

- `app/Models/StorageProfile.php` and storage-profile/show migrations.
- `app/Services/Storage/`: detection, fingerprinting, disk resolution, health
  checks, and show binding. The legacy profile resolves a disk per content type.
- `app/Livewire/StorageProfilesComponent.php`: profile management UI.
- `app/Services/Ferraraphoto/FerraraphotoApiClient.php`: HTTP metadata client,
  configured by `FERRARAPHOTO_API_BASE` and `FERRARAPHOTO_API_TOKEN`.
- `EnsureFerraraphotoShow` → `UploadDerivedFiles` → `PushPhotoMetadata`: the explicit
  upload chain. Legacy show/metadata sync skips when no API token is configured;
  a configured token can enable API calls even for legacy storage.
- `app/Services/Migration/` and migration commands: inventory, copies, verification,
  and cutover machinery. Their existence is not a completed storage migration.

Local working originals still use the fullsize filesystem. Configuring an S3
profile does not turn the ingest directory into an object-storage path.

## Migration direction and retained designs

The operator's current scope is simple: build/configure the required S3-compatible
storage support, copy existing server files into the bucket(s), then flip the
switch. Customer-facing staged cutover, rollback machinery, and automated source
retention are not requirements for this work. Existing machinery should be
reviewed for reuse or simplification against that scope.

- [Flower #3681](https://flower.legitphp.com/briefs/3681) tracks the storage migration.
- [Storage profiles](STORAGE_PROFILES.md) and [Proofgen API client](PROOFGEN_API_CLIENT.md)
  are earlier design references with code now present; their examples are not
  authoritative copies of the implementation.
- [Proof migration plan](PROOF_MIGRATION_PLAN.md) is a superseded rollout proposal,
  not an approved command sequence.
- [Ferraraphoto API specification](FERRARAPHOTO_API_SPEC.md) and
  [customer deliverables design](CUSTOMER_DELIVERABLES.md) describe the companion
  app's intended contract. Confirm its source/deployment separately before relying
  on endpoint or customer-flow availability.

No remote deployment, bucket transfer, API sync, or purchase was performed during
this documentation review.
