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
        // Add Core Image idle timeout configuration
        Configuration::setConfig(
            'core_image_idle_timeout',
            '120',
            'integer',
            'system',
            'Core Image Idle Timeout',
            'Minutes before Core Image daemon shuts down due to inactivity (0 to disable)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Configuration::where('key', 'core_image_idle_timeout')->delete();
    }
};
