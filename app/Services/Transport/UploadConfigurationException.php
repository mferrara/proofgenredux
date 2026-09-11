<?php

namespace App\Services\Transport;

use RuntimeException;

/**
 * Raised when a real upload is requested but its destination is not
 * configured. This is deliberately thrown before any Storage or rsync access
 * so a misconfigured environment fails loudly instead of quietly reporting a
 * successful no-op that lets the downstream metadata jobs run.
 *
 * Pending/dry-run checks do not throw: they may return an empty result (see
 * the models) because "not configured" is indistinguishable from "nothing
 * pending" for a read-only check.
 */
class UploadConfigurationException extends RuntimeException
{
    public static function missingDestination(string $syncType, string $configKey, string $scope): self
    {
        return new self(
            "Cannot upload {$syncType} for {$scope}: the destination path is not configured ({$configKey})."
        );
    }
}
