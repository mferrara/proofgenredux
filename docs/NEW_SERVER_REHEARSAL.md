# New-server mock show

Prepared September 15, 2026, after delivery fix `ee96150`. No new-server
connection, import, generation, or delivery has been run.

## Ready locally

- Eight real JPEG originals, four per class, **49,180,751 bytes (46.9 MiB)**.
- Staged on TrueNAS at `/Volumes/Public/proofgen-dev/rehearsals/REMOTEDEMO26`,
  outside the live import root. Source copies and staged copies have matching
  SHA1 hashes. All eight differ from the existing 72 catalog photos.
- Manifest: [remote_demo26.json](datasets/remote_demo26.json). Classes `001` and
  `002_A`; proposed local show `REMOTEDEMO26`, remote slug `proofgen-rehearsal-26`.
  The different slug and underscore class exercise both path mappings.
- Existing shows and their 72 photo records are unchanged. No new show row exists.
- Saved `upload_proofs=false`. Local API overrides are deliberately parked:
  `ferraraphoto.base_url=https://new-proofgen-server.invalid` and
  `ferraraphoto.api_token` empty. Existing `.env` credentials were not edited.
- File transport still uses the existing local NAS destinations. Do not click
  Upload until the new target is configured. Manual Upload bypasses the auto switch.
- Horizon is stopped while the rehearsal is parked. Start it from Settings
  after configuring the new target so fresh workers load the delivery fixes
  and final connection settings.

## Information needed when the server is ready

Use [the configuration worksheet](examples/new-server.env.example). Initial
rehearsal assumes **rsync over SSH plus the Ferraraphoto API**. S3 can be a later
pass; do not change storage architecture merely to run this test.

Collect the new site's HTTPS base URL, SSH hostname/port/user, authorized key
path, and the absolute roots for proofs, web images and highres images. Configure
a new matching bearer token: Proofgen uses `FERRARAPHOTO_API_TOKEN`, while the
current Ferraraphoto source uses `PROOFGEN_API_TOKEN`. Keep tokens out of Git.

The target application must contain the `/api/v1` storage-profile, show, class,
photo-upsert and readback endpoints, plus their database migrations. These are
present in the sibling Ferraraphoto source; their deployment to the new server
still needs verification. Successful responses use HTTP 200 with a JSON `data`
wrapper. An SSH-only file server does not exercise this integration.

For the current Ferraraphoto layout, create writable roots corresponding to
`public/proofs`, `app/storage/web_images`, and `app/storage/high_res_images`.
The SSH user must be able to write them and the application must be able to
read them. Rsync must be available on the destination; the Mac uses rsync 3.
Confirm the SSH host key against the new server and keep host-key checking on.

## Connect later

1. Populate the worksheet values in local `.env` when the target is known. The
   API base is the site origin, **without** `/api/v1`; the client appends it.
2. Update matching saved Configuration rows as well. Saved values override the
   environment for application settings, while the SFTP filesystem disks read
   their connection values from the environment. Both must identify the same
   new target. Do not change the NAS fullsize/archive roots.

   | Environment | Saved Configuration key |
   |---|---|
   | `TRANSPORT_DRIVER=sftp` | `sftp.driver` |
   | `SFTP_HOSTNAME`, `SFTP_PORT`, `SFTP_USERNAME` | `sftp.host`, `sftp.port`, `sftp.username` |
   | `SFTP_PATHTOPRIVATEKEY` | `sftp.private_key` |
   | `SFTP_PROOFSPATH` | `sftp.path` |
   | `SFTP_WEB_IMAGES_PATH` | `sftp.web_images_path` |
   | `SFTP_HIGHRES_IMAGES_PATH` | `sftp.highres_images_path` |
   | `FERRARAPHOTO_API_BASE` | `ferraraphoto.base_url` — replace parked override |
   | `FERRARAPHOTO_API_TOKEN` | `ferraraphoto.api_token` — replace empty override |
   | `UPLOAD_PROOFS=FALSE` | `upload_proofs=false` for the first class |

3. Restart idle Horizon and check the effective app **and filesystem** host/root
   values without printing the token. The `legacy-local` profile should be active
   and writable for this rsync rehearsal. Legacy targets are shared by existing
   shows, so operate only on `REMOTEDEMO26` during this test.
4. Verify SSH authentication, destination rsync, and access to all three roots.
   `/config/server` only tests the proofs listing; it is not the API handshake.
5. Use `FerraraphotoApiClient::readShow('proofgen-rehearsal-26')` for the first
   authenticated read. Before creation, `null` from an authenticated 404 is normal;
   a 401, HTML page, transport error, or unexpected existing show needs resolution.

For example, **only once the new settings have been verified**:

```sh
php artisan tinker --execute="dump(app(\App\Services\Ferraraphoto\FerraraphotoApiClient::class)->readShow('proofgen-rehearsal-26'));"
```

## Run the show later

1. Recheck the manifest hashes against the local catalog; don't reuse these
   originals under a second show if they have since been imported. Move the
   staged show folder into `/Volumes/Public/proofgen-dev/shows/REMOTEDEMO26`
   only if that destination does not already exist. This is a same-NAS move,
   not another laptop copy. Leave the pristine backup library untouched.
2. Open the new show in Proofgen. Set the Ferraraphoto slug to
   `proofgen-rehearsal-26` before delivery; confirm its pinned profile.
3. With automatic uploads OFF, import class `001` and generate all three kinds.
   Expect four unique photos and 16 generated files. No automatic transfer should
   occur. Click Upload and verify files arrive before photo metadata is pushed.
4. Confirm the new site's show/class has four photos, both proof sizes display,
   and the web/highres originals resolve through the application. Compare the
   generated and received file checksums, not just upload timestamps.
5. Turn automatic uploads ON, import class `002_A`, and watch all four photos
   generate and deliver without clicking Upload. Repeated UI actions should stay
   disabled while work is active. Restore automatic uploads OFF afterward.
6. Expected total: **8 photo records, 2 classes, 16 proofs, 8 web images and 8
   highres images**. API readback must report classes `001` and `002_A` with four
   photos each and the selected storage-profile ID. Verify all 32 file checksums.
7. Deliberately interrupt one transfer against this test target, then restore
   connectivity and retry. The failure must remain visible, incomplete files
   must not count as uploaded, and metadata must follow a successful transfer.
   A successful retry should not create extra records or proof numbers.
8. Exercise the site's web/highres download flow using its test-payment or
   operator-granted download mechanism. No real charge or customer email is
   needed. Capture any application-side failures separately from file transfer.

Record source revisions, effective target/profile, class counts, 32 checksum
comparisons, queue/failed-job state, and the download result in a dated review.
The rehearsal passes only after the real website and file-delivery checks pass.

## Local process note

During preparation, Horizon's master still used the Mac's old hostname, so
`horizon:terminate` restarted workers but did not select that master. Its cwd and
empty queues were verified, then that exact process was gracefully terminated
and stopped. CLI start attempts did not leave a persistent replacement; the
Settings UI confirmed Stopped. Start through Settings when beginning the
rehearsal. No broad process kill or queue flush was used.
