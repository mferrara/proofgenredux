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
        // Main enhancement settings
        Configuration::updateOrCreate(
            ['key' => 'image_enhancement_enabled'],
            [
                'value' => 'false',
                'type' => 'boolean',
                'category' => 'enhancement',
                'label' => 'Enable Image Enhancement',
                'description' => 'Enable automatic image enhancement during processing',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'image_enhancement_method'],
            [
                'value' => 'basic_auto_levels',
                'type' => 'string',
                'category' => 'enhancement',
                'label' => 'Enhancement Method',
                'description' => 'Method to use for image enhancement',
            ]
        );

        // Apply to different image types
        Configuration::updateOrCreate(
            ['key' => 'enhancement_apply_to_proofs'],
            [
                'value' => 'true',
                'type' => 'boolean',
                'category' => 'enhancement',
                'label' => 'Apply to Proof Thumbnails',
                'description' => 'Apply enhancement when generating proof thumbnails',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'enhancement_apply_to_web'],
            [
                'value' => 'false',
                'type' => 'boolean',
                'category' => 'enhancement',
                'label' => 'Apply to Web Images',
                'description' => 'Apply enhancement when generating web images',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'enhancement_apply_to_highres'],
            [
                'value' => 'false',
                'type' => 'boolean',
                'category' => 'enhancement',
                'label' => 'Apply to High-Res Images',
                'description' => 'Apply enhancement when generating high-resolution images',
            ]
        );

        // Advanced settings
        Configuration::updateOrCreate(
            ['key' => 'enhancement_percentile_low'],
            [
                'value' => '0.1',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Percentile Low Threshold',
                'description' => 'Lower percentile for clipping (0.0-1.0)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'enhancement_percentile_high'],
            [
                'value' => '99.9',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Percentile High Threshold',
                'description' => 'Upper percentile for clipping (99.0-100.0)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'enhancement_clahe_clip_limit'],
            [
                'value' => '2.0',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'CLAHE Clip Limit',
                'description' => 'Contrast limiting parameter for CLAHE (1.0-4.0)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'enhancement_clahe_grid_size'],
            [
                'value' => '8',
                'type' => 'integer',
                'category' => 'enhancement',
                'label' => 'CLAHE Grid Size',
                'description' => 'Grid size for CLAHE algorithm (4-16)',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $keys = [
            'image_enhancement_enabled',
            'image_enhancement_method',
            'enhancement_apply_to_proofs',
            'enhancement_apply_to_web',
            'enhancement_apply_to_highres',
            'enhancement_percentile_low',
            'enhancement_percentile_high',
            'enhancement_clahe_clip_limit',
            'enhancement_clahe_grid_size',
        ];

        Configuration::whereIn('key', $keys)->delete();
    }
};
