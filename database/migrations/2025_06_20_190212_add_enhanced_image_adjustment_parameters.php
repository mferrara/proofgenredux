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
        // Adjustable Auto-Levels parameters
        Configuration::updateOrCreate(
            ['key' => 'auto_levels_target_brightness'],
            [
                'value' => '128',
                'type' => 'integer',
                'category' => 'enhancement',
                'label' => 'Target Brightness',
                'description' => 'Target mean brightness for auto-levels (0-255)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'auto_levels_contrast_threshold'],
            [
                'value' => '200',
                'type' => 'integer',
                'category' => 'enhancement',
                'label' => 'Contrast Threshold',
                'description' => 'Histogram range threshold for contrast boost (0-255)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'auto_levels_contrast_boost'],
            [
                'value' => '1.2',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Contrast Boost',
                'description' => 'Contrast multiplier when range is below threshold (1.0-2.0)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'auto_levels_black_point'],
            [
                'value' => '0',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Black Point Clipping',
                'description' => 'Percentage of shadow values to clip (0-5%)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'auto_levels_white_point'],
            [
                'value' => '100',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'White Point Clipping',
                'description' => 'Percentage of highlight values to preserve (95-100%)',
            ]
        );

        // Advanced Tone Mapping parameters
        Configuration::updateOrCreate(
            ['key' => 'tone_mapping_shadow_amount'],
            [
                'value' => '0',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Shadow Adjustment',
                'description' => 'Shadow brightness adjustment (-100 to 100)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'tone_mapping_highlight_amount'],
            [
                'value' => '0',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Highlight Adjustment',
                'description' => 'Highlight brightness adjustment (-100 to 100)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'tone_mapping_shadow_radius'],
            [
                'value' => '30',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Shadow/Highlight Radius',
                'description' => 'Blend radius for shadow/highlight adjustments (0-100)',
            ]
        );

        Configuration::updateOrCreate(
            ['key' => 'tone_mapping_midtone_gamma'],
            [
                'value' => '1.0',
                'type' => 'float',
                'category' => 'enhancement',
                'label' => 'Midtone Gamma',
                'description' => 'Gamma correction for midtones (0.5-2.0)',
            ]
        );

        // Remove CLAHE parameters
        Configuration::whereIn('key', [
            'enhancement_clahe_clip_limit',
            'enhancement_clahe_grid_size',
        ])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove new parameters
        $newKeys = [
            'auto_levels_target_brightness',
            'auto_levels_contrast_threshold',
            'auto_levels_contrast_boost',
            'auto_levels_black_point',
            'auto_levels_white_point',
            'tone_mapping_shadow_amount',
            'tone_mapping_highlight_amount',
            'tone_mapping_shadow_radius',
            'tone_mapping_midtone_gamma',
        ];

        Configuration::whereIn('key', $newKeys)->delete();

        // Restore CLAHE parameters
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
};
