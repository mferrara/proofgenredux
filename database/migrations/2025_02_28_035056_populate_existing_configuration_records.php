<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $proofgen_config = config('proofgen');

        $configurations = [
            'fullsize_home_dir' => [
                'value' => $proofgen_config['fullsize_home_dir'],
                'type' => 'path',
                'category' => null,
                'label' => 'Fullsize Home Directory',
                'description' => 'The full path to the directory where full-size images are stored.',
            ],
            'archive_home_dir' => [
                'value' => $proofgen_config['archive_home_dir'],
                'type' => 'path',
                'category' => 'archive',
                'label' => 'Archive Home Directory',
                'description' => 'The full path to the directory where archived images are stored.',
            ],
            'archive_enabled' => [
                'value' => $proofgen_config['archive_enabled'],
                'type' => 'boolean',
                'category' => 'archive',
                'label' => 'Archive Enabled',
                'description' => 'Whether the archive/backup feature is enabled.',
            ],
            'test_source_dir' => [
                'value' => $proofgen_config['test_source_dir'],
                'type' => 'path',
                'category' => null,
                'label' => 'Test Source Directory',
                'description' => 'The full path to the directory where test images are stored.',
            ],
            'rename_files' => [
                'value' => $proofgen_config['rename_files'],
                'type' => 'boolean',
                'category' => 'proofs',
                'label' => 'Rename Files',
                'description' => 'Whether to rename images during import/processing.',
            ],
            'upload_proofs' => [
                'value' => $proofgen_config['upload_proofs'],
                'type' => 'boolean',
                'category' => 'proofs',
                'label' => 'Upload Proofs',
                'description' => 'Whether to upload proofs to the server.',
            ],
            'watermark_proofs' => [
                'value' => $proofgen_config['watermark_proofs'],
                'type' => 'boolean',
                'category' => 'watermarks',
                'label' => 'Watermark Proofs',
                'description' => 'Whether to watermark proofs.',
            ],
            'watermark_font' => [
                'value' => $proofgen_config['watermark_font'],
                'type' => 'path',
                'category' => 'watermarks',
                'label' => 'Watermark Font',
                'description' => 'The file path of the font used for watermarks.',
            ],
            'watermark_background_opacity' => [
                'value' => $proofgen_config['watermark_background_opacity'],
                'type' => 'integer',
                'category' => 'watermarks',
                'label' => 'Watermark Background Opacity',
                'description' => 'The opacity of the watermark background.',
            ],
            'watermark_foreground_opacity' => [
                'value' => $proofgen_config['watermark_foreground_opacity'],
                'type' => 'integer',
                'category' => 'watermarks',
                'label' => 'Watermark Foreground Opacity',
                'description' => 'The opacity of the watermark foreground.',
            ],
            'thumbnails.small.suffix' => [
                'value' => $proofgen_config['thumbnails']['small']['suffix'],
                'type' => 'string',
                'category' => 'thumbnails',
                'label' => 'Small Thumbnail Suffix',
                'description' => 'The suffix for small thumbnails.',
            ],
            'thumbnails.small.width' => [
                'value' => $proofgen_config['thumbnails']['small']['width'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Small Thumbnail Width',
                'description' => 'The width of small thumbnails.',
            ],
            'thumbnails.small.height' => [
                'value' => $proofgen_config['thumbnails']['small']['height'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Small Thumbnail Height',
                'description' => 'The height of small thumbnails.',
            ],
            'thumbnails.small.quality' => [
                'value' => $proofgen_config['thumbnails']['small']['quality'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Small Thumbnail Quality',
                'description' => 'The quality of small thumbnails.',
            ],
            'thumbnails.small.font_size' => [
                'value' => $proofgen_config['thumbnails']['small']['font_size'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Small Thumbnail Font Size',
                'description' => 'The font size for small thumbnails.',
            ],
            'thumbnails.small.bg_size' => [
                'value' => $proofgen_config['thumbnails']['small']['bg_size'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Small Thumbnail Background Size',
                'description' => 'The background size for small thumbnails.',
            ],
            'thumbnails.large.suffix' => [
                'value' => $proofgen_config['thumbnails']['large']['suffix'],
                'type' => 'string',
                'category' => 'thumbnails',
                'label' => 'Large Thumbnail Suffix',
                'description' => 'The suffix for large thumbnails.',
            ],
            'thumbnails.large.width' => [
                'value' => $proofgen_config['thumbnails']['large']['width'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Large Thumbnail Width',
                'description' => 'The width of large thumbnails.',
            ],
            'thumbnails.large.height' => [
                'value' => $proofgen_config['thumbnails']['large']['height'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Large Thumbnail Height',
                'description' => 'The height of large thumbnails.',
            ],
            'thumbnails.large.quality' => [
                'value' => $proofgen_config['thumbnails']['large']['quality'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Large Thumbnail Quality',
                'description' => 'The quality of large thumbnails.',
            ],
            'thumbnails.large.font_size' => [
                'value' => $proofgen_config['thumbnails']['large']['font_size'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Large Thumbnail Font Size',
                'description' => 'The font size for large thumbnails.',
            ],
            'thumbnails.large.bg_size' => [
                'value' => $proofgen_config['thumbnails']['large']['bg_size'],
                'type' => 'integer',
                'category' => 'thumbnails',
                'label' => 'Large Thumbnail Background Size',
                'description' => 'The background size for large thumbnails.',
            ],
            'web_images.suffix' => [
                'value' => $proofgen_config['web_images']['suffix'],
                'type' => 'string',
                'category' => 'web_images',
                'label' => 'Web Images Suffix',
                'description' => 'The suffix for web images.',
            ],
            'web_images.width' => [
                'value' => $proofgen_config['web_images']['width'],
                'type' => 'integer',
                'category' => 'web_images',
                'label' => 'Web Images Width',
                'description' => 'The width of web images.',
            ],
            'web_images.height' => [
                'value' => $proofgen_config['web_images']['height'],
                'type' => 'integer',
                'category' => 'web_images',
                'label' => 'Web Images Height',
                'description' => 'The height of web images.',
            ],
            'web_images.quality' => [
                'value' => $proofgen_config['web_images']['quality'],
                'type' => 'integer',
                'category' => 'web_images',
                'label' => 'Web Images Quality',
                'description' => 'The quality of web images.',
            ],
            'web_images.font_size' => [
                'value' => $proofgen_config['web_images']['font_size'],
                'type' => 'integer',
                'category' => 'web_images',
                'label' => 'Web Images Font Size',
                'description' => 'The font size for web images.',
            ],
            'web_images.bg_size' => [
                'value' => $proofgen_config['web_images']['bg_size'],
                'type' => 'integer',
                'category' => 'web_images',
                'label' => 'Web Images Background Size',
                'description' => 'The background size for web images.',
            ],
            'sftp.host' => [
                'value' => $proofgen_config['sftp']['host'],
                'type' => 'string',
                'category' => 'sftp',
                'label' => 'SFTP Host',
                'description' => 'The SFTP host address.',
            ],
            'sftp.port' => [
                'value' => $proofgen_config['sftp']['port'],
                'type' => 'integer',
                'category' => 'sftp',
                'label' => 'SFTP Port',
                'description' => 'The SFTP port number.',
            ],
            'sftp.username' => [
                'value' => $proofgen_config['sftp']['username'],
                'type' => 'string',
                'category' => 'sftp',
                'label' => 'SFTP Username',
                'description' => 'The SFTP username.',
            ],
            'sftp.private_key' => [
                'value' => $proofgen_config['sftp']['private_key'],
                'type' => 'path',
                'category' => 'sftp',
                'label' => 'SFTP Private Key',
                'description' => 'The file path of the SFTP private key.',
            ],
            'sftp.path' => [
                'value' => $proofgen_config['sftp']['path'],
                'type' => 'path',
                'category' => 'sftp',
                'label' => 'SFTP Proofs Path',
                'description' => 'The path to the "proofs" directory on the server.',
            ],
            'sftp.web_images_path' => [
                'value' => $proofgen_config['sftp']['web_images_path'],
                'type' => 'path',
                'category' => 'sftp',
                'label' => 'SFTP Web Images Path',
                'description' => 'The path to the directory where web images are stored on the server.',
            ],
        ];
        foreach ($configurations as $key => $config) {
            Configuration::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $config['value'],
                    'type' => $config['type'],
                    'category' => $config['category'],
                    'label' => $config['label'],
                    'description' => $config['description'],
                    'is_private' => $config['is_private'] ?? false,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
