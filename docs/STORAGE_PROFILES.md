# Storage Profile Architecture

> **Audience.** Future proofgen/ferraraphoto contributors and the Codex session that builds the
> ferraraphoto API. Read alongside `FERRARAPHOTO_INTEGRATION.md` (overview),
> `FERRARAPHOTO_API_SPEC.md` (the wire contract that uses these profiles), and
> `PROOF_MIGRATION_PLAN.md` (how legacy data gets pinned to a profile).

## Why this exists

Today, proofgen has one set of `remote_*` SFTP disks — there's no way to keep older shows
accessible after retargeting. If you change `B2_KEY` in `.env` to point at a new account/bucket,
every photo previously written to the old account becomes unreachable through `Storage::disk()`.

For Phase 2 we want to support:

1. **Many simultaneous storage backends.** A few B2 accounts/buckets, a personal MinIO, the
   legacy local filesystem on the production server. All readable concurrently.
2. **Per-show pinning.** A show written to `b2-2024-shows` keeps reading from there forever,
   even after the operator switches the *active* profile to `b2-2025-shows`.
3. **Auto-detection of new profiles.** When the operator adds new credentials to `.env`, proofgen
   fingerprints them and either re-uses an existing profile record or creates a new one.
4. **Cross-app identity.** ferraraphoto needs to know the same profile identifier proofgen used
   so the IPN/deliverables page reads from the right disk.

## Schema

Both apps get a `storage_profiles` table. The schema is intentionally identical so the cross-app
sync is trivial.

### proofgen migration

```php
Schema::create('storage_profiles', function (Blueprint $table) {
    $table->string('id', 64)->primary();        // human-readable name, lowercase-with-dashes
    $table->string('label');                    // operator-friendly label
    $table->string('driver', 16);               // 's3' | 'local'
    $table->string('bucket')->nullable();       // s3 only
    $table->string('region')->nullable();       // s3 only
    $table->string('endpoint')->nullable();     // s3 only — null for AWS, set for B2/MinIO/Spaces
    $table->boolean('use_path_style')->default(false); // s3 only
    $table->string('root')->nullable();         // local only — absolute path
    $table->string('fingerprint', 64);          // sha256 of normalized {driver,bucket,region,endpoint,root}
    $table->boolean('is_active')->default(false);   // exactly one row may be true
    $table->boolean('is_writable')->default(true);  // false = legacy / read-only
    $table->timestamps();

    $table->unique('fingerprint');
});
```

### ferraraphoto migration

Identical structure, written for Laravel 4.2's `Schema::create` syntax. The Codex session that
builds the API will produce the L4.2 migration alongside.

### `shows` table additions

```php
// proofgen
Schema::table('shows', function (Blueprint $table) {
    $table->string('storage_profile_id', 64)->nullable()->after('ferraraphoto_show_slug');
    $table->foreign('storage_profile_id')->references('id')->on('storage_profiles')->nullOnDelete();
});

// ferraraphoto (L4.2 syntax)
Schema::table('shows', function ($table) {
    $table->string('storage_profile_id', 64)->nullable();
});
```

Show-level pinning is sufficient — a single show always lives on a single profile. We
deliberately do **not** add `storage_profile_id` to `photos` / `show_classes` / `image_deliveries`
unless we hit a real use case for splitting a show across profiles. YAGNI.

## Profile identity: name + fingerprint

A profile's primary key is its **name** (e.g., `b2-2024-shows`, `minio-personal`,
`legacy-local`). Names are stable, lowercase-with-dashes, and double as the `.env` prefix:

```
PROFILE_B2_2024_SHOWS_KEY=...
PROFILE_B2_2024_SHOWS_SECRET=...
PROFILE_B2_2024_SHOWS_BUCKET=ferraraphoto-2024
PROFILE_B2_2024_SHOWS_REGION=us-west-002
PROFILE_B2_2024_SHOWS_ENDPOINT=https://s3.us-west-002.backblazeb2.com
PROFILE_B2_2024_SHOWS_USE_PATH_STYLE=true
```

(The `.env` key transformation: profile id → uppercase, dashes → underscores, prefixed `PROFILE_`,
suffixed `_KEY` / `_SECRET` / etc.)

The **fingerprint** is sha256 of canonical JSON of `{driver, bucket, region, endpoint, root}`
(secrets excluded). It exists for two reasons:
- **Drift detection.** If someone edits `.env` and points an existing profile name at a different
  bucket, the fingerprint mismatch surfaces a clear error rather than silently corrupting data.
- **Auto-creation.** When proofgen sees a new profile name in `.env`, it computes the fingerprint
  and either matches an existing row (no-op) or creates a new row.

