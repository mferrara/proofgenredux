<?php

namespace App\Services\Storage;

use App\Models\StorageProfile;
use InvalidArgumentException;

class StorageProfileResolver
{
    public const CONTENT_PROOFS = 'proofs';

    public const CONTENT_WEB_IMAGES = 'web_images';

    public const CONTENT_HIGH_RES_IMAGES = 'high_res_images';

    public function diskFor(StorageProfile $profile, ?string $contentType = null): string
    {
        if ($profile->isLegacyLocal()) {
            return $this->legacyDiskFor($contentType);
        }

        $diskName = 'profile_'.$profile->id;

        if (! config()->has("filesystems.disks.{$diskName}")) {
            config(["filesystems.disks.{$diskName}" => $this->buildDiskConfig($profile)]);
        }

        return $diskName;
    }

    public function buildDiskConfig(StorageProfile $profile): array
    {
        if ($profile->driver === 'local') {
            if (blank($profile->root)) {
                throw new InvalidArgumentException('Local storage profile '.$profile->id.' is missing a root path.');
            }

            return [
                'driver' => 'local',
                'root' => $profile->root,
                'throw' => false,
            ];
        }

        if ($profile->driver !== 's3') {
            throw new InvalidArgumentException('Unsupported storage profile driver: '.$profile->driver);
        }

        $envPrefix = $profile->envPrefix();

        return [
            'driver' => 's3',
            'key' => env("{$envPrefix}_KEY"),
            'secret' => env("{$envPrefix}_SECRET"),
            'region' => $profile->region,
            'bucket' => $profile->bucket,
            'endpoint' => $profile->endpoint,
            'use_path_style_endpoint' => $profile->use_path_style,
            'throw' => false,
        ];
    }

    private function legacyDiskFor(?string $contentType): string
    {
        return match ($contentType) {
            self::CONTENT_PROOFS => 'remote_proofs',
            self::CONTENT_WEB_IMAGES => 'remote_web_images',
            self::CONTENT_HIGH_RES_IMAGES => 'remote_highres_images',
            default => throw new InvalidArgumentException('legacy-local requires a content type.'),
        };
    }
}
