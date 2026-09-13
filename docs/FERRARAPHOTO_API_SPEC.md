# Ferraraphoto API Specification

> **Status — September 13, 2026:** Companion-app design contract, not deployment evidence. Confirm Ferraraphoto source and deployed endpoints before treating the examples below as available behavior; this Proofgen documentation pass did not re-audit that app. See [current integration status](FERRARAPHOTO_INTEGRATION.md).

> **Audience.** The Codex session that builds this on the ferraraphoto repo, and future
> contributors maintaining the API. Read alongside `STORAGE_PROFILES.md` (storage identity model)
> and `PROOFGEN_API_CLIENT.md` (the proofgen-side caller).

## Goals

1. Replace the rsync-+-convention ingestion with a real HTTP API.
2. Let proofgen POST show / class / photo metadata (file bytes go directly to cloud storage —
   the API only carries pointers).
3. Surface a tokenized customer-facing deliverables page that streams downloads through
   ferraraphoto's own routes, counting clicks/downloads per deliverable.
4. Keep the existing IPN delivery flow working but add the deliverables-page CTA in the email
   and the tracked-download path through ferraraphoto for both web + high-res files.
5. Stay on Laravel 4.2 / PHP 7.4. No composer churn beyond the AWS SDK (or already-vendored
   League Flysystem v1) for reading cloud-stored files.

## Base path & auth

- Base URL: `https://ferraraphoto.com/api/v1` (production), `https://staging.ferraraphoto.com/api/v1` (staging).
- All `/api/v1` endpoints require `Authorization: Bearer <PROOFGEN_API_TOKEN>` header.
- The token is a 64+ character random string stored as `PROOFGEN_API_TOKEN` in ferraraphoto's
  `.env` and `FERRARAPHOTO_API_TOKEN` in proofgen's `.env` (kept under separate names so you
  always know which side you're editing — but the values are equal). Compare with
  `hash_equals()` against `Config::get('proofgen.api_token')` (or `getenv('PROOFGEN_API_TOKEN')`).
- Customer-facing endpoints (`/deliveries/{token}*`) are unauthenticated; the URL token is the
  capability. Tokens are 32-byte URL-safe random strings (`bin2hex(random_bytes(32))`).

L4.2 doesn't have route middleware groups the modern way — implement auth as a route filter:

```php
// app/filters.php
Route::filter('proofgen-api', function() {
    $auth = Request::header('Authorization', '');
    if (! preg_match('/^Bearer (.+)$/', $auth, $m)) {
        return Response::json(['error' => ['code' => 'unauthorized', 'message' => 'Missing or malformed Authorization header']], 401);
    }
    if (! hash_equals(Config::get('proofgen.api_token'), $m[1])) {
        return Response::json(['error' => ['code' => 'unauthorized', 'message' => 'Invalid token']], 401);
    }
});

// app/routes-api.php
Route::group(['prefix' => 'api/v1', 'before' => 'proofgen-api'], function() {
    // ... endpoints below
});
```

## Error envelope

Every non-2xx response uses:

```json
{
  "error": {
    "code": "fingerprint_mismatch",
    "message": "Storage profile b2-2024-shows already exists with a different fingerprint",
    "context": {
      "stored_fingerprint": "9f8c...",
      "submitted_fingerprint": "1234..."
    }
  }
}
```

Defined codes:

| Code                     | HTTP | Meaning                                                         |
|--------------------------|------|-----------------------------------------------------------------|
| `unauthorized`           | 401  | Missing/invalid bearer token                                    |
| `not_found`              | 404  | Resource (show/class/photo/order) doesn't exist                 |
| `validation_error`       | 422  | Payload schema invalid                                          |
| `fingerprint_mismatch`   | 409  | Storage profile fingerprint doesn't match stored row            |
| `slug_conflict`          | 409  | Show or class identifier collides with existing differing data  |
| `internal_error`         | 500  | Anything else                                                   |

## Endpoints

### 1. `POST /api/v1/storage-profiles` — Upsert storage profile

Idempotent on `id`. On `id` conflict with matching fingerprint, returns 200 with the existing
row. On `id` conflict with mismatched fingerprint, returns 409.

**Request:**

