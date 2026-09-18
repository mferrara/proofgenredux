<?php

/*
|--------------------------------------------------------------------------
| Upload timing budget
|--------------------------------------------------------------------------
|
| One transport timeout is the single source of truth; every upload budget
| below derives from it so this ordering cannot silently invert:
|
|   one rsync transfer
|     < one upload job budget (one transfer + overhead)
|       < nested derived-upload budget (proofs + web + highres + overhead)
|         < uploads queue retry_after (longest job + margin)
|
| config/queue.php and config/horizon.php require this file and read the same
| derived values, because config() is not reliably populated while the
| configuration files themselves are being loaded. Changing
| SFTP_TRANSFER_TIMEOUT (or clearing config caches after changing it) keeps
| the job, worker and retry budgets aligned.
|
*/
$transferTimeout = (int) env('SFTP_TRANSFER_TIMEOUT', 1800);
if ($transferTimeout <= 0) {
    $transferTimeout = 1800;
}

$jobOverhead = (int) env('UPLOAD_JOB_OVERHEAD', 300);
if ($jobOverhead <= 0) {
    $jobOverhead = 300;
}

// One upload job performs at most one rsync transfer.
$singleUploadJobTimeout = $transferTimeout + $jobOverhead;

// UploadDerivedFiles runs proofs + web + highres synchronously inside one
// worker on the legacy-local path, so its budget must cover three transfers.
$derivedUploadJobTimeout = (3 * $singleUploadJobTimeout) + $jobOverhead;

// Redis releases an unacked job back onto the queue after retry_after. It must
// stay above the longest job budget plus the worker-side margin, otherwise a
// still-running upload would be handed to a second worker.
$uploadsRetryAfter = $derivedUploadJobTimeout + (2 * $jobOverhead);