## Resolving a profile to a runtime disk

```php
// app/Services/Storage/StorageProfileResolver.php
class StorageProfileResolver
{
    /**
     * Returns the disk name (config key) for a profile, registering the disk
     * config at runtime if it hasn't been registered yet.
     */
    public function diskFor(StorageProfile $profile): string
    {
        $diskName = "profile_{$profile->id}";

        if (! config()->has("filesystems.disks.{$diskName}")) {
            config(["filesystems.disks.{$diskName}" => $this->buildDiskConfig($profile)]);
        }

        return $diskName;
    }

    private function buildDiskConfig(StorageProfile $profile): array
    {
        if ($profile->driver === 'local') {
            return ['driver' => 'local', 'root' => $profile->root, 'throw' => false];
        }

        $envPrefix = 'PROFILE_'.strtoupper(str_replace('-', '_', $profile->id));

        return [
            'driver' => 's3',
            'key' => env("{$envPrefix}_KEY"),
            'secret' => env("{$envPrefix}_SECRET"),
            'region' => $profile->region,
            'bucket' => $profile->bucket,
            'endpoint' => $profile->endpoint,
            'use_path_style_endpoint' => $profile->use_path_style,
            'throw' => false,
        ];
    }
}
```

Call sites do `Storage::disk($resolver->diskFor($profile))` and get a normal Laravel disk.
The disk config is registered lazily at first use per request, so we don't pollute the boot
config tree with disks for profiles that aren't touched in this request.

If the required `.env` keys are missing, `Storage::disk(...)->get(...)` will surface the AWS
SDK's auth error. We can pre-flight that with an explicit health check (see "Profile health"
below) — but we don't try to hide the error, since the operator is the only audience.

### Auto-detection on boot / settings save

```php
// app/Services/Storage/ProfileAutoDetector.php
class ProfileAutoDetector
{
    public function detectFromEnv(): array
    {
        $detected = [];

        foreach ($_ENV as $key => $value) {
            if (! preg_match('/^PROFILE_(.+)_KEY$/', $key, $m)) continue;

            $profileId = strtolower(str_replace('_', '-', $m[1]));
            $envPrefix = "PROFILE_{$m[1]}";

            $config = [
                'driver' => env("{$envPrefix}_DRIVER", 's3'),
                'bucket' => env("{$envPrefix}_BUCKET"),
                'region' => env("{$envPrefix}_REGION"),
                'endpoint' => env("{$envPrefix}_ENDPOINT") ?: null,
                'use_path_style' => env("{$envPrefix}_USE_PATH_STYLE", false),
                'root' => env("{$envPrefix}_ROOT") ?: null,
            ];

            $fingerprint = $this->fingerprint($config);

            $existing = StorageProfile::where('fingerprint', $fingerprint)->first()
                ?? StorageProfile::find($profileId);

            if ($existing) {
                if ($existing->fingerprint !== $fingerprint) {
                    Log::error('Storage profile fingerprint drift', [
                        'profile' => $profileId,
                        'old' => $existing->fingerprint,
                        'new' => $fingerprint,
                    ]);
                }
                $detected[] = $existing;
                continue;
            }

            $profile = StorageProfile::create([
                'id' => $profileId,
                'label' => env("{$envPrefix}_LABEL", $profileId),
                'driver' => $config['driver'],
                'bucket' => $config['bucket'],
                'region' => $config['region'],
                'endpoint' => $config['endpoint'],
                'use_path_style' => (bool) $config['use_path_style'],
                'root' => $config['root'],
                'fingerprint' => $fingerprint,
                'is_active' => false,
                'is_writable' => true,
            ]);

            $detected[] = $profile;
        }

        return $detected;
    }

    private function fingerprint(array $config): string
    {
        ksort($config);
        return hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES));
    }
}
```

Run `ProfileAutoDetector::detectFromEnv()`:
- On a Settings page action ("Refresh storage profiles from .env")
- After `php artisan migrate` to seed the legacy profile (see migration plan)

We deliberately don't run this on every boot — `.env` reads from disk and the detector touches
the DB; once-per-config-change is plenty.

### Active profile

The active profile is "the one new shows go to." Tracked as:

```php
// proofgen
StorageProfile::where('is_active', true)->first()  // exactly one row
```

Setting active is a Settings-page action (radio list of `is_writable=true` profiles). The
mutation is wrapped in a transaction:

```php
DB::transaction(function () use ($profileId) {
    StorageProfile::where('is_active', true)->update(['is_active' => false]);
    StorageProfile::where('id', $profileId)->update(['is_active' => true]);
});
```

### Pinning a show to a profile

