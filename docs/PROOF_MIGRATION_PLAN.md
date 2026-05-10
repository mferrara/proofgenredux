# Bulk Migration of Legacy Proofs to Cloud Storage

> **Audience.** Whoever picks up the migration work after the storage-profile and
> ferraraphoto-API plumbing lands. Read alongside `STORAGE_PROFILES.md`,
> `FERRARAPHOTO_API_SPEC.md`, and `PROOFGEN_API_CLIENT.md`.

## Goals

1. Move every existing proof / web / high-res file from the production ferraraphoto server's
   attached volume to the active cloud storage profile.
2. **Never delete source files** during migration. Removal is a manual step the operator does
   after confirming cloud-served deliveries work.
3. Take an inventory of what's actually present (so we know what we have and what we're
   missing) before migrating.
4. Coexist with non-migrated shows: the IPN processor and the deliverables page key off each
   show's `storage_profile_id`, so a half-migrated install just works — migrated shows serve
   from cloud, non-migrated shows serve from the legacy SFTP path.

## Non-goals

- Migrating originals or archive copies. Those live on Mike's Mac (`fullsize` disk) + the
  archive drive (`archive` disk). They never leave proofgen-side machines.
- Migrating files for shows that aren't already in proofgen's database. If the production
  server has orphan directories with no matching `Show` row, they stay where they are.
- Live cutover. Each show's cutover is explicit ("migrate this show now") with a confirmation
  in the UI. There's no "migrate everything overnight" button.

## Inventory phase

A new `migration_inventory` table tracks the work:

```php
Schema::create('migration_inventory', function (Blueprint $table) {
    $table->id();
    $table->string('show_slug', 64);
    $table->string('class_number', 32);
    $table->string('proof_number', 64);
    $table->string('content_type', 16);  // 'proof_thm' | 'proof_std' | 'web_image' | 'high_res_image'
    $table->string('source_disk', 32);    // 'remote_proofs' | 'remote_web_images' | 'remote_highres_images'
    $table->string('source_path');        // path within the source disk
    $table->bigInteger('source_size_bytes')->nullable();
    $table->timestamp('source_mtime')->nullable();
    $table->string('target_disk', 64)->nullable();    // disk name from StorageProfileResolver
    $table->string('target_object_key')->nullable();
    $table->string('source_sha1', 40)->nullable();    // populated lazily during verify
    $table->string('target_sha1', 40)->nullable();
    $table->string('status', 16)->default('discovered');
    // discovered → planned → copied → verified → failed
    $table->text('error_message')->nullable();
    $table->timestamp('last_attempt_at')->nullable();
    $table->timestamps();

    $table->unique(['show_slug', 'class_number', 'proof_number', 'content_type']);
    $table->index('status');
});
```

### `php artisan proofgen:migration-inventory [--show=...]`

Walks the three legacy SFTP disks and inserts a row per file found.

```php
class MigrationInventoryService
{
    public function inventory(?string $showSlug = null): array
    {
        $disks = [
            'proof_thm'      => ['disk' => 'remote_proofs',         'pattern' => '_thm.jpg'],
            'proof_std'      => ['disk' => 'remote_proofs',         'pattern' => '_std.jpg'],
            'web_image'      => ['disk' => 'remote_web_images',     'pattern' => '_web.jpg'],
            'high_res_image' => ['disk' => 'remote_highres_images', 'pattern' => '_highres.jpg'],
        ];

        $stats = ['discovered' => 0, 'unchanged' => 0, 'errors' => 0];

        foreach ($disks as $contentType => $cfg) {
            $disk = Storage::disk($cfg['disk']);
            $shows = $showSlug ? [$showSlug] : $disk->directories('/');

            foreach ($shows as $show) {
                foreach ($disk->directories($show) as $classDir) {
                    foreach ($disk->files($classDir) as $file) {
                        if (! str_ends_with($file, $cfg['pattern'])) continue;

                        $proofNumber = $this->extractProofNumber(basename($file), $cfg['pattern']);
                        $upserted = MigrationInventory::updateOrCreate(
                            [
                                'show_slug' => basename($show),
                                'class_number' => basename($classDir),
                                'proof_number' => $proofNumber,
                                'content_type' => $contentType,
                            ],
                            [
                                'source_disk' => $cfg['disk'],
                                'source_path' => $file,
                                'source_size_bytes' => $disk->size($file),
                                'source_mtime' => Carbon::createFromTimestamp($disk->lastModified($file)),
                                'status' => 'discovered',
                            ]
                        );

                        $upserted->wasRecentlyCreated ? $stats['discovered']++ : $stats['unchanged']++;
                    }
                }
            }
        }

        return $stats;
    }
}
```