return [
    'fullsize_home_dir' => getenv('FULLSIZE_HOME_DIR'),
    'archive_home_dir' => getenv('ARCHIVE_HOME_DIR'),
    'archive_enabled' => getenv('ARCHIVE_ENABLED') === 'TRUE',
    'test_source_dir' => getenv('TEST_SOURCE_DIR'),

    // Source-of-truth file removals (originals, archive copies, ingest sources)
    // are routed into a per-disk graveyard directory instead of being deleted.
    // Derived files (proofs/web/highres) are NOT graveyarded; they regenerate.
    // Path is relative to each disk root.
    'graveyard' => [
        'path' => getenv('GRAVEYARD_PATH') ?: '_graveyard',
        'aged_days' => (int) (getenv('GRAVEYARD_AGED_DAYS') ?: 90),
    ],

    // Sample images configuration
    'auto_download_sample_images' => getenv('AUTO_DOWNLOAD_SAMPLE_IMAGES') === 'TRUE',

    'rename_files' => getenv('RENAME_FILES') === 'TRUE',
    'upload_proofs' => getenv('UPLOAD_PROOFS') === 'TRUE',
    'watermark_proofs' => getenv('WATERMARK_PROOFS'),
    'watermark_font' => getenv('WATERMARK_FONT'),
    'watermark_background_opacity' => getenv('WATERMARK_BACKGROUND_OPACITY'),
    'watermark_foreground_opacity' => getenv('WATERMARK_FOREGROUND_OPACITY'),

    'thumbnails' => [
        'small' => [
            'suffix' => getenv('SMALL_THUMBNAIL_SUFFIX'),
            'width' => getenv('SMALL_THUMBNAIL_WIDTH'),
            'height' => getenv('SMALL_THUMBNAIL_HEIGHT'),
            'quality' => getenv('SMALL_THUMBNAIL_QUALITY'),
            'font_size' => getenv('SMALL_THUMBNAIL_FONT_SIZE'),
            'bg_size' => getenv('SMALL_THUMBNAIL_BG_SIZE'),
        ],
        'large' => [
            'suffix' => getenv('LARGE_THUMBNAIL_SUFFIX'),
            'width' => getenv('LARGE_THUMBNAIL_WIDTH'),
            'height' => getenv('LARGE_THUMBNAIL_HEIGHT'),
            'quality' => getenv('LARGE_THUMBNAIL_QUALITY'),
            'font_size' => getenv('LARGE_THUMBNAIL_FONT_SIZE'),
            'bg_size' => getenv('LARGE_THUMBNAIL_BG_SIZE'),
        ],
    ],

    'web_images' => [
        'suffix' => getenv('WEB_SUFFIX'),
        'width' => getenv('WEB_WIDTH'),
        'height' => getenv('WEB_HEIGHT'),
        'quality' => getenv('WEB_QUALITY'),
    ],

    'generate_web_images' => [
        'enabled' => getenv('GENERATE_WEB_IMAGES_ENABLED') !== 'FALSE',
    ],

    'highres_images' => [
        'suffix' => getenv('HIGHRES_SUFFIX') ?: '_highres',
        'width' => getenv('HIGHRES_WIDTH'),
        'height' => getenv('HIGHRES_HEIGHT'),
        'quality' => getenv('HIGHRES_QUALITY'),
    ],

    'generate_highres_images' => [
        'enabled' => getenv('GENERATE_HIGHRES_IMAGES_ENABLED') !== 'FALSE',
    ],

    'ferraraphoto' => [
        'base_url' => getenv('FERRARAPHOTO_API_BASE') ?: 'https://ferraraphoto.com',
        'api_token' => getenv('FERRARAPHOTO_API_TOKEN'),
    ],

    'cards' => [
        // Pause between photos (minutes) that starts a new class when a card
        // is split automatically. Adjustable in the Card Reader window.
        'gap_minutes' => (int) (getenv('CARD_GAP_MINUTES') ?: 5),
        // Testing only: offer mounted disk images as card sources.
        'allow_disk_images' => getenv('CARD_ALLOW_DISK_IMAGES') === 'TRUE',
    ],

    // Reports filed from an install (php artisan proofgen:report). They go to a
    // PRIVATE GitHub repo as issues; the token needs only "Issues: write" on it.
    'feedback' => [
        'repo' => getenv('PROOFGEN_FEEDBACK_REPO') ?: 'mferrara/proofgen-feedback',
        'token' => getenv('PROOFGEN_FEEDBACK_TOKEN') ?: null,
        // How this machine is named in reports, e.g. "Dad's MacBook".
        'install_name' => getenv('PROOFGEN_INSTALL_NAME') ?: null,
    ],

    'sftp' => [
        'rsync_binary' => env('RSYNC_BINARY'),
        // Transport driver: 'sftp' for rsync-over-SSH (production / staging),
        // 'local' for plain rsync between local directories (local dev against
        // a sibling install on the same Mac, or any future "proofgen runs on
        // the same server as ferraraphoto" deployment). Database-backed
        // Configuration setting can override this per-environment.
        'driver' => getenv('TRANSPORT_DRIVER') ?: 'sftp',
        'host' => getenv('SFTP_HOSTNAME'),
        'port' => getenv('SFTP_PORT') ?: 22,
        'username' => getenv('SFTP_USERNAME') ?: 'forge',
        'private_key' => getenv('SFTP_PATHTOPRIVATEKEY'),
        'path' => getenv('SFTP_PROOFSPATH'),
        'web_images_path' => getenv('SFTP_WEB_IMAGES_PATH'),
        'highres_images_path' => getenv('SFTP_HIGHRES_IMAGES_PATH'),
        // Per-transfer rsync budget. Read by RsyncRunner; every upload queue
        // budget above derives from it.
        'timeout' => $transferTimeout,
    ],

    // Dedicated queue for long upload jobs (config/queue.php connection,
    // config/horizon.php supervisor, app/Jobs/Show*/*).
    'uploads' => [
        'connection' => 'uploads',
        'queue' => 'uploads',
        'single_job_timeout' => $singleUploadJobTimeout,
        'derived_job_timeout' => $derivedUploadJobTimeout,
        'retry_after' => $uploadsRetryAfter,
        // Seconds before each retry; the final value repeats for any extra
        // attempt. Upload failures are usually remote/transient, so back off
        // instead of hammering the SFTP target.
        'backoff' => [60, 300, 900, 1800],

        // Proofs are what customers order from, so they always go first. `queue`
        // above is the proofs queue; web and highres have their own, taken only
        // when no proofs are waiting. A second worker takes nothing but proofs,
        // so a highres transfer already in flight never delays them.
        'web_queue' => 'uploads-web',
        'highres_queue' => 'uploads-highres',
        // Web/highres go up ONE photo per job. They are large and not urgent:
        // after every photo the worker looks for proofs again, and a proof
        // upload shares the connection with at most one big file. This is
        // affordable because a follow-on job skips the per-class preamble (see
        // DeliverClassOutputs). Raise it only on a fast, idle connection.
        'batch_size' => (int) (getenv('UPLOAD_BATCH_SIZE') ?: 1),
        // How long a class's website sync and destination check stay good for
        // its follow-on web/highres jobs.
        'preamble_seconds' => 900,
        // When recent web/highres uploads averaged less than this (kilobits per
        // second), highres waits and tries again later: on bad show wifi it only
        // gets in the way. 0 turns this off.
        'highres_min_kbps' => (int) (getenv('UPLOAD_HIGHRES_MIN_KBPS') ?: 1000),
        'highres_retry_seconds' => 300,
    ],
];
