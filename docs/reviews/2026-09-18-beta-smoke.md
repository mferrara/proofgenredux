# Beta smoke test — September 18, 2026

Target: `https://beta.ferraraphoto.com/api/v1`. User authorized a small
throwaway `26Test01` show against the future production database and shared
production image filesystem.

## Result

Passed the scoped import/generation/delivery/readback checks:

- One show, one class (`001`), two photos (`26TEST01_00001`, `26TEST01_00002`).
- Two fresh staged NAS originals imported through PhotoService; four outputs each.
- Exactly eight JPEGs uploaded, totaling 3,982,437 bytes. All eight remote SHA-256
  checksums match local output. Four public proof responses also match byte-for-byte.
- Beta API readback reports one class/two photos, `legacy-local`, and both instant
  web/highres flags enabled. Class HTML links both photos and thumbnails.
- Show, class, and first photo pages return HTTP 200.
- Gallery's actual DeliverableService resolves and streams all four web/highres
  outputs with matching SHA-256. Used unsaved ImageDelivery value objects: no
  order, delivery row, payment, email, or download-counter mutation.

Gallery: <https://beta.ferraraphoto.com/show-photos/26Test01/001/>
Local: <http://proofgenredux.test/show/26Test01/class/001>

## Effective local setup

Both ignored `.env` and saved Configuration values now agree:

- API origin `https://beta.ferraraphoto.com` (client appends `/api/v1`).
- User-supplied API token saved privately; not included in this report or Git.
- SFTP/rsync: `forge@<origin host>`, port 22, existing local SSH key.
- Proofs `/mnt/photo-storage/proofs`.
- Web `/mnt/photo-storage/web_images`.
- Highres `/mnt/photo-storage/highres_images`.

Those paths were verified against beta's GALLERY_LEGACY_* roots. Prior local
settings used an obsolete SSH address and staging paths; those are replaced.
Existing local shows share these transport settings, so manual Upload now targets
this server. Automatic uploads remain OFF; Horizon remains stopped; all three
local shows have empty waiting/active/delayed queues. Existing 72 photos were
not reuploaded. Local catalog now contains 74 photos.

## Fixes discovered

1. Beta seeds `legacy-local` but its custom-profile registration validation rejects
   the seed's sentinel fingerprint and null root. EnsureFerraraphotoShow now uses
   the existing built-in profile directly and still registers custom profiles.
   No beta source, profile row, or server configuration was changed.
2. An explicit `SFTP_PORT` environment value was a string, rejected by Flysystem's
   typed SFTP constructor. All three disk configurations now cast it to integer.

Validation in an isolated checkout: 25 tests, 76 assertions; Pint passes.
The first profile rejection created no remote show. The port failure occurred
before file transfer; retry reused the same show/class. Final count is two photos.

## Limits and observations

This was a bounded synchronous run of the normal services and delivery coordinator,
not a Horizon/UI lifecycle or checkout/payment test. No forced failure/replay or
extra mock classes were added. The test show remains available for inspection.
A read-only SSH checksum request timed out once and succeeded on retry. Python
urllib received HTTP 403 for a proof; curl fetched all proof routes successfully.
No server/Cloudflare configuration was changed to accommodate either client.

A Gallery-authoritative delivery handshake is proposed separately in
[the contract handoff](../GALLERY_DELIVERY_HANDSHAKE.md); it is not implemented.