When proofgen first writes derived files for a show:

```php
// app/Services/Storage/ShowProfileBinder.php
class ShowProfileBinder
{
    public function pin(Show $show): StorageProfile
    {
        if ($show->storage_profile_id) {
            return $show->storageProfile;  // already pinned
        }

        $active = StorageProfile::where('is_active', true)->where('is_writable', true)->firstOrFail();
        $show->storage_profile_id = $active->id;
        $show->save();

        return $active;
    }
}
```

Called from `UploadProofs::handle()` (or earlier, from the first derivative-write job — see
PROOFGEN_API_CLIENT.md for placement). After this, all reads/writes for the show go through
`Storage::disk($resolver->diskFor($show->storageProfile))`.

## Cross-app identity sync

The two apps independently store the same profile rows. Sync happens via the
**proofgen → ferraraphoto API push**: when proofgen POSTs photo metadata for a show, the payload
includes the show's profile reference:

```json
{
  "show": { "slug": "2026R12", ... },
  "storage_profile": {
    "id": "b2-2024-shows",
    "label": "Backblaze B2 — 2024 shows",
    "driver": "s3",
    "bucket": "ferraraphoto-2024",
    "region": "us-west-002",
    "endpoint": "https://s3.us-west-002.backblazeb2.com",
    "use_path_style": true,
    "fingerprint": "9f8c..."
  },
  ...
}
```

ferraraphoto upserts the `storage_profiles` row keyed on `id`:

- New row → insert.
- Existing row, fingerprint matches → no-op.
- Existing row, fingerprint differs → log error, surface in admin, **reject the push**. Operator
  must reconcile (drop one app's row, fix `.env`, re-trigger sync).

ferraraphoto **never** receives credentials from proofgen. The operator must independently
populate the matching `PROFILE_<NAME>_*` keys in ferraraphoto's `.env`. A Settings page on
ferraraphoto admin shows profile health: green if .env keys resolve, yellow if missing, red if
fingerprint drift.

## Profile health check

```php
class StorageProfileHealthCheck
{
    /**
     * @return array{status: 'ok'|'missing_credentials'|'unreachable'|'drift', message: ?string}
     */
    public function check(StorageProfile $profile): array
    {
        // 1. .env keys present?
        $envPrefix = 'PROFILE_'.strtoupper(str_replace('-', '_', $profile->id));
        if ($profile->driver === 's3' && (! env("{$envPrefix}_KEY") || ! env("{$envPrefix}_SECRET"))) {
            return ['status' => 'missing_credentials', 'message' => "Missing {$envPrefix}_KEY / _SECRET in .env"];
        }

        // 2. Cheap reachability — list the bucket root with a 1-key cap.
        try {
            $disk = Storage::disk(app(StorageProfileResolver::class)->diskFor($profile));
            $disk->files('/', false);
            return ['status' => 'ok', 'message' => null];
        } catch (Throwable $e) {
            return ['status' => 'unreachable', 'message' => $e->getMessage()];
        }
    }
}
```

Surfaced on the proofgen Settings → Storage Profiles page (one row per profile, status badge,
"Re-check" button) and on ferraraphoto's `/admin/storage-profiles`.

## Read-only / legacy profiles

`is_writable = false` profiles are the explicit way to say "this storage holds data but no new
writes go here." After migration, the legacy local profile becomes read-only. The active-profile
selector hides non-writable rows.

There's no enforcement at the disk level — `Storage::disk(...)->put(...)` still works on a
read-only profile. We rely on the active-profile pinning logic to never select a read-only
profile for new writes. If someone bypasses that and writes anyway, the data is recoverable; it
just isn't tracked in the right place.

## Path conventions on cloud storage

Object keys mirror today's directory structure but flattened to a single bucket prefix:

```
{bucket}/proofs/{show_slug}/{class_number}/{proof_number}{thm|std}.jpg
{bucket}/web_images/{show_slug}/{class_number}/{proof_number}_web.jpg
{bucket}/high_res_images/{show_slug}/{class_number}/{proof_number}_highres.jpg
```

Same path shape on local profile (just rooted at the disk's `root`). `PathResolver` already
returns these paths — they're independent of disk type.

## What this replaces

After Phase 2, the three legacy `remote_*` disks (`remote_proofs`, `remote_web_images`,
`remote_highres_images`) and the rsync-driven upload jobs become a special case:
- Old shows pinned to `legacy-local` profile use the SFTP-driver disks via the resolver
  (resolver returns `remote_proofs` etc. for that one profile).
- New shows pinned to a cloud profile use the dynamic per-profile disk built by the resolver.

Once all shows are migrated, the SFTP path can be retired.
