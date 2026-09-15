<?php

namespace App\Services;

use App\Models\Configuration;

class AutomaticUploadSettings
{
    public function enabled(): bool
    {
        // Horizon retains boot-time config. Read this operator switch fresh so
        // saving it takes effect without restarting workers.
        $setting = Configuration::where('key', 'upload_proofs')->first();

        return $setting
            ? (bool) Configuration::castValue($setting->value, $setting->type)
            : (bool) config('proofgen.upload_proofs', false);
    }
}