After this runs, the operator can query the inventory to answer:
- "How many web images do we actually have for show 2026R12?"
- "Which photos in our DB don't have a corresponding file on the production server?"
- "Which content types are we missing?"

A Livewire panel on the show view surfaces the inventory counts with a "Re-inventory this show"
button (which clears + re-runs for that one slug).

### Detect orphans (DB rows with no source file)

The inventory is "what's on disk." A second pass joins `photos` against the inventory:

```sql
SELECT p.id, p.proof_number, p.show_class_id
FROM photos p
LEFT JOIN migration_inventory mi
    ON mi.proof_number = p.proof_number
    AND mi.content_type = 'proof_std'
WHERE mi.id IS NULL
    AND p.proofs_uploaded_at IS NOT NULL;
```

Surfaced as a `photo_issues` row of type `migration_source_missing` (new type — extends the
existing audit pipeline rather than starting a new system) so the operator can investigate
photo-by-photo.

### Detect strays (source files with no DB row)

```sql
SELECT mi.show_slug, mi.class_number, mi.proof_number, mi.content_type
FROM migration_inventory mi
LEFT JOIN photos p ON p.proof_number = mi.proof_number
WHERE p.id IS NULL;
```

Surfaced via the same audit panel; usually means a manual upload or a deleted show.

## Copy phase

Two implementations supported. Pick per-show based on what's available.

### Option 1 (recommended): rclone on the production server

Install rclone on the production ferraraphoto server. Configure two remotes:

```ini
# ~/.config/rclone/rclone.conf
[localvol]
type = local
nounc = true

[b2-2024]
type = s3
provider = Other
endpoint = https://s3.us-west-002.backblazeb2.com
access_key_id = ...
secret_access_key = ...
region = us-west-002
force_path_style = true
```

Per-show migration:

```bash
rclone copy --immutable --no-update-modtime --checksum \
    /home/forge/ferraraphoto.com/public/proofs/2026R12 \
    b2-2024:ferraraphoto-2024/proofs/2026R12 \
    --transfers 8 --checkers 16 --log-level INFO --stats 30s
```

`--immutable --no-update-modtime` ensures we never overwrite cloud-side data after the first
successful copy. Re-running is idempotent.

Repeat for `web_images` and `high_res_images`. The proofgen artisan command wraps this:

```bash
php artisan proofgen:migrate-show 2026R12
    --rclone-remote=b2-2024
    --bucket=ferraraphoto-2024
    --ssh-host=ferraraphoto.com
    --ssh-user=forge
    [--dry-run]
```

The command:
1. SSH-execs `rclone copy ...` on the remote host (one process per content type, sequential to
   avoid clobbering bandwidth).
2. Parses rclone's stats output to update `migration_inventory.status` rows in batches.
3. After all three content types copy successfully, marks the show "copied" and moves to
   verification.

### Option 2 (fallback): Laravel Storage walk from proofgen

When rclone-on-prod isn't available (e.g., the user can't install on the host), proofgen does
the move itself by reading from the legacy `remote_*` disks (SFTP) and writing to the active
profile's disk:

