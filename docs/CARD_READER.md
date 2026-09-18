# Card Reader

**Show page → Card Reader.** Put a card in, say which class it is, press one
button. Built for a photographer who now dumps his own cards between classes.

Flower brief 3862; design input from a real card in `mferrara/proofgen-feedback#9`.

## What it optimizes for

1. The shortest reasonable path from a card leaving the camera to **two verified
   copies** (not counting the card).
2. Never waiting on imports: dumping has its own worker.
3. The ritual everyone relies on: **an empty card means it was safely dumped.**
   A card that still has photos on it means "stop and look".

## How it works

- **Source.** Any storage device under `/Volumes` can be picked, whatever the
  camera brand or reader (built-in SD slot, USB CF/CFexpress readers). Never
  offered: the working drive, the archive drive, network shares, disk images.
  The chosen **reader** is remembered; the next card put into it is selected
  automatically (checked every 2 s while the window is open).
- **Scan (read-only).** JPEGs under `/DCIM` (the whole volume if there is no
  `/DCIM`), sorted by EXIF capture time. Cameras are time-synced and given
  distinct filename prefixes before a show, so capture time is the one ordering
  that holds across bodies. RAW, video, and anything else is counted as "left on
  card" and never touched. Camera catalog folders and macOS `._` files are ignored.
- **One class**, or **Split into multiple classes**: photos are grouped wherever
  the pause between two photos is at least N minutes (default 5, adjustable),
  shown as thumbnail strips (the preview cameras embed in each JPEG, so a whole
  card is a contact sheet without decoding 20 MB files). Click a photo to start
  a new class there; "Join with previous" merges; each group gets a class. Groups
  left without a class stay on the card for another pass.
- **New class** names must be acceptable to the website (letters, numbers, `.`,
  `-`, `_`; start and end with a letter or number; 32 characters at most).
- **Copy** (`App\Services\Cards\CardDumper`, job `App\Jobs\Cards\DumpCard`): each
  file is read from the card **once**, in 4 MB chunks; every chunk is written to
  the class folder and the archive in the same pass while SHA-1 is computed.
  Both files are flushed (`fsync`) and **read back**; only matching read-backs
  count. The class-folder copy is staged in a hidden `.incoming-*` folder and
  renamed into place, so the importer never sees a partial file.
  - Archive copy: `<archive>/<SHOW>/_cards/<CLASS>/<date_time>-<card id>/` with
    the camera's original filenames plus `manifest.json` (hashes, status, card id).
  - A photo whose hash is already in the catalog is not copied to the class
    folder again (the importer would only quarantine it as a duplicate).
  - Same filename, different photo (counter rollover): both are kept.
- **Then**, in this order: queue the normal import per class (switch, on by
  default; off = "just copy cards now, import later"), empty the card (switch,
  **off by default**), eject (switch, on by default; there is also a manual Eject
  button). A failure anywhere leaves the card exactly as it was.

## Emptying the card: the rules

There is no format option. "Empty the card" deletes a photo from the card only when:

- the archive is on and reachable (the switch is disabled otherwise), **and**
- that photo has a read-back-verified copy in the archive **and** either a
  verified copy in the class folder or an already-imported original that is on
  disk with the same hash, **and**
- the card in the reader is still the one that was scanned (volume id re-checked
  immediately before deleting).

Photos that were not assigned to a class, and every non-JPEG, stay on the card.

## Queues

`DumpCard` runs on its own connection and supervisor (`cards`, one worker,
`retry_after` above the job timeout - on the default `redis` connection a job is
handed to a second worker after 90 s and would run twice). A backlog of import or
generation jobs from earlier cards cannot make a card wait for a worker.
**After updating, restart the background workers** so the new supervisor exists.

Known and not yet done: the import still writes its own renamed archive copy, so
each photo is on the archive drive twice and imports still compete with a running
dump for that drive. Planned: have the import rename the card copy within the
archive instead of writing again, and have generation yield while a dump runs.

## macOS privacy

macOS gates removable volumes per *responsible app*. Herd's PHP and workers
started from the app header count as **Herd**; a Terminal session counts as the
terminal (which is why a shell can read a card the app cannot). A background
worker without permission gets a silent "Operation not permitted", not a prompt.
USB/Thunderbolt readers are gated; the built-in SD slot may not be.

**Grant Herd Full Disk Access once** (System Settings → Privacy & Security → Full
Disk Access), then restart the workers from the page header. The window shows
this instruction when a card is mounted but unreadable. A Herd update can
invalidate the grant. This is from research, not yet confirmed on the install.

## Testing without a camera card

```sh
hdiutil create -size 200m -fs "MS-DOS FAT32" -volname EOS_DIGITAL -layout MBRSPUD /tmp/card.dmg
hdiutil attach /tmp/card.dmg && mkdir -p /Volumes/EOS_DIGITAL/DCIM/100EOSR5   # copy JPEGs in
```

and set `CARD_ALLOW_DISK_IMAGES=TRUE` in `.env`. Automated: `tests/Feature/CardReaderTest.php`.
