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

        // Add toggle configuration entries for web images and highres images generation
        $toggle_configurations = [
            'generate_web_images.enabled' => [
                'value' => $proofgen_config['generate_web_images']['enabled'] ?? true,
                'type' => 'boolean',
                'category' => 'web_images',
                'label' => 'Enable Web Images Generation',
                'description' => 'Enable or disable the generation of web images (digital copies for social media).',
            ],
            'generate_highres_images.enabled' => [
                'value' => $proofgen_config['generate_highres_images']['enabled'] ?? true,
                'type' => 'boolean',
                'category' => 'highres_images',
                'label' => 'Enable High Resolution Images Generation',
                'description' => 'Enable or disable the generation of high resolution images.',
            ],
        ];

        foreach ($toggle_configurations as $key => $config) {
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
        // Remove the toggle configuration entries
        Configuration::whereIn('key', [
            'generate_web_images.enabled',
            'generate_highres_images.enabled',
        ])->delete();
    }
};
