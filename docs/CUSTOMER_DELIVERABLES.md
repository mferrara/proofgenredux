# Customer-Facing Deliverables Page

> Companion to `FERRARAPHOTO_API_SPEC.md` §8-10. This doc walks through the customer flow with
> all the UX details that don't fit cleanly in the API spec.

## The flow

1. Customer pays for one or more instant-delivery products on ferraraphoto.com.
2. PayPal IPN fires. `IpnProcessor` finds-or-creates a `customer_orders` row (one per IPN
   `txn_id`) with a fresh `access_token`.
3. For each line item:
   - Photo is found by proof number.
   - `image_deliveries` row inserted, linked to the `customer_orders` row.
   - **Web image**: file streamed from the photo's pinned storage profile to a temp file,
     attached to the email. Email body includes a "View all your deliverables" CTA pointing
     at `https://ferraraphoto.com/deliveries/{access_token}`.
   - **High-res image**: no attachment. Email body contains a "Click here to download your
     high resolution image" CTA pointing at the same `/deliveries/{access_token}` URL.
4. Customer clicks the CTA → lands on the deliverables page.
5. Customer clicks "Download" on a deliverable → ferraraphoto streams the file from cloud
   through its own web process, incrementing the per-deliverable download counters.

## Why both attach + link for web images

- Most customers expect an email attachment for the lower-resolution image.
- The link doubles as "I lost the email" / "I want to download again later" recovery.
- It also tracks repeat-download stats which we wouldn't get with attach-only.
- Bandwidth cost of the link path is negligible (~1-3MB per click, low volume).

## Why link-only for high-res images

- Files routinely exceed 25MB. Email providers reject or silently drop large attachments.
- The link gives a consistent UX, lets us resume downloads via `Range:` requests if the SDK
  supports it, and lets us count downloads.

## Page UX (`/deliveries/{token}`)

```
+-------------------------------------------------------------+
|  Ferraraphoto                                               |
+-------------------------------------------------------------+
|                                                             |
|  Your Deliverables                                          |
|                                                             |
|  Order placed on May 9, 2026                                |
|  Delivered to customer@example.com                          |
|                                                             |
|  [Thumbnail]  REDUX26R12_00001                              |
|               Web Image                                     |
|               2.1 MB · downloaded 0 times                   |
|               [ Download ]                                  |
|                                                             |
|  [Thumbnail]  REDUX26R12_00001                              |
|               High Resolution                               |
|               42.5 MB · downloaded 0 times                  |
|               [ Download ]                                  |
|                                                             |
|  [Thumbnail]  REDUX26R12_00007                              |
|               Web Image                                     |
|               1.8 MB · downloaded 1 time · 2 hours ago      |
|               [ Download ]                                  |
|                                                             |
|  ─────────                                                  |
|  Need help? Contact support@ferraraphoto.com                |
|                                                             |
+-------------------------------------------------------------+
```

The thumbnail is the existing `_std.jpg` proof, served straight from the photo's pinned profile
(or the legacy proofs path for un-migrated shows). No watermark stripping — these are the same
proof thumbnails public-site visitors see.

For Phase 2 v1, no auth on the page — the token is the capability. (Future work could add an
"enter your email to view" gate if we ever see misuse, but it's unnecessary today.)

## Token lifecycle

- Generated as `bin2hex(random_bytes(32))` (64 hex chars).
- Stored on `customer_orders.access_token`, unique index.
- **Never expires** by default. Low volume + trusted use case + the value to the customer of
  "I bought this six months ago and want to download again" outweighs any leak risk.
- Admin can manually invalidate (`UPDATE customer_orders SET access_token = NULL`) if abuse is
  ever observed. The deliverables page returns 404 for null tokens.

## Streaming download specifics

`GET /deliveries/{token}/download/{delivery_id}` is the only "interesting" route. Things the
implementation must get right:

1. **Stream, don't buffer.** Open the file via `Storage::disk(...)->readStream(...)`, then
   `fpassthru()`. Disable PHP output buffering before streaming
   (`while (ob_get_level()) ob_end_clean();`).
2. **Set `Content-Length`** if the SDK exposes it (S3 `HeadObject` does). Without it, Nginx
   buffers the response which defeats the purpose.
3. **Suggest a download filename** via `Content-Disposition: attachment; filename="..."`.
   Use the proof number + content type, e.g., `REDUX26R12_00001_web.jpg`.
4. **Increment counters atomically** — `image_deliveries.download_count++`,
   `last_downloaded_at = now()`. Wrap in a single `UPDATE` so concurrent downloads of the same
   deliverable don't lose counts.
5. **Handle missing source file** with a 404 + a logged error so the operator knows. Don't let
   the AWS SDK exception bubble up as a 500.
6. **Cache headers**: `Cache-Control: private, max-age=0, no-store` so browsers don't cache
   the binary. The token URL itself can be cached but the file content shouldn't be.

L4.2 caveat: confirm the current Flysystem version supports `readStream`. The repo's
`composer.json` likely has `league/flysystem` v1.x via the L4.2 IoC bindings. If `readStream`
isn't there, drop to AWS SDK directly (`$client->getObject(['SaveAs' => fopen('php://output', 'wb')])`).

## Email template changes

`app/views/emails/web_image.blade.php`:

```blade
<p>Hi,</p>

<p>Thanks for your order! Your web image is attached. You can also re-download it any time
from your deliverables page:</p>

<p style="margin: 24px 0;">
    <a href="{{ $deliverables_url }}"
       style="background: #6c5ce7; color: white; padding: 12px 24px;
              text-decoration: none; border-radius: 4px; display: inline-block;">
        View all your deliverables
    </a>
</p>

<p>Proof number: {{ $proof_number }}</p>
```

`app/views/emails/high_res_image.blade.php` (new template):

```blade
<p>Hi,</p>

<p>Thanks for your order! Because high resolution images are too large to email directly,
we've prepared a download page for you:</p>

<p style="margin: 24px 0;">
    <a href="{{ $deliverables_url }}"
       style="background: #6c5ce7; color: white; padding: 12px 24px;
              text-decoration: none; border-radius: 4px; display: inline-block;">
        Download your high resolution image
    </a>
</p>

<p>Proof number: {{ $proof_number }}</p>
<p>This page also lists any other images from this order.</p>
```

`Photo::sendImageToClient($email, $type)` gets a third parameter `$attach_file = true`. The
high-res caller passes false. Both callers now also pass `$deliverables_url` into the view.

## Admin view of deliverables

The existing `/admin/deliveries` page gains two columns: `download_count`,
`last_downloaded_at`. Filter options "downloaded / never downloaded" let the admin spot
deliveries that haven't been claimed yet (potentially for a "re-send the link" follow-up).

A per-row "Resend deliverables link" button regenerates a fresh email body and emails it
again. Doesn't change the access_token — the customer keeps the same URL.