```json
{
  "id": "b2-2024-shows",
  "label": "Backblaze B2 — 2024 shows",
  "driver": "s3",
  "bucket": "ferraraphoto-2024",
  "region": "us-west-002",
  "endpoint": "https://s3.us-west-002.backblazeb2.com",
  "use_path_style": true,
  "root": null,
  "fingerprint": "9f8c..."
}
```

**Validation:**
- `id`: required, string, `^[a-z0-9-]{1,64}$`
- `driver`: required, in `['s3', 'local']`
- `bucket`, `region`, `endpoint`: required when driver=s3
- `root`: required when driver=local
- `fingerprint`: required, sha256 hex (64 chars)

**Response 200:**

```json
{
  "data": {
    "id": "b2-2024-shows",
    "label": "Backblaze B2 — 2024 shows",
    "driver": "s3",
    "bucket": "ferraraphoto-2024",
    "region": "us-west-002",
    "endpoint": "https://s3.us-west-002.backblazeb2.com",
    "use_path_style": true,
    "root": null,
    "fingerprint": "9f8c...",
    "credentials_present": true,
    "created_at": "2026-05-09T14:30:00-04:00"
  }
}
```

`credentials_present` is computed live by checking whether the matching `PROFILE_<NAME>_KEY` /
`_SECRET` are set in ferraraphoto's `.env`. If false, deliveries from this profile won't work
until the operator populates them — but the upsert still succeeds (this lets proofgen sync
identity ahead of operator credential setup).

### 2. `POST /api/v1/shows` — Upsert show

Idempotent on `slug`. Auto-creates the matching ferraraphoto Show row if it doesn't exist; if
it does exist, updates name/start_date/end_date/storage_profile_id from the payload.

**Request:**

```json
{
  "slug": "2026R12",
  "name": "Redux 2026 Show 12",
  "start_date": "2026-04-15",
  "end_date": "2026-04-18",
  "storage_profile_id": "b2-2024-shows",
  "auto_import": false
}
```

**Validation:**
- `slug`: required, `^[A-Za-z0-9_-]{1,64}$`
- `name`: required string
- `storage_profile_id`: required, must reference an existing profile row (or be sent first via
  the upsert above)
- `start_date`/`end_date`: optional ISO 8601 dates
- `auto_import`: optional bool, defaults to false

**Response 200:**

```json
{
  "data": {
    "slug": "2026R12",
    "name": "Redux 2026 Show 12",
    "start_date": "2026-04-15",
    "end_date": "2026-04-18",
    "storage_profile_id": "b2-2024-shows",
    "auto_import": false,
    "instant_web_images": false,
    "instant_high_res_images": false,
    "class_count": 0,
    "photo_count": 0,
    "created_at": "2026-05-09T14:30:00-04:00",
    "updated_at": "2026-05-09T14:30:00-04:00"
  }
}
```

**Behavior notes:**
- Setting `storage_profile_id` to a different value than the existing show's profile is
  **rejected** with 409 `slug_conflict` — a show's profile is immutable post-creation. To
  change profile, run the migration flow (see `PROOF_MIGRATION_PLAN.md`).
- `instant_web_images` / `instant_high_res_images` are computed by ferraraphoto from the
  registered photo set (whether any photo on this show has has_web_image=true / has_high_res=true)
  rather than checking filesystem paths. The `Show::syncInstantDeliveryFlag()` helper retires.

### 3. `POST /api/v1/shows/{slug}/classes` — Upsert class

Idempotent on `(show.slug, class_number)`.

**Request:**

```json
{
  "class_number": "121",
  "name": "Class # 121 — Senior Eq",
  "session_name": "Imported Classes"
}
```

**Validation:**
- `class_number`: required, `^[A-Za-z0-9_-]{1,32}$`
- `name`: required string
- `session_name`: optional, defaults to "Imported Classes" (matches existing
  `Show::getImportSession()` behavior)

**Response 200:**

```json
{
  "data": {
    "show_slug": "2026R12",
    "class_number": "121",
    "name": "Class # 121 — Senior Eq",
    "session_name": "Imported Classes",
    "photo_count": 0
  }
}
```

### 4. `POST /api/v1/shows/{slug}/classes/{class_number}/photos` — Bulk upsert photos

Idempotent on `(show.slug, class.class_number, proof_number)`. Accepts up to **500 photos per
request**.

**Request:**

