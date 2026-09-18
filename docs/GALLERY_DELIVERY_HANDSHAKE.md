# Gallery delivery handshake

Gallery (the website) owns where a show's files are delivered. Proofgen asks
before each class delivery instead of carrying its own copy of the server's
host and paths. Contract owner: flower brief 3832 (Gallery side); Proofgen
consumer: brief 3833.

Status, September 18, 2026: **both sides implemented.** Gallery's endpoints are
live on beta (gallery commit 21868fa; its authoritative contract doc is
`docs/PROOFGEN_DELIVERY_TARGET.md` in the gallery repo). Proofgen's side is on
branch `delivery-target-handshake`, tested against faked responses and checked
read-only against beta: the live `26Test01` answer parses and equals the current
local settings. A full upload smoke through the new path has not been run yet.
Against an older Gallery, Proofgen gets a 404 and behaves exactly as before.

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
  Gallery has none configured it returns `"transport": null` (key present), and
  Proofgen reaches Gallery's directories with its own local host/port/user.
- A show pinned to a non-`legacy-local` profile gets `"transport": null` **and**
  `"destinations": null`: that show is not delivered by rsync. Proofgen stops
  rather than guessing.
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
| 200 with `transport: null` (or absent) | Gallery's directories, local host/port/user |
| 503, other 5xx, network error - show **has** a saved Gallery answer for this API origin | log a warning and deliver to that saved answer |
| 503, other 5xx, network error - nothing saved | stop before rsync; the job retries on its normal backoff |
| 401/403/422, or unknown `schema_version` / `layout` / `transport.driver`, non-`local` profile driver, null/missing/relative directory | stop before rsync; fails immediately, no retries, even with a saved answer |

Proofgen never falls back to **local settings** on an error. Uploading to a
stale destination "succeeds" and the website shows broken images - the silent
failure this handshake exists to remove. The outage rule is different: it reuses
what Gallery itself last said for this show, so a deploy or a slow response
mid-show does not switch destinations. Note the rest of a delivery still talks
to the API (show/class sync before rsync, photo metadata after), so during an
outage those steps retry on the normal backoff as they always have.

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

## Shows are created on the website

`GET /api/v1/shows?limit=&q=` → `{ data: [{ slug, name, start_date, end_date,
storage_profile_id, class_count, photo_count, hidden }] }`, newest first
(default limit 100, max 500). Code: `app/Services/Ferraraphoto/WebsiteShows.php`.

- **Proofgen never creates a show once this list answers.** Before syncing,
  `EnsureFerraraphotoShow` reads the show; if it is missing and the list endpoint
  exists, delivery fails immediately with "create it on the website first" and
  no `POST /shows` is sent. A `409 show_creation_disabled` from `POST /shows`
  (Gallery's `api_show_creation` setting turned off) gets the same message.
  Existing shows keep syncing. On an older Gallery (list → 404) Proofgen creates
  shows through the API exactly as before.
- **Create Show** (home page) offers the website's shows that have no local
  folder yet, with a link to the website's New show page. Typing a name is still
  possible ("Not listed yet?") so work can start offline; uploads wait until a
  show with that exact name exists on the website.
- **Show page → Ferraraphoto target**: an "on website" / "not on website" badge,
  and the slug override is a picker of website shows instead of free text.
  Re-check refreshes it after creating the show on the website.
- The list is fetched on page load (`wire:init`) and by Re-check, cached five
  minutes; page polling only reads the cache. With no token, an older Gallery,
  or Gallery unreachable, everything falls back to the free-typed behavior.

## Not done yet

- A full two-photo `26Test01` upload smoke through the handshake path against
  beta (needs this install's database migrated first).
- Shrinking Settings → Legacy SFTP to the key path plus a collapsed fallback
  group.
- The legacy string builders in `app/Proofgen/Show*.php`, the rclone migration
  command builder, and the Legacy SFTP connection page still read local settings.
- Choosing a new show's storage profile from the handshake (only one rsync
  profile exists today).