```php
class CopyShowToCloud
{
    public function copy(Show $show, StorageProfile $target): void
    {
        $resolver = app(PathResolver::class);
        $targetDisk = Storage::disk($resolver->diskFor($target));

        foreach (MigrationInventory::where('show_slug', $show->ferraraphoto_slug)
            ->where('status', 'discovered')
            ->cursor() as $row) {

            $sourceDisk = Storage::disk($row->source_disk);
            $targetKey = $this->buildTargetKey($row);

            if ($targetDisk->exists($targetKey) && $targetDisk->size($targetKey) === $row->source_size_bytes) {
                $row->update(['status' => 'copied', 'target_object_key' => $targetKey, 'target_disk' => $targetDisk]);
                continue;
            }

            $stream = $sourceDisk->readStream($row->source_path);
            $targetDisk->writeStream($targetKey, $stream);

            $row->update([
                'status' => 'copied',
                'target_disk' => $resolver->diskFor($target),
                'target_object_key' => $targetKey,
                'last_attempt_at' => now(),
            ]);
        }
    }
}
```

Slower than rclone (everything routes through Mike's Mac) but no remote-host setup. Works for
small migrations or the long tail.

Either path produces the same end state: `migration_inventory` rows in `copied` status with
`target_disk` + `target_object_key` populated.

## Verify phase

After copy, run a verification pass:

```php
class VerifyMigrationCopies
{
    public function verify(Show $show): array
    {
        foreach (MigrationInventory::where('show_slug', $show->ferraraphoto_slug)
            ->where('status', 'copied')
            ->cursor() as $row) {

            $sourceDisk = Storage::disk($row->source_disk);
            $targetDisk = Storage::disk($row->target_disk);

            // Cheap check first: size matches.
            $sourceSize = $sourceDisk->size($row->source_path);
            $targetSize = $targetDisk->size($row->target_object_key);

            if ($sourceSize !== $targetSize) {
                $this->markFailed($row, 'size_mismatch', compact('sourceSize', 'targetSize'));
                continue;
            }

            // Optional expensive check: sha1 match. Pulls bytes through proofgen — skip for
            // bulk runs; enable via --thorough flag for confidence sampling.
            if ($this->thorough) {
                $sourceSha = sha1_file($sourceDisk->path($row->source_path));
                $targetSha = $this->sha1FromStream($targetDisk->readStream($row->target_object_key));
                if ($sourceSha !== $targetSha) {
                    $this->markFailed($row, 'sha1_mismatch', compact('sourceSha', 'targetSha'));
                    continue;
                }
                $row->source_sha1 = $sourceSha;
                $row->target_sha1 = $targetSha;
            }

            $row->update(['status' => 'verified']);
        }
    }
}
```

For S3-compatible providers, `Storage::disk()->size()` translates to a HEAD request — cheap.
sha1 verification only runs when `--thorough` is passed because it pulls the full file through
proofgen (slow for high-res).

A "spot-check 5% of files thoroughly" mode is good middle ground for confidence without
re-downloading everything.

## Cutover phase (per show)

Triggered by the operator clicking "Migrate this show to active profile" on the show view:

1. **Pre-flight checks**:
   - All inventory rows for this show must be in `verified` status. If any are `failed` or
     `discovered`, the button is disabled with a tooltip explaining why.
   - Active profile health check passes (`Storage::disk()->files('/')` succeeds).
   - ferraraphoto API reachable (`GET /api/v1/storage-profiles/{active}/health` returns ok).

2. **Atomic update** in a DB transaction:
   ```php
   DB::transaction(function () use ($show, $activeProfile) {
       $show->storage_profile_id = $activeProfile->id;
       $show->save();

       foreach ($show->photos as $photo) {
           $photo->update([
               'proof_thm_key' => $this->keyFor($photo, 'proof_thm'),
               'proof_std_key' => $this->keyFor($photo, 'proof_std'),
               'web_image_key' => $this->keyFor($photo, 'web_image'),
               'high_res_image_key' => $this->keyFor($photo, 'high_res_image'),
           ]);
       }
   });
   ```

3. **Push to ferraraphoto**:
   ```php
   Bus::chain([
       new EnsureFerraraphotoShow($show->id),  // upserts profile + show + classes
       ...$show->classes->map(fn ($c) => new PushPhotoMetadata($c->id))->all(),
   ])->dispatch();
   ```

4. **Verification step**: After the chain completes, proofgen polls
   `GET /api/v1/shows/{slug}` and confirms photo_count matches local. UI shows "Migration
   complete — N photos served from cloud."

5. **Notify operator**: Top status bar surfaces a one-time "Show 2026R12 migrated; please
   test customer flow" toast.

## Source file retention

Sources are **not deleted** during any of the above. The legacy filesystem stays exactly as it
is.

After the operator manually verifies cloud delivery is working (paid order → email → click
deliverables-page link → file downloads), they can run:

```bash
php artisan proofgen:migration-cleanup 2026R12 [--really]
```

Without `--really`, the command lists what it *would* delete (file count, total bytes). With
`--really`, it `mv`'s the files into a per-show graveyard on the production server (e.g.,
`/home/forge/ferraraphoto.com/_migration_graveyard/2026R12/`) — still not deleted, just out of
the way. Final deletion is whenever the operator runs `rm -rf` themselves.

