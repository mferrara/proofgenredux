# Gallery delivery handshake

Gallery (the website) owns where a show's files are delivered. Proofgen asks
before each class delivery instead of carrying its own copy of the server's
host and paths. Contract owner: flower brief 3832 (Gallery side); Proofgen
consumer: brief 3833.

Status, September 18, 2026: **Proofgen side implemented** and tested against a
faked endpoint. The Gallery endpoint is being built separately; until it ships,
Proofgen gets a 404 and keeps using its local SFTP settings, exactly as before.

This first version covers the existing rsync/SSH filesystem delivery only. S3
delivery can extend `transport` / `destinations` later.

## Endpoint

`GET /api/v1/delivery-target?show_slug=26Test01`

Existing Proofgen bearer authentication and `{ "data": ... }` / error envelopes.
Read-only: no show creation, profile registration, or filesystem access. HTTP
200 for an existing show **or** a valid new slug. Existing show → its pinned
profile; new slug → Gallery's default writable profile, creating nothing. Never
swap an existing show's pinned profile for the current default. Invalid slug →
422. No usable profile → 503.

```json
{
  "data": {
    "schema_version": 1,
    "show": { "slug": "26Test01", "exists": true, "storage_profile_id": "legacy-local" },
    "storage_profile": { "id": "legacy-local", "label": "Server filesystem", "driver": "local" },
    "transport": { "driver": "rsync_ssh", "host": "<origin host>", "port": 22, "username": "forge" },
    "layout": "proofgen-v1",
    "destinations": {
      "proofs":         { "directory": "/mnt/photo-storage/proofs/26Test01",         "key_prefix": "proofs/26Test01/" },
      "web_images":     { "directory": "/mnt/photo-storage/web_images/26Test01",     "key_prefix": "web_images/26Test01/" },
      "highres_images": { "directory": "/mnt/photo-storage/highres_images/26Test01", "key_prefix": "highres_images/26Test01/" }
    }
  }
}
```

Dropped from the original proposal: `target_revision`, the per-profile
`fingerprint`, and `writable`. Proofgen compares the destination values it
cares about itself, and a Gallery-side flag cannot prove Proofgen's SSH user can
write - rsync succeeding is the truth.

## Meaning

- Each `directory` is an SSH-addressable absolute path **already scoped to the
  remote show slug**. Proofgen appends the class folder and filename and never
  adds the slug again.
- `transport` comes from explicit Gallery configuration, never from the request
  host (beta/production sit behind Cloudflare; SSH must reach the origin). When
  Gallery has none configured it omits `transport`, and Proofgen reaches
  Gallery's directories with its own local host/port/user.
- `proofgen-v1` means `<class_number>/<proof_number>_<suffix>.jpg`: `thm` and
  `std` under proofs, `web` under web_images, `highres` under highres_images.
  Class numbers stay strings, including leading zeros and underscores.
- `key_prefix` is the metadata namespace, not a filesystem path. Proofgen does
  not use it for rsync delivery today.
- No SSH private key, token, password, or bucket secret is ever returned. The
  private key stays on the Proofgen laptop (`SFTP_PATHTOPRIVATEKEY`).

## What Proofgen does with it

Code: `app/Services/Delivery/` (`DeliveryTarget`, `DeliveryTargetResolver`,
`DeliveryTargetDisks`, `DeliveryTargetException`).

- **One fetch per class delivery**, in `DeliverClassOutputs` after the show is
  ensured and before any file moves. Never per image. Only for shows on the
  rsync (`legacy-local`) profile.
- **Everything else reads the saved answer with no network call**: pending
  upload dry runs, the rsync commands, and the show page's Ferraraphoto target
  panel (which shows the host, the three directories, and a "from website" or
  "local settings" badge). The exists/mkdir check before rsync is built from the
  same target as the rsync command, so they cannot point at different places.
- **Saved per show** in `shows.delivery_target`, including the API origin it
  came from.

### Fallback rule

| Situation | Behavior |
| --- | --- |
| 404 (older Gallery without the endpoint) | local SFTP settings |
| No API token, or `TRANSPORT_DRIVER=local` | local SFTP settings, no request made |
| 200 without `transport` | Gallery's directories, local host/port/user |
| 503, other 5xx, network error | stop before rsync; the job retries on its normal backoff |
| 401/403/422, or unknown `schema_version` / `layout` / `transport.driver`, non-`local` profile driver, missing or relative directory | stop before rsync; fails immediately, no retries |

Proofgen never falls back to local settings on an error. Uploading to a stale
destination "succeeds" and the website shows broken images - the silent failure
this handshake exists to remove.

### Destination changes

A new answer is compared with the show's saved one on host, port, user, the
three directories, and the profile id. The API origin is ignored: beta and
production share a filesystem, so the same destination from another origin is
not a change.

- Show has **no uploads yet** → the new destination is adopted silently.
- Show **already uploaded files** → delivery stops without retrying, the new
  answer is kept in `shows.delivery_target_pending`, and the show page shows
  both destinations with an **Accept new destination** button. Files already
  uploaded to the old destination are not moved; after accepting, use **Check**
  under Uploads to find what is missing at the new one.
- The first handshake for a show that only ever used local settings adopts
  Gallery's answer and logs a warning if it differs from the local settings.

## Not done yet

- Show picker backed by `GET /api/v1/shows` (waits for that Gallery endpoint),
  and shrinking Settings → Legacy SFTP to the key path plus a collapsed fallback
  group.
- The legacy string builders in `app/Proofgen/Show*.php`, the rclone migration
  command builder, and the Legacy SFTP connection page still read local settings.
- Choosing a new show's storage profile from the handshake (only one rsync
  profile exists today).
- After Gallery ships the endpoint: repeat the two-photo `26Test01` smoke
  against beta, without creating more shows.
