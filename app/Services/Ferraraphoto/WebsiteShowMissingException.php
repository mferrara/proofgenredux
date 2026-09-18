<?php

namespace App\Services\Ferraraphoto;

use RuntimeException;
use Throwable;

/**
 * The show has not been created on the website. Shows are created there, not
 * by Proofgen, so retrying cannot help: the operator has to create it first.
 */
class WebsiteShowMissingException extends RuntimeException
{
    public static function forSlug(string $slug, ?Throwable $previous = null): self
    {
        return new self(
            'Show "'.$slug.'" does not exist on the website yet. Create it on the website first (Admin → Shows → New show), then upload again.',
            0,
            $previous,
        );
    }
}