```json
{
  "photos": [
    {
      "proof_number": "REDUX26R12_00001",
      "object_keys": {
        "proof_thm": "proofs/2026R12/121/REDUX26R12_00001_thm.jpg",
        "proof_std": "proofs/2026R12/121/REDUX26R12_00001_std.jpg",
        "web_image": "web_images/2026R12/121/REDUX26R12_00001_web.jpg",
        "high_res_image": "high_res_images/2026R12/121/REDUX26R12_00001_highres.jpg"
      },
      "sha1": "abc123...",
      "size_bytes": 12345678,
      "captured_at": "2026-04-15T13:42:11-04:00"
    }
  ]
}
```

`object_keys` values are paths within the show's pinned storage profile. `web_image` /
`high_res_image` are nullable — null means "this product type isn't available for this photo."

**Response 200:**

```json
{
  "data": {
    "results": [
      {
        "proof_number": "REDUX26R12_00001",
        "status": "created",
        "id": 12345
      }
    ],
    "summary": {
      "created": 1,
      "updated": 0,
      "unchanged": 0,
      "errors": 0
    }
  }
}
```

`status` is one of `created` / `updated` / `unchanged` / `error`. Per-photo errors don't fail
the whole request — the operator sees per-row status.

### 5. `GET /api/v1/shows/{slug}` — Read-back

Returns the current ferraraphoto-side state. Used by proofgen's "Pull current state" button
during reconciliation and by the legacy import sweep.

**Response 200:**

```json
{
  "data": {
    "slug": "2026R12",
    "name": "Redux 2026 Show 12",
    "storage_profile_id": "b2-2024-shows",
    "instant_web_images": true,
    "instant_high_res_images": true,
    "classes": [
      {
        "class_number": "121",
        "name": "Class # 121 — Senior Eq",
        "photo_count": 47
      }
    ]
  }
}
```

### 6. `GET /api/v1/shows/{slug}/deliveries` — Read delivery status

Read-only listing of `image_deliveries` rows for proofgen to display "X paid / Y delivered"
counters per show. Pagination via `?page=1&per_page=100` (cap 500).

**Response 200:**

```json
{
  "data": {
    "deliveries": [
      {
        "id": 999,
        "ipn_order_id": "...",
        "proof_number": "REDUX26R12_00001",
        "image_type": "web",
        "status": "sent",
        "customer_email": "...",
        "delivered_at": "2026-05-09T14:30:00-04:00",
        "click_count": 0,
        "download_count": 0,
        "last_downloaded_at": null
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 100,
      "total": 1
    }
  }
}
```

(`click_count`, `download_count`, `last_downloaded_at` are populated by the deliverables-page
serving routes — see below.)

### 7. `GET /api/v1/storage-profiles/{id}/health` — Profile health check

Used by proofgen to confirm ferraraphoto can actually read from a profile before doing the first
upload to it. Returns a per-profile health status.

**Response 200:**

```json
{
  "data": {
    "id": "b2-2024-shows",
    "status": "ok",
    "credentials_present": true,
    "last_checked_at": "2026-05-09T14:30:00-04:00",
    "message": null
  }
}
```

`status` is one of:
- `ok` — credentials present, bucket reachable.
- `missing_credentials` — `.env` keys absent.
- `unreachable` — credentials present but the cheap "list bucket" probe failed. `message`
  carries the SDK error.

The probe is a `Storage::disk(...)->files('/', false)` with a 1-key result cap (or a
`HeadBucket` call directly via the AWS SDK). Cached for 60 seconds to avoid hammering the
provider during health-check sweeps.

## Customer-facing deliverables (no auth — tokenized URLs)

### 8. `GET /deliveries/{order_token}` — Deliverables landing page

HTML page that lists all paid deliverables for an order. Token is `bin2hex(random_bytes(32))`
stored on a new `customer_orders` row at IPN-success time. Tokens never expire (low-volume,
trusted use case) but can be invalidated manually via admin.

Page contents:
- Customer email (verification — "Hi, this is the order for ___")
- For each deliverable:
  - Proof number + thumbnail (from `proof_std`)
  - Image type label (Web Image / High Resolution)
  - Download button → `/deliveries/{order_token}/download/{delivery_id}`
  - Last downloaded timestamp + download count (so the customer sees their own activity)
- "Need help? Contact us" footer

### 9. `GET /deliveries/{order_token}/download/{delivery_id}` — Tracked download

