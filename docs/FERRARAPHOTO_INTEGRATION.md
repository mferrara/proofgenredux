# Proofgen ↔ Ferraraphoto Integration

The two applications are filesystem-coupled today: proofgen (this app) generates and rsync-uploads
files to specific paths under the ferraraphoto deployment, and ferraraphoto reads from those paths
when serving the public site, processing PayPal IPNs, and emailing instant-delivery products to
customers.

This document tracks the **current state of the integration** and the **work still ahead** to make
it a first-class API-driven, cloud-storage-backed integration. The bulk of Phase 1 (instant
delivery + show linking + pre-upload validation) shipped between 2026-04 and 2026-05 and is now
production-ready or already in production.

## Repos

- **proofgen** (this repo) — Laravel 13 / Livewire 4 / Flux 2 / PHP 8.4. Local desktop app run on macOS via Herd.
  Lives at `/Users/mikeferrara/Herd/proofgenredux`.
- **ferraraphoto** — Laravel 4.2 (PHP 7.4) public-facing site. Branch `php7.4-migration` is ahead of
  `master` by 19+ commits as of 2026-04. Lives at `/Users/mikeferrara/Documents/code/ferraraphoto`.
  Production deploy serves from `https://ferraraphoto.com` (and a `staging.ferraraphoto.com` env).
  **Decision (2026-05):** ferraraphoto stays on L4.2 / PHP 7.4 for the foreseeable future.
  Modernization is out of scope; the Phase 2 work below adds new code in the existing app.

## Current state (post Phase 1)

```
┌────────────────┐         rsync         ┌──────────────────────────┐
│   proofgen     │  ───────────────────▶ │      ferraraphoto        │
│   (your Mac)   │   ssh forge@host      │  (Forge server)          │
│                │                       │                          │
│ /Users/        │                       │  /home/forge/            │
│   mikeferrara/ │                       │   ferraraphoto.com/      │
│   shows/       │                       │                          │
│    {show}/     │                       │   public/                │
│      {class}/  │                       │     proofs/{slug}/{n}/   │ ← proofs
│        proofs/ │ ──── proofs           │                          │
│        web_    │                       │   app/storage/           │
│        images/ │ ──── web images       │     web_images/{slug}/{n}/_web.jpg     ← web
│        highres │                       │     high_res_images/{slug}/{n}/_highres.jpg ← high-res
│        _images/│ ──── highres images   │                          │
│        originals/                      │                          │
└────────────────┘                       └──────────────────────────┘
```

There is **no API or DB integration yet**. Show ↔ show identity is maintained by hand-shared
convention: the **show abbreviation/slug must match exactly** between the two systems
(e.g. `2026R12` on both sides), with proofgen-side `Show.ferraraphoto_show_slug` available as an
override when the slugs drift.

### Path mappings

These three SFTP paths are configured in proofgen's settings (Settings → Server (SFTP) and the
underlying `proofgen.sftp.*` config):

| Local source (under the `fullsize` disk root) | proofgen config key        | Remote destination on ferraraphoto host                            |
|-----------------------------------------------|----------------------------|--------------------------------------------------------------------|
| `proofs/{show}/{class}/`                      | `sftp.path`                | `/home/forge/<host>/public/proofs/{show}/{class}/`                 |
| `web_images/{show}/{class}/`                  | `sftp.web_images_path`     | `/home/forge/<host>/app/storage/web_images/{show}/{class}/`        |
| `highres_images/{show}/{class}/`              | `sftp.highres_images_path` | `/home/forge/<host>/app/storage/high_res_images/{show}/{class}/`   |

### File naming

| File type        | Filename pattern                              | Source                                    |
|------------------|-----------------------------------------------|-------------------------------------------|
| Small thumbnail  | `{proof_number}_thm.jpg`                      | proofgen `thumbnails.small.suffix`        |
| Large thumbnail  | `{proof_number}_std.jpg`                      | proofgen `thumbnails.large.suffix`        |
| Web image        | `{proof_number}_web.jpg`                      | hardcoded on both sides                   |
| Highres image    | `{proof_number}_highres.jpg`                  | hardcoded on both sides                   |

