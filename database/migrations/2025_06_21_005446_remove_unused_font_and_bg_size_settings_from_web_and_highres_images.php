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
        // Remove unused font_size and bg_size settings for web and highres images
        Configuration::whereIn('key', [
            'web_images.font_size',
            'web_images.bg_size',
            'highres_images.font_size',
            'highres_images.bg_size',
        ])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add the configuration entries if rolling back
        $configurations = [
            'web_images.font_size' => [
                'value' => 22,
                'type' => 'integer',
                'category' => 'web_images',
                'label' => 'Web Images Font Size',
                'description' => 'The font size for web images.',
            ],
            'web_images.bg_size' => [
                'value' => 42,
                'type' => 'integer',
                'category' => 'web_images',
                'label' => 'Web Images Background Size',
                'description' => 'The background size for web images.',
            ],
            'highres_images.font_size' => [
                'value' => 22,
                'type' => 'integer',
                'category' => 'highres_images',
                'label' => 'High Resolution Images Font Size',
                'description' => 'The font size for high resolution images.',
            ],
            'highres_images.bg_size' => [
                'value' => 42,
                'type' => 'integer',
                'category' => 'highres_images',
                'label' => 'High Resolution Images Background Size',
                'description' => 'The background size for high resolution images.',
            ],
        ];

        foreach ($configurations as $key => $config) {
            Configuration::create([
                'key' => $key,
                'value' => $config['value'],
                'type' => $config['type'],
                'category' => $config['category'],
                'label' => $config['label'],
                'description' => $config['description'],
                'is_private' => false,
            ]);
        }
    }
};