Streams the file from the photo's pinned storage profile through the ferraraphoto app process,
incrementing `image_deliveries.download_count` and setting `last_downloaded_at` on each call.

Implementation sketch:

```php
Route::get('deliveries/{token}/download/{delivery_id}', function ($token, $delivery_id) {
    $order = CustomerOrder::where('access_token', $token)->firstOrFail();
    $delivery = ImageDelivery::where('id', $delivery_id)
        ->where('customer_order_id', $order->id)
        ->where('status', 'sent')
        ->firstOrFail();

    $delivery->incrementDownload();

    $disk = StorageProfileResolver::diskFor($delivery->photo->show->storageProfile);
    $key = $delivery->image_type === 'web'
        ? $delivery->photo->web_image_object_key
        : $delivery->photo->high_res_image_object_key;

    if (! Storage::disk($disk)->exists($key)) {
        App::abort(404);
    }

    $stream = Storage::disk($disk)->readStream($key);
    return Response::stream(function() use ($stream) {
        fpassthru($stream);
    }, 200, [
        'Content-Type' => 'image/jpeg',
        'Content-Disposition' => 'attachment; filename="'.$delivery->download_filename().'"',
        'Cache-Control' => 'private, max-age=0, no-store',
    ]);
});

// no auth filter — token is the capability
```

L4.2 caveat: `Storage::disk()->readStream()` exists from Flysystem v1; verify ferraraphoto's
Flysystem version. If not present, drop to AWS SDK directly: `S3Client::getObject()` with
`SaveAs => php://output`.

The download endpoint should also `flush()` after each chunk to start the stream rather than
buffering the full file in memory. Set `output_buffering = Off` for this route (or call
`while (ob_get_level()) ob_end_clean();` before streaming).

### 10. `GET /deliveries/{order_token}/click/{delivery_id}` — Click tracking (optional)

Increments `click_count` and 302-redirects to the `/download/` URL. Used if we want a separate
"clicked the link in the email" metric vs "actually started a download." For Phase 2 v1, skip
this and rely on `download_count` only. Add later if we miss the metric.

## IPN Processor changes

`app/Acme/IpnProcessor.php` evolves to:

1. On payment success, find or create the `CustomerOrder` for `(ipn_order_id, customer_email)`.
   Generate `access_token` if newly created.
2. Build the deliverables-page URL: `URL::to('deliveries/'.$order->access_token)`.
3. For each line item:
   - Look up the photo as today.
   - Insert an `image_deliveries` row (linked to `customer_order_id`).
   - **Web image**: download from the show's pinned profile to a temp file, attach to email,
     also include the deliverables-page CTA in the email body.
   - **High-res image**: do **not** attach. Email contains only the deliverables-page CTA.
   - Set `status = sent` and `delivered_at` after the email succeeds.
4. Per-customer dedupe — re-running the same IPN doesn't create duplicate orders or
   deliveries. Idempotent on `(ipn_order_id, photo_id, image_type)`.

The existing `Photo::sendImageToClient($email, $type)` method gets a third parameter
`$attach_file = true`. High-res deliveries pass `false`. The deliverables-page link and
verification text live in the email blade templates (`emails/web_image.blade.php`,
`emails/high_res_image.blade.php`).

## Schema additions on ferraraphoto

```php
// 2026_06_01_000001_create_storage_profiles_table.php (L4.2 syntax)
Schema::create('storage_profiles', function ($table) {
    $table->string('id', 64); $table->primary('id');
    $table->string('label');
    $table->string('driver', 16);
    $table->string('bucket')->nullable();
    $table->string('region')->nullable();
    $table->string('endpoint')->nullable();
    $table->boolean('use_path_style')->default(false);
    $table->string('root')->nullable();
    $table->string('fingerprint', 64)->unique();
    $table->timestamps();
});

// 2026_06_01_000002_add_storage_profile_to_shows.php
Schema::table('shows', function ($table) {
    $table->string('storage_profile_id', 64)->nullable();
});

// 2026_06_01_000003_add_object_keys_to_photos.php
Schema::table('photos', function ($table) {
    $table->string('proof_thm_key')->nullable();
    $table->string('proof_std_key')->nullable();
    $table->string('web_image_key')->nullable();
    $table->string('high_res_image_key')->nullable();
    $table->string('sha1', 40)->nullable();
    $table->bigInteger('size_bytes')->unsigned()->nullable();
    $table->timestamp('captured_at')->nullable();
});

// 2026_06_01_000004_create_customer_orders_table.php
Schema::create('customer_orders', function ($table) {
    $table->increments('id');
    $table->string('ipn_order_id')->index();
    $table->string('customer_email')->index();
    $table->timestamp('paid_at')->nullable();
    $table->string('access_token', 64)->unique();
    $table->timestamps();
});

// 2026_06_01_000005_extend_image_deliveries.php
Schema::table('image_deliveries', function ($table) {
    $table->integer('customer_order_id')->unsigned()->nullable()->after('id');
    $table->integer('click_count')->unsigned()->default(0);
    $table->integer('download_count')->unsigned()->default(0);
    $table->timestamp('last_downloaded_at')->nullable();
});
```

