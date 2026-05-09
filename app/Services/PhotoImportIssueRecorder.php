<?php

namespace App\Services;

use App\Models\PhotoIssue;

/**
 * Records a photo_issues row and quarantines the incoming source for an unresolved
 * import. Called by PhotoService when the resolver flags DUPLICATE_CONTENT,
 * PROOF_COLLISION, or NEEDS_REVIEW.
 */
class PhotoImportIssueRecorder
{
    private const DECISION_TO_TYPE = [
        PhotoImportIdentityResolver::DUPLICATE_CONTENT => PhotoIssue::TYPE_DUPLICATE_CONTENT,
        PhotoImportIdentityResolver::PROOF_COLLISION => PhotoIssue::TYPE_PROOF_COLLISION,
        PhotoImportIdentityResolver::INVALID_NUMBERED_FILENAME => PhotoIssue::TYPE_INVALID_NUMBERED_FILENAME,
        PhotoImportIdentityResolver::NEEDS_REVIEW => PhotoIssue::TYPE_NEEDS_REVIEW,
    ];

    public function __construct(private ?SafeFileMover $safeFileMover = null)
    {
        $this->safeFileMover ??= app(SafeFileMover::class);
    }

    public function record(PhotoImportPlan $plan, string $show, string $class): PhotoIssue
    {
        $issueType = self::DECISION_TO_TYPE[$plan->decision] ?? PhotoIssue::TYPE_NEEDS_REVIEW;

        $quarantineResult = $this->safeFileMover->quarantineImport(
            disk: 'fullsize',
            sourcePath: $plan->sourcePath,
            show: $show,
            class: $class,
            context: [
                'sha1' => $plan->sha1,
                'size' => $plan->size,
                'decision' => $plan->decision,
                'original_filename' => $plan->originalFilename,
                'intended_proof_number' => $plan->intendedProofNumber,
                'existing_photo_id' => $plan->existingByContent?->id ?? $plan->existingByProofNumber?->id,
            ],
        );

        return PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => $issueType,
            'show_id' => $show,
            'show_class_id' => $show.'_'.$class,
            'source_path' => $plan->sourcePath,
            'quarantine_path' => $quarantineResult['quarantine_path'],
            'intended_proof_number' => $plan->intendedProofNumber,
            'incoming_sha1' => $plan->sha1,
            'incoming_size' => $plan->size,
            'existing_photo_id' => $plan->existingByContent?->id ?? $plan->existingByProofNumber?->id,
            'existing_proof_number' => $plan->existingByContent?->proof_number ?? $plan->existingByProofNumber?->proof_number,
            'existing_sha1' => $plan->existingByContent?->sha1 ?? $plan->existingByProofNumber?->sha1,
            'evidence' => $plan->evidence + [
                'quarantine_sidecar' => $quarantineResult['sidecar_path'],
                'original_filename' => $plan->originalFilename,
                'source_mtime' => $plan->sourceMtime,
                'capture_fingerprint' => $plan->captureFingerprint,
            ],
        ]);
    }
}
