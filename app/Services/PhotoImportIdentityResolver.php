<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\PhotoMetadata;
use App\Models\Show;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Pure classifier for incoming source files. Hashes the source once and decides
 * whether it can be imported, is an idempotent retry, or constitutes a duplicate
 * or proof-number collision that needs operator review.
 *
 * The resolver does not mutate the filesystem or the database. Callers receive
 * a PhotoImportPlan and decide what to do (import, quarantine, log issue, etc).
 */
class PhotoImportIdentityResolver
{
    /** Filename is raw and unseen — allocate next proof number and import. */
    public const IMPORT_NEW = 'import_new';

    /** Same SHA + same show/class — repair/restore-only path; do not re-import. */
    public const IDEMPOTENT_EXISTING = 'idempotent_existing';

    /** SHA already exists in photos under a different show/class. */
    public const DUPLICATE_CONTENT = 'duplicate_content';

    /** Proof number is taken by a different file (different SHA). */
    public const PROOF_COLLISION = 'proof_collision';

    /** Filename looks like it's trying to claim a proof number but format is wrong. */
    public const INVALID_NUMBERED_FILENAME = 'invalid_numbered_filename';

    /** Catch-all for combinations we don't have a specific policy for. */
    public const NEEDS_REVIEW = 'needs_review';

    public function __construct(private ?PathResolver $pathResolver = null)
    {
        $this->pathResolver ??= app(PathResolver::class);
    }

    public function resolve(string $sourcePath, Show|string $show, string $class): PhotoImportPlan
    {
        $sourcePath = $this->pathResolver->normalizePath($sourcePath);
        $showId = $show instanceof Show ? $show->id : $show;

        if (! Storage::disk('fullsize')->exists($sourcePath)) {
            throw new RuntimeException("Cannot resolve missing source: {$sourcePath}");
        }

        $contents = Storage::disk('fullsize')->get($sourcePath);
        $sha1 = sha1($contents);
        $size = strlen($contents);
        $mtime = Storage::disk('fullsize')->lastModified($sourcePath);

        $basename = basename($sourcePath);
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        // EXIF read needs an absolute path. We deliberately read from the live source
        // before any move; the resolver still doesn't mutate anything.
        $absolutePath = rtrim((string) config('proofgen.fullsize_home_dir'), '/').'/'.ltrim($sourcePath, '/');
        $captureFingerprint = PhotoMetadata::fingerprintFromFile($absolutePath);

        $embeddedProofNumber = $this->extractEmbeddedProofNumber($basename, $showId);
        $filenameIsNumbered = $embeddedProofNumber !== null;

        $existingByContent = Photo::query()->where('sha1', $sha1)->first();

        $intendedProofNumber = $embeddedProofNumber;
        $existingByProofNumber = null;
        if ($intendedProofNumber !== null) {
            $existingByProofNumber = Photo::query()
                ->where('show_class_id', $showId.'_'.$class)
                ->where('proof_number', $intendedProofNumber)
                ->first();

            if ($existingByProofNumber === null) {
                // Same proof number used in another class within the same show is also a collision
                // signal because proof numbers are show-scoped (Redis pool is keyed on show id).
                $existingByProofNumber = Photo::query()
                    ->where('proof_number', $intendedProofNumber)
                    ->first();
            }
        }

        [$decision, $allocatesNewProofNumber, $evidence] = $this->classify(
            filenameIsNumbered: $filenameIsNumbered,
            showId: $showId,
            class: $class,
            existingByContent: $existingByContent,
            existingByProofNumber: $existingByProofNumber,
        );

        return new PhotoImportPlan(
            decision: $decision,
            sourcePath: $sourcePath,
            originalFilename: $basename,
            extension: $extension,
            sha1: $sha1,
            size: $size,
            sourceMtime: $mtime,
            filenameIsNumberedForShow: $filenameIsNumbered,
            intendedProofNumber: $intendedProofNumber,
            allocatesNewProofNumber: $allocatesNewProofNumber,
            existingByContent: $existingByContent,
            existingByProofNumber: $existingByProofNumber,
            captureFingerprint: $captureFingerprint,
            evidence: $evidence,
        );
    }

    /**
     * Returns the proof number embedded in a basename if it strictly matches the show's
     * `{SHOW_UPPERCASE}_{5-digit}.{ext}` pattern. Otherwise returns null.
     *
     * `IMG_02631.jpg` is raw even though it contains digits.
     * `22BUCK_00093.jpg` for show `22Buck` is recognized as proof number `22BUCK_00093`.
     */
    public function extractEmbeddedProofNumber(string $basename, string $showId): ?string
    {
        $stem = pathinfo($basename, PATHINFO_FILENAME);
        $prefix = strtoupper($showId).'_';

        if (! str_starts_with(strtoupper($stem), $prefix)) {
            return null;
        }

        $suffix = substr($stem, strlen($prefix));
        if (! preg_match('/^\d{5}$/', $suffix)) {
            return null;
        }

        return strtoupper($showId).'_'.$suffix;
    }

    private function classify(
        bool $filenameIsNumbered,
        string $showId,
        string $class,
        ?Photo $existingByContent,
        ?Photo $existingByProofNumber,
    ): array {
        $showClassId = $showId.'_'.$class;
        $evidence = [
            'show_id' => $showId,
            'class' => $class,
            'show_class_id' => $showClassId,
            'sha1_match_photo_id' => $existingByContent?->id,
            'sha1_match_proof_number' => $existingByContent?->proof_number,
            'sha1_match_show_class_id' => $existingByContent?->show_class_id,
            'proof_number_match_photo_id' => $existingByProofNumber?->id,
            'proof_number_match_sha1' => $existingByProofNumber?->sha1,
        ];

        // Exact same bytes already represented in this show/class: reuse the
        // existing record. This covers both raw re-imports and "same content
        // under a different proof number" retries. Identity and proof number
        // are preserved and no new proof number is allocated.
        if ($existingByContent !== null && $existingByContent->show_class_id === $showClassId) {
            return [self::IDEMPOTENT_EXISTING, false, $evidence];
        }

        // Exact same bytes owned by a different show/class: surface a visible
        // duplicate linked to the existing photo. Never create a second row.
        if ($existingByContent !== null) {
            return [self::DUPLICATE_CONTENT, false, $evidence];
        }

        // Different bytes claim a proof number that is already taken.
        if ($filenameIsNumbered && $existingByProofNumber !== null) {
            return [self::PROOF_COLLISION, false, $evidence];
        }

        // Genuinely new content. Raw camera names allocate the next proof
        // number; already-numbered names reuse their embedded proof number.
        return [self::IMPORT_NEW, ! $filenameIsNumbered, $evidence];
    }
}
