<?php

namespace App\Services\Storage;

use App\Models\Show;
use App\Models\StorageProfile;

class ShowProfileBinder
{
    public function pin(Show $show): StorageProfile
    {
        if ($show->storage_profile_id) {
            return $show->storageProfile()->firstOrFail();
        }

        $profile = StorageProfile::query()
            ->where('is_active', true)
            ->where('is_writable', true)
            ->firstOrFail();

        $show->storage_profile_id = $profile->id;
        $show->save();

        return $profile;
    }
}
