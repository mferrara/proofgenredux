<?php

namespace App\Services;

use App\Models\Photo;

/**
 * Result of PhotoImportIdentityResolver::resolve(). Pure data, no behavior.
 *
 * Decision values are documented as constants on PhotoImportIdentityResolver.
 */
class PhotoImportPlan
{
    public function __construct(
        public readonly string $decision,
        public readonly string $sourcePath,
        public readonly string $originalFilename,
        public readonly string $extension,
        public readonly string $sha1,
        public readonly int $size,
        public readonly bool $filenameIsNumberedForShow,
        public readonly ?string $intendedProofNumber,
        public readonly bool $allocatesNewProofNumber,
        public readonly ?Photo $existingByContent,
        public readonly ?Photo $existingByProofNumber,
        public readonly array $evidence = [],
    ) {}

    public function shouldImport(): bool
    {
        return $this->decision === PhotoImportIdentityResolver::IMPORT_NEW;
    }

    public function isIdempotent(): bool
    {
        return $this->decision === PhotoImportIdentityResolver::IDEMPOTENT_EXISTING;
    }

    public function requiresReview(): bool
    {
        return in_array($this->decision, [
            PhotoImportIdentityResolver::DUPLICATE_CONTENT,
            PhotoImportIdentityResolver::PROOF_COLLISION,
            PhotoImportIdentityResolver::NEEDS_REVIEW,
        ], true);
    }
}