## Mixed-state behavior during the rollout

While migration is in flight, the install will have shows pinned to multiple profiles
simultaneously:

- New shows go to active cloud profile (whatever is selected in Settings).
- Some old shows pinned to `legacy-local` (rsync path).
- Some old shows already migrated and pinned to a cloud profile.

Both apps key off `Show.storage_profile_id` everywhere — there's no "which mode are we in"
global state. Specifically:

- **proofgen `UploadDerivedFiles`** dispatches the right path based on the show's pinned
  profile (rsync delegate for `legacy-local`, cloud-disk write otherwise).
- **ferraraphoto IPN processor** loads the photo, looks up its show's pinned profile, resolves
  the disk via `StorageProfileResolver`, reads the file. Equally happy with legacy-local
  (which still resolves to the local filesystem) and cloud profiles.
- **ferraraphoto deliverables page** routes downloads through `Storage::disk(...)->readStream()`
  — same behavior either way.

This is the whole point of the per-show pinning model: there's no global flag, no big-bang
cutover. Each show migrates independently, and during the migration window we just have a
heterogeneous fleet.

## Operator UX summary

**On the show view** (proofgen):
- Storage profile badge near the title ("Stored on: Legacy (SFTP)" or "B2 — 2024 shows")
- Migration panel (only visible when pinned profile != active profile):
  - Inventory counts: discovered / planned / copied / verified / failed per content type
  - Buttons: "Inventory now", "Copy", "Verify", "Migrate" (last one disabled until verify is
    100%)
  - Live progress while a phase is running

**On the home view** (proofgen):
- Migration progress bar across all shows ("47/123 shows migrated")
- Drill-in opens a list of shows grouped by their current profile

## Tests

- `tests/Feature/MigrationInventoryServiceTest.php` — discovers files via `Storage::fake()`,
  detects orphans + strays, idempotent re-runs.
- `tests/Feature/CopyShowToCloudTest.php` — covers Laravel-Storage-walk path with size match,
  size mismatch, missing source.
- `tests/Feature/VerifyMigrationCopiesTest.php` — covers cheap and thorough modes, failure
  states.
- `tests/Feature/ShowMigrationCutoverTest.php` — pre-flight failures, transactional update,
  ferraraphoto API push, post-migration verification poll.
- A Codex-side rclone test plan: dry-run on a small show, verify byte-for-byte parity, then
  cutover and re-test.

## Schedule (rough)

| Step | Estimated effort |
|------|------------------|
| migration_inventory schema + service + artisan command | 1 day |
| Verify pass + UI panel | 1 day |
| rclone wrapper command | 1 day |
| Per-show cutover flow + tests | 1 day |
| First production show migrated end-to-end (high-touch, with operator) | half-day |
| Bulk migration of remaining shows (mostly automated) | depends on volume |

The sequencing assumes ferraraphoto's API ships first (Codex delivery — see
`FERRARAPHOTO_API_SPEC.md`).
