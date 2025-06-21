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
        // Rename percentile parameters to use consistent tone_mapping prefix
        $percentileLow = Configuration::where('key', 'enhancement_percentile_low')->first();
        if ($percentileLow) {
            $percentileLow->key = 'tone_mapping_percentile_low';
            $percentileLow->save();
        }

        $percentileHigh = Configuration::where('key', 'enhancement_percentile_high')->first();
        if ($percentileHigh) {
            $percentileHigh->key = 'tone_mapping_percentile_high';
            $percentileHigh->save();
        }

        // Update any stored method values from old to new names
        $methodConfig = Configuration::where('key', 'image_enhancement_method')->first();
        if ($methodConfig) {
            $mapping = [
                'basic_auto_levels' => 'adjustable_auto_levels',
                'percentile_clipping' => 'advanced_tone_mapping',
            ];

            if (isset($mapping[$methodConfig->value])) {
                $methodConfig->value = $mapping[$methodConfig->value];
                $methodConfig->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename back to original parameter names
        $percentileLow = Configuration::where('key', 'tone_mapping_percentile_low')->first();
        if ($percentileLow) {
            $percentileLow->key = 'enhancement_percentile_low';
            $percentileLow->save();
        }

        $percentileHigh = Configuration::where('key', 'tone_mapping_percentile_high')->first();
        if ($percentileHigh) {
            $percentileHigh->key = 'enhancement_percentile_high';
            $percentileHigh->save();
        }

        // Revert method values
        $methodConfig = Configuration::where('key', 'image_enhancement_method')->first();
        if ($methodConfig) {
            $mapping = [
                'adjustable_auto_levels' => 'basic_auto_levels',
                'advanced_tone_mapping' => 'percentile_clipping',
            ];

            if (isset($mapping[$methodConfig->value])) {
                $methodConfig->value = $mapping[$methodConfig->value];
                $methodConfig->save();
            }
        }
    }
};
