<?php

return [
    'fullsize_home_dir' => getenv('FULLSIZE_HOME_DIR'),
    'archive_home_dir' => getenv('ARCHIVE_HOME_DIR'),
    'archive_enabled' => getenv('ARCHIVE_ENABLED') === 'TRUE',
    'test_source_dir' => getenv('TEST_SOURCE_DIR'),

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

    'sftp' => [
        'host' => getenv('SFTP_HOSTNAME'),
        'port' => getenv('SFTP_PORT') ?: 22,
        'username' => getenv('SFTP_USERNAME'),
        'private_key' => getenv('SFTP_PATHTOPRIVATEKEY'),
        'path' => getenv('SFTP_PROOFSPATH'),
        'web_images_path' => getenv('SFTP_WEB_IMAGES_PATH'),
        'highres_images_path' => getenv('SFTP_HIGHRES_IMAGES_PATH'),
    ],
];
