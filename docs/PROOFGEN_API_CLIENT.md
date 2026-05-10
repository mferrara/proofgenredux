# Proofgen-side API Client + Storage Refactor

> **Audience.** Whoever picks up the proofgen-side Phase 2 work. Read alongside
> `STORAGE_PROFILES.md` (the contract this implements) and `FERRARAPHOTO_API_SPEC.md` (what we
> call).

## Scope

This is the proofgen-side mirror of `FERRARAPHOTO_API_SPEC.md`. It covers:
- The proofgen `storage_profiles` table and resolver (mirroring ferraraphoto's)
- The new `FerraraphotoApiClient` service
- How `UploadProofs` / `UploadWebImages` / `UploadHighresImages` evolve to support both
  legacy-local (rsync) and cloud (Storage::disk()->put()) profiles
- A new `EnsureFerraraphotoShow` upstream job that creates show + classes + storage profile
  on ferraraphoto before any photo metadata is pushed
- A new `PushPhotoMetadata` job that POSTs photo metadata after files are in place
- Settings UI for managing profiles
- Show view UI for surfacing pinned profile + migration status

## Schema

### `storage_profiles` table

Identical shape to ferraraphoto's (see `STORAGE_PROFILES.md`). Migration:

```php
Schema::create('storage_profiles', function (Blueprint $table) {
    $table->string('id', 64)->primary();
    $table->string('label');
    $table->string('driver', 16);
    $table->string('bucket')->nullable();
    $table->string('region')->nullable();
    $table->string('endpoint')->nullable();
    $table->boolean('use_path_style')->default(false);
    $table->string('root')->nullable();
    $table->string('fingerprint', 64)->unique();
    $table->boolean('is_active')->default(false);
    $table->boolean('is_writable')->default(true);
    $table->timestamps();
});
```

### `shows.storage_profile_id`

```php
Schema::table('shows', function (Blueprint $table) {
    $table->string('storage_profile_id', 64)->nullable()->after('ferraraphoto_show_slug');
    $table->foreign('storage_profile_id')->references('id')->on('storage_profiles')->nullOnDelete();
});
```

### `photos.*_object_key` columns

Stored alongside `*_uploaded_at` so we know exactly where in the bucket each derived file lives.
Backfilled at upload time using `PathResolver` paths.

```php
Schema::table('photos', function (Blueprint $table) {
    $table->string('proof_thm_key')->nullable()->after('proofs_uploaded_at');
    $table->string('proof_std_key')->nullable()->after('proof_thm_key');
    $table->string('web_image_key')->nullable()->after('web_image_uploaded_at');
    $table->string('high_res_image_key')->nullable()->after('highres_image_uploaded_at');
});
```

### Legacy profile seed

A data-only migration creates the `legacy-local` profile that represents the existing rsync
target:

```php
StorageProfile::firstOrCreate(['id' => 'legacy-local'], [
    'label' => 'Legacy (production server filesystem via SFTP)',
    'driver' => 'local',           // s3 from proofgen's perspective is wrong here — see below
    'root' => null,                // resolved via the existing remote_* SFTP disks (special case)
    'fingerprint' => 'legacy-local-v1',  // synthetic, not a real hash
    'is_active' => true,           // no-behavior-change rollout: new shows still pin here
    'is_writable' => true,         // until the operator configures and activates a cloud profile
]);
```

The legacy profile is a **special case** — its disk-resolution path is "use the existing
`remote_proofs` / `remote_web_images` / `remote_highres_images` disks rather than build a new
one." `StorageProfileResolver::diskFor()` checks `$profile->id === 'legacy-local'` and returns
the appropriate `remote_*` name. All existing shows are pinned to this profile by the same
migration, so legacy uploads keep flowing through rsync until each show is individually
migrated to a cloud profile.

## Services

### `App\Services\Storage\StorageProfileResolver`

Mirror of ferraraphoto's. Returns a Laravel disk name for a profile. Special-cases
`legacy-local` to map proof / web / highres reads-and-writes to the right `remote_*` disk via
a small content-type-aware helper:

```php
public function diskFor(StorageProfile $profile, ?string $contentType = null): string
{
    if ($profile->id === 'legacy-local') {
        return match ($contentType) {
            'proofs' => 'remote_proofs',
            'web_images' => 'remote_web_images',
            'high_res_images' => 'remote_highres_images',
            default => throw new InvalidArgumentException('legacy-local requires contentType'),
        };
    }

    $diskName = "profile_{$profile->id}";
    if (! config()->has("filesystems.disks.{$diskName}")) {
        config(["filesystems.disks.{$diskName}" => $this->buildDiskConfig($profile)]);
    }
    return $diskName;
}
```

### `App\Services\Storage\ProfileAutoDetector`

Same as ferraraphoto's. Scans `$_ENV` for `PROFILE_*_KEY` keys, fingerprints each, upserts.

Triggered by:
- Settings → Storage Profiles → "Refresh from .env" button
- Post-deploy `php artisan proofgen:detect-storage-profiles` command (idempotent)

### `App\Services\Storage\ShowProfileBinder`

Pin a show to the active profile on first upload. Idempotent.

```php
public function pin(Show $show): StorageProfile
{
    if ($show->storage_profile_id) {
        return $show->storageProfile;
    }

    $active = StorageProfile::where('is_active', true)
        ->where('is_writable', true)
        ->firstOrFail();

    $show->storage_profile_id = $active->id;
    $show->save();

    return $active;
}
```

### `App\Services\Ferraraphoto\FerraraphotoApiClient`

Thin HTTP wrapper around the API spec'd in `FERRARAPHOTO_API_SPEC.md`. One method per endpoint
plus internal retry / idempotency handling.

```php
class FerraraphotoApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiToken,
        private LoggerInterface $logger,
    ) {}

    public function upsertStorageProfile(StorageProfile $profile): array { ... }
    public function upsertShow(Show $show): array { ... }
    public function upsertClass(ShowClass $class): array { ... }
    public function upsertPhotos(ShowClass $class, Collection $photos): array { ... }
    public function readShow(string $slug): ?array { ... }
    public function readDeliveries(string $slug, int $page = 1, int $perPage = 100): array { ... }
    public function profileHealth(string $profileId): array { ... }

    private function request(string $method, string $path, array $body = []): array
    {
        $response = Http::withToken($this->apiToken)
            ->timeout(30)
            ->retry(3, 250, throw: false)
            ->acceptJson()
            ->asJson()
            ->{$method}("{$this->baseUrl}/api/v1{$path}", $body);

        if (! $response->ok()) {
            $err = $response->json('error') ?? ['code' => 'unknown', 'message' => $response->body()];
            throw new FerraraphotoApiException($err['code'], $err['message'], $response->status(), $err['context'] ?? []);
        }

        return $response->json('data');
    }
}
```

Failure modes:
- Network error / timeout → throws `FerraraphotoApiException` after 3 retries; the caller job
  fails and Horizon retries with backoff.
- 4xx response → throws immediately (no retry — operator action needed).
- 5xx response → retries up to 3x, then throws.

Config (`config/proofgen.php`):

```php
'ferraraphoto' => [
    'base_url' => env('FERRARAPHOTO_API_BASE', 'https://ferraraphoto.com'),
    'api_token' => env('FERRARAPHOTO_API_TOKEN'),
],
```

`FerraraphotoApiClient` is bound in a service provider via `App::singleton()` so jobs can
resolve it via DI.

## Job pipeline changes

### Today

```
ImportPhoto → GenerateThumbnails / GenerateWebImage / GenerateHighresImage
                ↓
              UploadProofs / UploadWebImages / UploadHighresImages (rsync)
```

`UploadProofs` reads from `fullsize/proofs/...` and rsyncs to `remote_proofs`. Same for the
others.

### After Phase 2

```
ImportPhoto → GenerateThumbnails / GenerateWebImage / GenerateHighresImage
                ↓
              EnsureFerraraphotoShow (per show, idempotent — upsert profile, show, classes)
                ↓
              UploadDerivedFiles (per class, parallel — uploads via Storage::disk())
                ↓
              PushPhotoMetadata (per class — POSTs photo metadata to ferraraphoto)
```

#### `EnsureFerraraphotoShow` job

```php
class EnsureFerraraphotoShow implements ShouldQueue
{
    public function __construct(public string $showId) {}

    public function handle(FerraraphotoApiClient $api, ShowProfileBinder $binder): void
    {
        $show = Show::findOrFail($this->showId);
        $profile = $binder->pin($show);

        $api->upsertStorageProfile($profile);
        $api->upsertShow($show);
        foreach ($show->classes as $class) {
            $api->upsertClass($class);
        }
    }
}
```

Dispatched once per show before any per-class upload jobs. Idempotent — re-running is cheap
(fingerprint+slug match returns 200 with no DB churn on the ferraraphoto side).

#### `UploadDerivedFiles` job (replaces the three per-content-type jobs for cloud profiles)

Single job per class that uploads proofs + web + highres in sequence (proofs first), reading
from the local `fullsize` disk and writing to `Storage::disk($resolver->diskFor($profile))`.

For `legacy-local` profile, this job delegates to the existing rsync flow (calls into
`UploadProofs::handle()` etc. via direct method invocation, or short-circuits and dispatches
the legacy jobs).

```php
class UploadDerivedFiles implements ShouldQueue
{
    public function __construct(public string $classId) {}

    public function handle(StorageProfileResolver $resolver, FerraraphotoApiClient $api): void
    {
        $class = ShowClass::findOrFail($this->classId);
        $profile = $class->show->storageProfile ?? throw new RuntimeException('Show not pinned');

        if ($profile->id === 'legacy-local') {
            $this->legacyUpload($class);
            return;
        }

        $proofsDisk = $resolver->diskFor($profile);
        foreach ($class->photos as $photo) {
            $this->copyToCloud($photo, $proofsDisk);
        }

        // PushPhotoMetadata is dispatched by the Bus::chain that wraps this job.
    }

    private function copyToCloud(Photo $photo, string $disk): void
    {
        $resolver = app(PathResolver::class);
        $localDisk = Storage::disk('fullsize');
        $remoteDisk = Storage::disk($disk);

        $jobs = [
            'proof_thm_key' => $resolver->getProofThumbnailPath($photo->show_id, $photo->showclass->name, $photo->originals_basename, '_thm'),
            'proof_std_key' => $resolver->getProofThumbnailPath($photo->show_id, $photo->showclass->name, $photo->originals_basename, '_std'),
            'web_image_key' => $resolver->getWebImagePath($photo->show_id, $photo->showclass->name, $photo->originals_basename, '_web'),
            'high_res_image_key' => $resolver->getHighresImagePath($photo->show_id, $photo->showclass->name, $photo->originals_basename, '_highres'),
        ];

        $patch = [];
        foreach ($jobs as $col => $localPath) {
            $localPath = $resolver->normalizePath($localPath);
            if (! $localDisk->exists($localPath)) continue;

            $remoteDisk->writeStream($localPath, $localDisk->readStream($localPath));
            $patch[$col] = $localPath;
            $patch[$this->stampColumnFor($col)] = now();
        }

        if (! empty($patch)) {
            $photo->update($patch);
        }
    }

    private function legacyUpload(ShowClass $class): void
    {
        // Dispatch the existing rsync chain inline.
        UploadProofs::dispatchSync($class->id);
        UploadWebImages::dispatchSync($class->id);
        UploadHighresImages::dispatchSync($class->id);
    }
}
```

(Sketch only — the real implementation needs progress events for the UI, batched writeStream
calls with backoff for cloud throttling, and the same upload-tracking telemetry the legacy jobs
record.)

#### `PushPhotoMetadata` job

```php
class PushPhotoMetadata implements ShouldQueue
{
    public function __construct(public string $classId) {}

    public function handle(FerraraphotoApiClient $api): void
    {
        $class = ShowClass::with('photos')->findOrFail($this->classId);
        // Chunked to respect the 500/photo cap.
        foreach ($class->photos->chunk(500) as $chunk) {
            $api->upsertPhotos($class, $chunk);
        }
    }
}
```

#### Bus::chain orchestration

`ClassViewComponent::uploadPendingProofsAndWebImages()` (and the show-level equivalent) become:

```php
Bus::chain([
    new EnsureFerraraphotoShow($show->id),
    new UploadDerivedFiles($class->id),
    new PushPhotoMetadata($class->id),
])->dispatch();
```

For the legacy-local profile, this collapses to:
```
EnsureFerraraphotoShow (no-op until ferraraphoto API rolls out — see "Rollout" below)
  ↓
UploadDerivedFiles (delegates to legacy rsync chain)
  ↓
PushPhotoMetadata (no-op for legacy-local until ferraraphoto API rolls out)
```

## Settings UI changes

New page: **Settings → Storage Profiles** (`app/Livewire/StorageProfilesComponent.php`,
`resources/views/livewire/settings/storage-profiles.blade.php`).

Layout:
- Header: "Storage Profiles" + "Refresh from .env" button
- Table:
  - Label / id / driver / bucket / endpoint
  - Status badge (computed via `StorageProfileHealthCheck` — green/yellow/red)
  - "Set active" radio (only enabled for `is_writable=true` rows)
  - "Mark read-only" / "Mark writable" toggle (with confirmation modal)
- Footer: "Active profile: <label>"

Existing **Settings → Server (SFTP)** page becomes "Settings → Legacy SFTP" and only
matters while the legacy profile is in use. Disable when no shows reference `legacy-local`.

## Show view UI changes

`app/Livewire/ShowViewComponent.php`:
- Storage profile badge near the show title ("Stored on: B2 — 2024 shows" with a green dot if
  healthy)
- "Migrate this show to active profile" button (only visible when show's profile differs from
  active profile and active profile is `is_writable=true`). Clicking opens a confirmation modal
  and dispatches the migration jobs (see `PROOF_MIGRATION_PLAN.md`).

`app/Livewire/ClassViewComponent.php`:
- Inherits the show's profile badge.
- "Ferraraphoto target" panel becomes "Ferraraphoto sync" panel — shows whether the show/class
  has been pushed to the API (per-content-type counts) instead of checking remote disk
  existence. Reads from `GET /api/v1/shows/{slug}` cached for 60s.

## Rollout sequence

Phase 2 lands in pieces so each step is independently revertible:

1. **Migrations + models + ProfileAutoDetector** (no behavior change). All shows backfill to
   `legacy-local` profile.
2. **Settings → Storage Profiles UI** (still no behavior change — UI surfaces existing state).
3. **`FerraraphotoApiClient`** + `EnsureFerraraphotoShow` + `PushPhotoMetadata` jobs.
   ferraraphoto-side API must be deployed first (Codex delivery).
4. **`UploadDerivedFiles`** as the orchestrator job — initially just delegates to legacy
   rsync, but the chain pattern is in place.
5. **First cloud profile** added via .env + Settings → Storage Profiles. Active profile remains
   `legacy-local` so nothing changes.
6. **Switch active to cloud profile.** New shows now write to cloud; old shows still read/write
   to legacy via rsync.
7. **Bulk migrate legacy shows** to cloud profile per `PROOF_MIGRATION_PLAN.md`. Each migration
   updates `Show.storage_profile_id`.
8. **Mark `legacy-local` profile read-only** when no shows actively write to it.
9. **Retire rsync** (delete the three `remote_*` disks, the `Server (SFTP)` settings page, and
   the `RsyncCommandBuilder`) when no shows remain on `legacy-local`. This is a long horizon.

## Tests to add

- `tests/Unit/Services/Storage/StorageProfileResolverTest.php` — covers legacy-local mapping,
  cloud disk lazy registration, missing-credential paths.
- `tests/Unit/Services/Storage/ProfileAutoDetectorTest.php` — covers fingerprinting, drift
  detection, idempotent re-detection.
- `tests/Unit/Services/Ferraraphoto/FerraraphotoApiClientTest.php` — uses `Http::fake()` to
  cover idempotency, error envelope parsing, retry behavior.
- `tests/Feature/UploadDerivedFilesTest.php` — covers cloud-profile upload (with
  `Storage::fake()`) and legacy-profile passthrough.
- `tests/Feature/PushPhotoMetadataTest.php` — chunking, idempotent re-runs.
- `tests/Feature/StorageProfilesComponentTest.php` — UI, "Refresh from .env" action,
  set-active toggling.

## What's deliberately not in this plan

- A "rollback" path from cloud-profile back to legacy-local. Once a show is migrated, the
  `legacy-local` directory is left intact (per the bulk migration plan — nothing is deleted)
  but proofgen no longer reads from it for that show.
- Proofgen-side delivery dashboard. We can read `GET /api/v1/shows/{slug}/deliveries` and show
  counters, but a full dashboard with filtering / search lives on ferraraphoto admin. proofgen
  just shows "X paid in last 30 days" or similar.
- Per-content-type pinning (proofs on profile A, web on profile B). One profile per show.
