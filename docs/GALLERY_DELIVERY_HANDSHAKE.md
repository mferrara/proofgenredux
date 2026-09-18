# Proposed Gallery delivery handshake

Proposal for coordination, September 18, 2026. Not implemented on either side
by this Proofgen session. Keep this first version focused on existing rsync/SSH
filesystem delivery; S3 delivery can extend the transport contract later.

## Endpoint

`GET /api/v1/delivery-target?show_slug=26Test01`

Use the existing Proofgen bearer authentication and `{ "data": ... }` envelope.
Read-only: no show creation, profile registration, filesystem writes, or health
probe side effects. Return HTTP 200 for an existing show **or** a valid new slug.
For an existing show, resolve its pinned profile. For a new slug, resolve Gallery's
active writable default profile. Never silently replace an existing show's pin
with the current default. Reject invalid slugs (422) or unavailable/unconfigured
upload targets (503) using the existing error envelope.

Example (target_revision below is illustrative, not an actual fingerprint):

```json
{
  "data": {
    "schema_version": 1,
    "target_revision": "sha256:<hash-of-effective-delivery-settings>",
    "show": {
      "slug": "26Test01",
      "exists": true,
      "storage_profile_id": "legacy-local"
    },
    "storage_profile": {
      "id": "legacy-local",
      "label": "Server filesystem",
      "driver": "local",
      "fingerprint": "legacy-local-v1",
      "writable": true
    },
    "transport": {
      "driver": "rsync_ssh",
      "host": "<origin host>",
      "port": 22,
      "username": "forge"
    },
    "layout": "proofgen-v1",
    "destinations": {
      "proofs": {
        "directory": "/mnt/photo-storage/proofs/26Test01",
        "key_prefix": "proofs/26Test01/"
      },
      "web_images": {
        "directory": "/mnt/photo-storage/web_images/26Test01",
        "key_prefix": "web_images/26Test01/"
      },
      "highres_images": {
        "directory": "/mnt/photo-storage/highres_images/26Test01",
        "key_prefix": "highres_images/26Test01/"
      }
    }
  }
}
```

## Meaning and behavior

- Gallery owns the profile, connection destination, and directories. Return the
  SSH-addressable absolute paths that correspond to its configured media roots.
  Configure the upload hostname explicitly; do not derive it from the HTTPS host
  (beta is behind Cloudflare). Profile driver `local` means Gallery's filesystem;
  Proofgen reaches it through `rsync_ssh`.
- Each directory is already scoped to the **remote show slug**. Proofgen appends
  the class number and output filename, without adding the show slug again.
- `proofgen-v1` means `<class_number>/<proof_number>_<suffix>.jpg`, with `thm` and
  `std` under proofs, `web` under web_images, and `highres` under highres_images.
  Class numbers remain strings, including leading zeros and underscores.
- `key_prefix` is the metadata namespace, not an absolute filesystem path.
  Example: `/mnt/photo-storage/web_images/26Test01/001/26TEST01_00001_web.jpg`
  corresponds to `web_images/26Test01/001/26TEST01_00001_web.jpg`.
- `storage_profile.fingerprint` is the existing profile identity, including the
  literal built-in `legacy-local-v1`; it does not describe current upload roots.
  `target_revision` separately changes when effective profile/transport/layout/
  destination settings change. It must be stable across repeated reads and
  exclude timestamps and `show.exists` (creation alone does not change a target).
- Return no SSH private key, bearer token, password, or bucket secret. Proofgen
  retains its local credentials and existing SSH host-key verification.

Proofgen should cache this response **per API origin and remote show slug**, pin
the returned profile, and create the show using that profile ID. This replaces
choosing a new show's remote profile from Proofgen's own global default. Existing
show readback already includes storage_profile_id; the handshake adds the actual
transport and destination contract needed to use it.

Fetch on setup and before each class delivery, not per image. Compare with the
saved target; a changed profile/host/path must be shown to the operator before
switching an existing show's destination. Do not silently fall back to global
upload paths if the handshake fails. Keep the last cached response for display.
An unavailable/writable=false target stops before rsync.

Minimal initial acceptance checks: existing pinned show; new slug using Gallery's
default; leading-zero/underscore class paths; non-default remote show slug;
changed destination detection; missing/unwritable configuration; an unsupported
schema/layout/transport; no credentials in responses. Repeat the same two-photo
smoke after both implementations are ready, without creating more shows.