## What shipped in Phase 1 (2026-04 → 2026-05)

All of the items below are committed/merged on the `php7.4-migration` branch on ferraraphoto and
on `main` for proofgen. Several are already deployed to production; the rest deploy when the
branch merges.

- **Instant high-res delivery** mirroring the existing instant web image flow. New
  `instant_high_res_images` flag on shows, new `image_type` column on products, IpnProcessor
  rewrite consolidating web/high-res paths and adding null-safety.
- **Per-delivery tracking** in a new `image_deliveries` table (`pending` → `sent` / `failed`),
  with admin retry button on `/admin/deliveries`.
- **Path consolidation** on ferraraphoto Photo / Show models (single parameterized
  `Photo::sendImageToClient($email, $type)`, single private `Show::syncInstantDeliveryFlag`).
- **Upload ordering** on proofgen: class- and show-level uploads now `Bus::chain` proofs before
  web/highres so the public-facing files appear before the paid digital products are delivered.
- **Show-to-show linking override** on proofgen: nullable `ferraraphoto_show_slug` column on
  `shows`, surfaced via the Show view's "Ferraraphoto target" panel. Pencil edit. All remote-path
  call sites (`Show::rsync*Command`, `ShowClass::getRemote*PathAttribute`,
  `FerraraphotoTargetVerifier`, `pendingProofUploads`/etc.) consult the slug accessor; local
  paths still use the proofgen show id (matches the on-disk directory).
- **Pre-upload target verification**: `App\Services\FerraraphotoTargetVerifier` checks all three
  remote disks for a given show or class via `Storage::disk()->exists()`. Surfaced as a
  lazy-loaded panel on `ShowViewComponent` / `ClassViewComponent` and called by the upload jobs
  (5-minute cache to collapse the proofs→web→highres chain into one round-trip set).
- **Local dev integration via `local` transport driver**: `proofgen.sftp.driver = local` swaps
  rsync-over-SSH for plain `rsync` between local directories. `ConfigurationServiceProvider`
  rewrites the three `remote_*` Storage disks to local-driver at boot, so `Storage::disk()`
  call sites don't branch.
- **High-res product seeder + image_type backfill** on ferraraphoto so fresh installs ship with
  both web and high-res instant delivery products correctly typed.
- **Failed-delivery retry UI** at `/admin/deliveries`.

There's still one production deployment task owed for Phase 1 (called out in
`INSTANT_DELIVERY_WORK.md` on the ferraraphoto side):

- Pull/merge the `php7.4-migration` branch on the production ferraraphoto host
- Run `php artisan migrate`
- Set `image_type = web` on the existing instant delivery web product via the admin
- Optionally create a high-res instant delivery product (admin → Pricing → Products) and assign
  it to the relevant pricelists. The product `name` must contain both "high resolution" (or
  "high res") and "instant" so `IpnProcessor::detectImageType()` matches it. Or seed it.

## What's next: Phase 2 — first-class API integration + cloud storage

Phase 2 replaces the rsync convention with a real HTTP API and moves proof/web/high-res storage
from the production server's attached volume to cloud object storage (B2 / S3-compatible /
MinIO). The migration is staged so existing local-stored shows keep working while new shows
land in the cloud.

The detailed specs for each work stream live in dedicated docs (created alongside this update):

- **Storage profile architecture** — `docs/STORAGE_PROFILES.md` (proofgen + ferraraphoto)
- **Ferraraphoto API surface** — `docs/FERRARAPHOTO_API_SPEC.md` (the contract Codex will build)
- **Proofgen-side API client + storage refactor** — `docs/PROOFGEN_API_CLIENT.md`
- **Bulk migration of legacy proofs** — `docs/PROOF_MIGRATION_PLAN.md`
- **Customer-facing deliverables page** — `docs/CUSTOMER_DELIVERABLES.md`

### Phase 2 design summary

