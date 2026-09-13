# Local test isolation

Run tests in a disposable checkout, separate from the operator's Herd checkout.
The suite is destructive by design (`RefreshDatabase`) and some tests write
synthetic image files or run child processes. Never supply the operator database,
`.env`, image roots, remote credentials, or a symlink back to its `vendor` tree.

## Prepare a committed source snapshot

From the project root, with dependencies already installed:

```sh
proofgen_source="$PWD"
proofgen_test_checkout=$(mktemp -d "${TMPDIR:-/tmp}/proofgen-tests.XXXXXX")
git archive HEAD | tar -x -C "$proofgen_test_checkout"
cp -R "$proofgen_source/vendor" "$proofgen_test_checkout/vendor"
cat > "$proofgen_test_checkout/.env" <<'ENV'
APP_ENV=testing
APP_URL=http://localhost
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_STORE=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
AUTO_DOWNLOAD_SAMPLE_IMAGES=false
ENV
cd "$proofgen_test_checkout"
```

This tests **committed HEAD**. For uncommitted fixes, explicitly copy the changed
source/test files into the disposable checkout before running; do not copy the
whole live working directory. The vendor copy must be physical: a vendor symlink
can make Composer/Laravel infer the operator checkout as the application base.
The committed watermark fonts/assets are included by `git archive`.

Use the project's Herd PHP 8.4 binary. On this Mac:

```sh
proofgen_php='/Users/mikeferrara/Library/Application Support/Herd/bin/php84'
PATH=/usr/bin:/bin "$proofgen_php" vendor/bin/pest --compact
# A focused test, still from the disposable checkout:
PATH=/usr/bin:/bin "$proofgen_php" vendor/bin/pest tests/Feature/SettingsLargePreviewTest.php
```

The restricted PATH matches the ordinary local regression run and avoids
accidentally discovering optional host integrations. Do not run `artisan migrate`
to prepare tests; `RefreshDatabase` builds the in-memory schema.

## Guards provided by the repository

`phpunit.xml` forces testing, SQLite `:memory:`, an empty DB URL, array cache/session,
synchronous queues, blank SFTP credentials/targets, and disabled sample downloads.
`tests/TestCase.php` mirrors these into `$_SERVER`, refuses cached configuration or
non-isolated DB settings before providers/migrations, pins the named upload queue
to sync, and blocks unfaked HTTP requests. Cache paths are redirected away from
`bootstrap/cache` operator artifacts.

If a guard fails, fix the disposable checkout/configuration. Do not remove the
guard or substitute a disk-backed operator database to get a passing run.

## Optional rendering and sample tests

The regular suite covers synthetic GD processing, EXIF orientation, watermark
fitting/quality, upload evidence, import identity, file preservation, and Livewire
states. Missing optional sample/host integrations can produce skips; inspect their
reason before interpreting a green run as end-to-end validation.

For the real macOS Swift/Metal renderer, in the disposable checkout with its own
temporary files and development tools available:

```sh
PROOFGEN_TEST_CORE_IMAGE=1 "$proofgen_php" vendor/bin/pest tests/Feature/CoreImageRenderingTest.php
```

That test compiles the daemon source and uses stdin mode without starting or
stopping the application's daemon. NAS imports and real upload rehearsals are
separate operator-data exercises, documented in [datasets](datasets/README.md).

## Latest recorded result

Implementation `2aae180`, September 13, 2026: **486 passed, 22 skipped, 2,105
assertions** in the isolated checkout. The [rehearsal report](reviews/2026-09-13-show-prep-finish.md)
records the separate 48-photo real-file validation. These are dated results, not
an assertion that every future checkout has been tested.
