<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;

/**
 * A 2025 settings migration copied `getenv('SFTP_HIGHRES_IMAGES_PATH')` into a
 * saved setting. `getenv()` returns false for an unset variable, and false was
 * stored as "0" - a "path" that the local upload fallback would have turned
 * into a folder literally named 0. Clear such values; an empty path means "not
 * configured", which is what was meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Configuration::query()
            ->whereIn('key', ['sftp.path', 'sftp.web_images_path', 'sftp.highres_images_path'])
            ->whereIn('value', ['0', 'false'])
            ->get()
            // Through the model so the settings cache is cleared.
            ->each(fn (Configuration $setting) => $setting->update(['value' => '']));
    }

    public function down(): void
    {
        // Nothing to restore: the old value was never a path.
    }
};
