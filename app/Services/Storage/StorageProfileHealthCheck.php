<?php

namespace App\Services\Storage;

use App\Models\StorageProfile;
use Illuminate\Support\Facades\Storage as StorageFacade;
use JsonException;
use Throwable;

class StorageProfileHealthCheck
{
    public function __construct(
        private readonly StorageProfileResolver $resolver,
        private readonly ProfileAutoDetector $detector,
    ) {}

    /**
     * @return array{status: 'ok'|'missing_credentials'|'unreachable'|'drift', message: ?string}
     */
    public function check(StorageProfile $profile): array
    {
        if ($profile->isLegacyLocal()) {
            return $this->checkLegacyLocal($profile);
        }

        if ($profile->driver === 's3' && ($this->missingEnv($profile, 'KEY') || $this->missingEnv($profile, 'SECRET'))) {
            return [
                'status' => 'missing_credentials',
                'message' => 'Missing '.$profile->envPrefix().'_KEY / _SECRET in .env',
            ];
        }

        if ($profile->driver === 'local' && blank($profile->root)) {
            return [
                'status' => 'missing_credentials',
                'message' => 'Missing root path for local profile '.$profile->id,
            ];
        }

        try {
            if ($this->detector->hasIdentityConfigForProfileId($profile->id)) {
                $currentFingerprint = $this->detector->fingerprint(
                    $this->detector->configForProfileId($profile->id),
                );

                if ($currentFingerprint !== $profile->fingerprint) {
                    return [
                        'status' => 'drift',
                        'message' => 'Storage profile '.$profile->id.' no longer matches .env identity settings',
                    ];
                }
            }
        } catch (JsonException $exception) {
            return [
                'status' => 'drift',
                'message' => $exception->getMessage(),
            ];
        }

        try {
            StorageFacade::disk($this->resolver->diskFor($profile))->files('/', false);

            return ['status' => 'ok', 'message' => null];
        } catch (Throwable $exception) {
            return [
                'status' => 'unreachable',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function checkLegacyLocal(StorageProfile $profile): array
    {
        foreach ([
            StorageProfileResolver::CONTENT_PROOFS,
            StorageProfileResolver::CONTENT_WEB_IMAGES,
            StorageProfileResolver::CONTENT_HIGH_RES_IMAGES,
        ] as $contentType) {
            try {
                StorageFacade::disk($this->resolver->diskFor($profile, $contentType))->files('/', false);
            } catch (Throwable $exception) {
                return [
                    'status' => 'unreachable',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return ['status' => 'ok', 'message' => null];
    }

    private function missingEnv(StorageProfile $profile, string $suffix): bool
    {
        $key = $profile->envPrefix().'_'.$suffix;

        return blank(env($key) ?? $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key));
    }
}
