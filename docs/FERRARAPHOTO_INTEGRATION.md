# Proofgen ↔ Ferraraphoto Integration

The two applications are filesystem-coupled today: proofgen (this app) generates and rsync-uploads
files to specific paths under the ferraraphoto deployment, and ferraraphoto reads from those
paths when serving the public site, processing PayPal IPNs, and (in the case of instant-delivery
products) emailing files to the customer.

## Repos

- **proofgen** (this repo) — Laravel 11 / Livewire 3 / Flux UI. Local desktop app run on macOS via Herd.
  Lives at `/Users/mikeferrara/Herd/proofgenredux`.
- **ferraraphoto** — Laravel 4.2 (PHP 7.4) public-facing site. Branch `php7.4-migration` is ahead of
  `master` by 19+ commits as of 2026-04. Lives at `/Users/mikeferrara/Documents/code/ferraraphoto`.
  Production deploy serves from `https://ferraraphoto.com` (and a `staging.ferraraphoto.com` env).

## How the apps relate today

There is **no API or DB integration**. The apps share state only via the filesystem on the
ferraraphoto server, accessed by proofgen through SFTP/rsync as user `forge`. Show ↔ show
identity is maintained by a hand-shared convention: the **show abbreviation/slug must match
exactly** between the two systems (e.g. `2026R12` on both sides).

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

## Path mappings

These three SFTP paths are configured in proofgen's settings (Settings → Server (SFTP) and the
underlying `proofgen.sftp.*` config):

| Local source (under `fullsize_home_dir`) | proofgen config key            | Remote destination on ferraraphoto host                            |
|------------------------------------------|--------------------------------|--------------------------------------------------------------------|
| `{show}/{class}/proofs/`                 | `sftp.path`                    | `/home/forge/<host>/public/proofs/{show}/{class}/`                 |
| `{show}/{class}/web_images/`             | `sftp.web_images_path`         | `/home/forge/<host>/app/storage/web_images/{show}/{class}/`        |
| `{show}/{class}/highres_images/`         | `sftp.highres_images_path`     | `/home/forge/<host>/app/storage/high_res_images/{show}/{class}/`   |

The current values point at staging:
- `sftp.path` → `/home/forge/staging.ferraraphoto.com/public/proofs`
- `sftp.web_images_path` → `/home/forge/staging.ferraraphoto.com/app/storage/web_images`
- `sftp.highres_images_path` → `/home/forge/staging.ferraraphoto.com/app/storage/high_res_images`

Production paths drop the `staging.` prefix.

## File naming

| File type        | Filename pattern                              | Source                                    |
|------------------|-----------------------------------------------|-------------------------------------------|
| Small thumbnail  | `{proof_number}_thm.jpg`                      | proofgen `thumbnails.small.suffix`        |
| Large thumbnail  | `{proof_number}_std.jpg`                      | proofgen `thumbnails.large.suffix`        |
| Web image        | `{proof_number}_web.jpg`                      | hardcoded on both sides                   |
| Highres image    | `{proof_number}_highres.jpg`                  | hardcoded on both sides                   |

`Photo::webImagePath()` and `Photo::highResImagePath()` on ferraraphoto build absolute paths
using `storage_path()` + `show->slug` + `showclass->class_number` + the suffix above.

## proofgen-side upload code

- **`app/Models/ShowClass.php`** owns the SFTP mechanics:
  - `proofUploads()` / `webImageUploads()` / `highresImageUploads()` — call rsync, parse output, mark photos uploaded.
  - `pendingProofUploads()` / `pendingWebImageUploads()` / `pendingHighresImageUploads()` — same as above with `--dry-run` to preview pending work.
  - `rsyncProofsCommand()` / `rsyncWebImagesCommand()` / `rsyncHighresImagesCommand()` — produce the exact rsync invocation. All three use `rsync -avz --delete -e "ssh -i {private_key}"` and target the `forge@` user.
- **Jobs** in `app/Jobs/ShowClass/`:
  - `UploadProofs` — wraps `proofUploads()`.
  - `UploadWebImages` — wraps `webImageUploads()`.
  - `UploadHighresImages` — wraps `highresImageUploads()`.
  - All three currently land on the `default` queue (Horizon supervisor-1).
- **Trigger UIs**: `ClassViewComponent::uploadPendingProofsAndWebImages()` and the show-level
  equivalent dispatch the three jobs in parallel when there's pending work for each.

## ferraraphoto-side reception

There is no automatic ingestion of uploaded files. ferraraphoto reads files lazily:
- **Proofs**: `Show::importClasses()` (admin-triggered) scans `public/proofs/{slug}/`, creates
  `Showclass` records for each subdirectory, and runs `Showclass::importPhotos()` to ingest the
  per-class HTML index and per-photo files.