The legacy `web_images/{slug}/...` filesystem storage scan in `Show::syncInstantDeliveryFlag()`
becomes a derived check on `photos.web_image_key IS NOT NULL` for any photo on that show. The
flag column itself stays for backward-compat with existing admin UI.

## Path conventions in cloud storage

Same shape as today's filesystem layout, but rooted at the bucket:

```
{bucket}/proofs/{show_slug}/{class_number}/{proof_number}_thm.jpg
{bucket}/proofs/{show_slug}/{class_number}/{proof_number}_std.jpg
{bucket}/web_images/{show_slug}/{class_number}/{proof_number}_web.jpg
{bucket}/high_res_images/{show_slug}/{class_number}/{proof_number}_highres.jpg
```

Stored as `{type}_key` columns on the photo so the deliverables-download route can look up the
exact key without re-deriving it. Keys are profile-relative — the profile's `root` (for local)
or bucket (for s3) is supplied by the disk config.

## Deliverables for Codex

The Codex session should produce:

1. The migrations above
2. `app/models/StorageProfile.php` (Eloquent)
3. `app/models/CustomerOrder.php` (Eloquent)
4. `app/services/StorageProfileResolver.php` — runtime disk registration
5. `app/services/StorageProfileHealthCheck.php`
6. `app/controllers/Api/V1/StorageProfileController.php`
7. `app/controllers/Api/V1/ShowController.php`
8. `app/controllers/Api/V1/ShowClassController.php`
9. `app/controllers/Api/V1/PhotoController.php`
10. `app/controllers/Api/V1/DeliveryController.php` (admin/proofgen-facing)
11. `app/controllers/DeliveryDownloadController.php` (customer-facing tokenized routes)
12. `app/views/deliveries/show.blade.php` (customer-facing landing page)
13. Updates to `app/Acme/IpnProcessor.php` for the customer_order linkage + deliverables-page CTA
14. Updates to `app/views/emails/web_image.blade.php` and `emails/high_res_image.blade.php` to
    include the CTA. High-res email gets a fresh template that doesn't reference an attachment.
15. Route registrations in `app/routes-api.php` (the `api/v1` block) and `app/routes.php` (the
    customer-facing tokenized URLs)
16. Auth filter in `app/filters.php`
17. Config additions: `app/config/proofgen.php` for the API token + a few feature flags
18. AWS SDK / Flysystem S3 adapter dependency (verify ferraraphoto's composer.json — it may
    already have `aws/aws-sdk-php` v2 from the legacy era; if so, upgrade to a compatible v3
    that runs on PHP 7.4)
19. A pest-equivalent or PHPUnit test pass for at least the auth filter, the upsert idempotency
    on shows + photos, the customer order tokenization, and the streamed download

## What's deliberately not in this API

- File uploads. Bytes go from proofgen directly to cloud storage; ferraraphoto only handles
  metadata.
- Webhooks / callbacks back to proofgen. proofgen is a local desktop app with no public URL.
  Reconciliation is via proofgen polling `GET /api/v1/shows/{slug}` and
  `GET /api/v1/shows/{slug}/deliveries` when the operator opens the relevant view.
- User authentication. Bearer token only. No OAuth, no per-user scopes, no audit logging.
- Pagination on small endpoints. Only deliveries paginate; shows/classes/photos are small enough
  per show that no listing endpoints are paginated. (We don't expose a "list all shows" API.)
- Soft deletes. ferraraphoto can hide a Photo via `hidden_at` (existing behavior); the API
  doesn't expose a delete endpoint.
