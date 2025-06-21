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

        // Only add highres configuration entries if they don't already exist
        $highres_configurations = [
            'highres_images.suffix' => [
                'value' => $proofgen_config['highres_images']['suffix'] ?? '_highres',
                'type' => 'string',
                'category' => 'highres_images',
                'label' => 'High Resolution Images Suffix',
                'description' => 'The suffix for high resolution images.',
            ],
            'highres_images.width' => [
                'value' => $proofgen_config['highres_images']['width'] ?? 3000,
                'type' => 'integer',
                'category' => 'highres_images',
                'label' => 'High Resolution Images Width',
                'description' => 'The width of high resolution images.',
            ],
            'highres_images.height' => [
                'value' => $proofgen_config['highres_images']['height'] ?? 3000,
                'type' => 'integer',
                'category' => 'highres_images',
                'label' => 'High Resolution Images Height',
                'description' => 'The height of high resolution images.',
            ],
            'highres_images.quality' => [
                'value' => $proofgen_config['highres_images']['quality'] ?? 95,
                'type' => 'integer',
                'category' => 'highres_images',
                'label' => 'High Resolution Images Quality',
                'description' => 'The quality of high resolution images.',
            ],
            'sftp.highres_images_path' => [
                'value' => $proofgen_config['sftp']['highres_images_path'] ?? '/home/forge/staging.ferraraphoto.com/app/storage/high_res_images',
                'type' => 'path',
                'category' => 'sftp',
                'label' => 'SFTP High Resolution Images Path',
                'description' => 'The path to the directory where high resolution images are stored on the server.',
            ],
        ];

        foreach ($highres_configurations as $key => $config) {
            Configuration::firstOrCreate(
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
        // Remove the highres configuration entries
        Configuration::whereIn('key', [
            'highres_images.suffix',
            'highres_images.width',
            'highres_images.height',
            'highres_images.quality',
            'sftp.highres_images_path',
        ])->delete();
    }
};