- **Web / highres**: `Show::checkForWebImagesPath()` and `Show::checkForHighResImagesPath()` only
  toggle the `instant_web_images` / `instant_high_res_images` boolean flags on the show by checking
  whether `app/storage/web_images/{slug}/` / `app/storage/high_res_images/{slug}/` exists. Triggered
  when an admin views the show page. Per-photo lookup happens at IPN time.
- **Per-photo delivery** (`app/Acme/IpnProcessor.php`): on a successful PayPal IPN containing an
  "instant" product, look up the photo by proof number, build the file path via
  `Photo::webImagePath()` / `Photo::highResImagePath()`, attach the file to the email, and BCC the
  admin. Each delivery is now logged to the `image_deliveries` table (status: `pending` → `sent` /
  `failed`).

## Show / Showclass identity

Show identity is the **slug**:
- proofgen: `Show::id` and `Show::name` both match the directory name (e.g. `2026R12`).
- ferraraphoto: `Show::slug`. Set when creating the show via admin.

Showclass identity is the **class number**:
- proofgen: `ShowClass::id = "{show_id}_{class_number}"`, `ShowClass::name = class_number`.
- ferraraphoto: `Showclass::class_number`, plus a `name` column ("Class # 121" or pulled from the
  Breeze-generated `index.htm`).

There is no enforcement that a show with a given slug exists on both sides before an upload runs.
Operationally this is "the same abbreviation is used both places by convention."

## Phase 1 — Instant Delivery (uncommitted on `php7.4-migration`)

See `INSTANT_DELIVERY_WORK.md` in the ferraraphoto repo for the full status. Summary:
- **Web image instant delivery**: shipping, in production.
- **High-res image instant delivery**: code is written but uncommitted on the
  `php7.4-migration` branch alongside two new migrations (`image_type` on `products`,
  `image_deliveries` table) and an admin "Deliveries" log UI.
- Once committed and migrated, an "Instant High Resolution" product needs to be created in
  the admin (with `image_type = high_res`) and assigned to the relevant pricelists.

## Known gaps / TODOs

1. **Upload ordering** (proofgen): proofs, web images, and highres uploads dispatch in parallel
   today. Per CLAUDE_NOTES.md the intent is to run web/highres _after_ proofs so the customer-
   visible proofs hit the server first. Likely fix: `Bus::chain([UploadProofs, UploadWebImages,
   UploadHighresImages])` instead of three independent `dispatch()` calls.
2. **Show linking** (both sides): there is no enforced link between a proofgen show and a
   ferraraphoto show. The slug-match convention is the only thing keeping them aligned. A future
   improvement could add an explicit `ferraraphoto_show_id` (or `ferraraphoto_show_slug`) column
   on the proofgen `shows` table, populated either by manual selection from a list of shows pulled
   from the ferraraphoto API, or by a fuzzy-match-and-confirm flow.
3. **Pre-upload validation**: nothing currently confirms that a matching show/class exists on the
   ferraraphoto side before rsync runs. Worst case is an upload to a directory that ferraraphoto
   has no record for; in practice that's auto-resolved when the admin scans the show.
4. **Cloud storage migration**: out of scope. Notes in the user message: a future direction is
   pushing to object storage and POSTing metadata to ferraraphoto, which would let ferraraphoto
   serve files from the bucket. Not relevant to the current task.
5. **High-res product seeder**: the Pricing seeder doesn't include a high-res instant delivery
   product. After the high-res branch is committed, either create one via admin or extend the
   seeder.
6. **Failed-delivery retry UI**: the `image_deliveries` table records `failed` rows but there is
   no admin button to retry. Documented as a future enhancement in INSTANT_DELIVERY_WORK.md.

## Useful files to read first when picking this up

In **proofgen**:
- `app/Models/ShowClass.php` (rsync builders and upload methods near the bottom)
- `app/Jobs/ShowClass/UploadProofs.php`, `UploadWebImages.php`, `UploadHighresImages.php`
- `app/Livewire/ClassViewComponent.php::uploadPendingProofsAndWebImages()`
- `config/horizon.php` (queue layout)
- The Settings → "Server (SFTP)" section for the runtime values

In **ferraraphoto**:
- `app/Acme/IpnProcessor.php` (the actual delivery logic)
- `app/models/Photo.php::webImagePath()` / `highResImagePath()` / `sendImageToClient()`
- `app/models/Show.php::syncInstantDeliveryFlag()` and `importClasses()`
- `app/models/ShowClass.php::importPhotos()`
- `INSTANT_DELIVERY_WORK.md` at the repo root (status of the uncommitted high-res work)

---

*Living document — last updated 2026-05-02. Update when path conventions change, when a real
API/DB linkage is added, or when work in `INSTANT_DELIVERY_WORK.md` lands.*
