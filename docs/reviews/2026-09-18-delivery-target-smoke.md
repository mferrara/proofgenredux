# Delivery-target smoke test — September 18, 2026

Target: `https://beta.ferraraphoto.com/api/v1`, existing throwaway show
`26Test01` (one class `001`, two photos). Proofgen `main` at `e8e84d6`, local
database migrated (backup `database/database.sqlite.pre-delivery-target-*.bak`).
No show, class, or file was created for this run.

## What was run

Both photos were already stamped as uploaded by the [earlier smoke](2026-09-18-beta-smoke.md),
so a plain delivery would have skipped rsync. The six upload stamps on those two
photos were cleared, then `DeliverClassOutputs('26Test01_001')` ran synchronously
(Horizon stopped, automatic uploads OFF).

## Result: passed

- Handshake fetched and saved on the show: source `gallery`, `forge@<origin host>:22`,
  `/mnt/photo-storage/{proofs,web_images,highres_images}/26Test01`, profile
  `legacy-local`. Identical to this Mac's local SFTP settings (no drift).
- The exists check and rsync both ran against Gallery's destination. rsync
  confirmed all eight files already in place: proofs 4, web 2, highres 2 synced,
  **0 transferred**. All six stamps were set again; metadata push succeeded.
  Total 11.3 s.
- Steady state: a second refresh with uploads present and a saved baseline raised
  no "destination changed". Pending dry runs through the saved target report
  0/0/0. The target verifier reports all three class directories present.
- Show list: `26Test01` is reported as on the website. Gallery readback: one
  class, two photos, `legacy-local`. Class page HTTP 200.

## Limits

No bytes moved: the files were already there, so this proves the destination and
the whole control path, not a fresh transfer (the rsync command itself is
unchanged apart from where the destination comes from). Not exercised against a
live server: the outage fallback, the changed-destination block, a missing
show / 409, and the two pickers in a browser. Those are covered by faked tests only.
