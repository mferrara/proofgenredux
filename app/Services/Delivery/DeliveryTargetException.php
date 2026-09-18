<?php

namespace App\Services\Delivery;

use RuntimeException;
use Throwable;

/**
 * Delivery must stop before rsync: Gallery's handshake failed, is not
 * understood, or reports a different destination for a show that already has
 * uploads. Never a reason to fall back to local settings - uploading to a
 * stale destination "succeeds" and the site shows broken images.
 */
class DeliveryTargetException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Transient: Gallery unreachable or reporting no usable upload target.
     */
    public static function unavailable(string $showId, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            'Could not confirm the delivery destination for show '.$showId.' with Gallery: '.$reason,
            true,
            $previous,
        );
    }

    /**
     * Gallery answered, but retrying cannot help (bad slug, bad token, or a
     * contract this Proofgen does not understand).
     */
    public static function unsupported(string $showId, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            'Gallery\'s delivery destination for show '.$showId.' cannot be used: '.$reason,
            false,
            $previous,
        );
    }

    public static function changed(string $showId): self
    {
        return new self(
            'The delivery destination for show '.$showId.' changed after files were already uploaded. '
            .'Review and accept the new destination on the show page before uploading again.',
            false,
        );
    }
}