1. **Multi-disk-profile storage.** Both apps gain a `storage_profiles` table. A profile is a
   fingerprinted (driver + bucket + region + endpoint) tuple. Credentials still live in `.env`
   under a per-profile prefix (`PROFILE_<NAME>_KEY`, `PROFILE_<NAME>_SECRET`, etc.); the table
   stores only non-secret identity. Photos / shows / proofs are pinned to the profile they were
   first written under, so legacy data stays readable when a new "active" profile is configured.
   Profile identity is shared across the two apps via the API push so they always agree on
   which profile holds a given show.

2. **API direction: proofgen → ferraraphoto.** When proofgen finishes generating + uploading,
   it POSTs show/class/photo metadata + the storage profile reference to ferraraphoto. The IPN
   processor and the public site read files via `Storage::disk($profile)->...` instead of the
   filesystem. ferraraphoto exposes admin-facing endpoints for pull-back ("re-sync this show",
   "tell me delivery status for this order") but proofgen is the publisher.

3. **Customer-facing delivery via ferraraphoto routes.** Cloud-stored web/high-res files are
   never linked directly. Instead, after IPN, the customer gets:
   - **Web image**: email attachment (~1-3MB) PLUS a "View all your deliverables" CTA pointing
     at a tokenized `/deliveries/{token}` page.
   - **High-res image**: email-only CTA (no attachment, sizes are too large for reliable email
     delivery), pointing at the same `/deliveries/{token}` page.

   The deliverables page lists all paid deliverables for that order and serves downloads through
   ferraraphoto's own routes — files stream from cloud through the app, letting us record
   per-photo download counts / last-downloaded timestamps and keep the storage layer invisible
   to customers.

4. **Bulk migration of legacy proofs.** Existing proofs live on the production server's attached
   volume. They migrate to the active cloud profile via rclone (with parallel-safe verify) and
   their associated photos get pinned to the new profile. Originals are **not deleted** during
   migration — that's a manual confirmation step after the cloud-served path is confirmed
   working.

5. **Mixed-profile coexistence.** During the migration window, both rsync-and-local and
   cloud profiles are valid. The IPN processor + deliverables page key off the photo's pinned
   profile and use the matching disk. No global cutover.

## Useful files to read first when picking this up

In **proofgen**:
- `app/Models/Show.php` (`ferraraphoto_slug` accessor, `rsync*Command` builders,
  `pendingProofUploads`/`proofUploads`/equivalents for web + highres)
- `app/Models/ShowClass.php` (per-class rsync + upload tracking)
- `app/Jobs/ShowClass/UploadProofs.php` / `UploadWebImages.php` / `UploadHighresImages.php`
- `app/Services/FerraraphotoTargetVerifier.php`
- `app/Services/PathResolver.php` (where remote paths get built)
- `app/Services/Transport/RsyncCommandBuilder.php` (current `sftp` vs `local` driver)
- `app/Providers/ConfigurationServiceProvider.php` (`applyTransportDriver` rewrites Storage disks)
- `config/proofgen.php` (`sftp.*` block) + `config/filesystems.php` (`remote_*` disks)
- Settings → "Server (SFTP)" UI for the runtime values

In **ferraraphoto**:
- `app/Acme/IpnProcessor.php` (the actual delivery logic)
- `app/models/Photo.php` (`webImagePath()` / `highResImagePath()` / `sendImageToClient()`)
- `app/models/Show.php::syncInstantDeliveryFlag()` and `importClasses()`
- `app/models/ShowClass.php::importPhotos()`
- `app/models/ImageDelivery.php`
- `app/controllers/AdminDeliveryController.php`
- `app/routes-api.php` (currently a stub — Phase 2 builds it out)
- `INSTANT_DELIVERY_WORK.md` at the repo root (status of Phase 1 deployment)

---

*Living document — last updated 2026-05-09. Phase 1 closed; Phase 2 specs live in the sibling
docs in this directory. Update when Phase 2 work lands or when path conventions / storage
profiles change.*
