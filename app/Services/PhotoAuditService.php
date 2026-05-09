<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\PhotoIssue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Aggregates audit checks across the photos table and the on-disk image trees.
 * Persists findings as PhotoIssue rows so the same inbox surfaces both
 * import-time conflicts and audit-time discoveries.
 *
 * Repair behavior is intentionally conservative — only safe, unambiguous fixes
 * happen automatically. Anything ambiguous (duplicate SHAs, duplicate proof
 * numbers, orphan originals) is reported only.
 */
class PhotoAuditService
{
    public function __construct(
        private ?PhotoArchiveService $archiveService = null,
        private ?PathResolver $pathResolver = null,
    ) {
        $this->archiveService ??= app(PhotoArchiveService::class);
        $this->pathResolver ??= app(PathResolver::class);
    }

    /**
     * Run all audits, optionally scoped to a show or class. Returns aggregated stats
     * plus a list of findings. If $repair is true, applies safe repairs.
     */
    public function auditAll(?string $showFilter = null, ?string $classFilter = null, bool $repair = false): array
    {
        $stats = [
            'photos_total' => 0,
            'photos_ok' => 0,
            'photos_repaired' => 0,
            'photos_needs_attention' => 0,
            'duplicate_sha1_groups' => 0,
            'duplicate_proof_number_groups' => 0,
            'orphan_originals' => 0,
            'photos_without_original_and_archive' => 0,
        ];
        $findings = [];

        $query = Photo::query()->with('showClass')->orderBy('show_class_id')->orderBy('proof_number');
        if ($showFilter !== null) {
            $query->where('show_class_id', 'like', $showFilter.'_%');
        }
        if ($classFilter !== null) {
            $query->where('show_class_id', $showFilter !== null ? $showFilter.'_'.$classFilter : 'like:%_'.$classFilter);
        }

        $query->chunk(200, function ($photos) use (&$stats, &$findings, $repair) {
            foreach ($photos as $photo) {
                $finding = $this->auditOnePhoto($photo, $repair);
                $stats['photos_total']++;
                if ($finding['repaired']) {
                    $stats['photos_repaired']++;
                }
                if ($finding['status'] === 'ok') {
                    $stats['photos_ok']++;
                } else {
                    $stats['photos_needs_attention']++;
                    $this->recordOrUpdateIssue($finding);
                }
                $findings[] = $finding;
            }
        });

        foreach ($this->findDuplicateSha1Groups() as $group) {
            $stats['duplicate_sha1_groups']++;
            $findings[] = ['kind' => 'duplicate_sha1_group'] + $group;
        }

        foreach ($this->findDuplicateProofNumberGroups() as $group) {
            $stats['duplicate_proof_number_groups']++;
            $findings[] = ['kind' => 'duplicate_proof_number_group'] + $group;
        }

        foreach ($this->findOrphanOriginals($showFilter, $classFilter) as $orphan) {
            $stats['orphan_originals']++;
            $findings[] = ['kind' => 'orphan_original'] + $orphan;
        }

        foreach ($this->findPhotosWithoutOriginalOrArchive($showFilter, $classFilter) as $missing) {
            $stats['photos_without_original_and_archive']++;
            $findings[] = ['kind' => 'photo_without_original_and_archive'] + $missing;
        }

        return ['stats' => $stats, 'findings' => $findings];
    }

    public function auditOnePhoto(Photo $photo, bool $repair): array
    {
        $archiveAudit = $this->archiveService->auditPhoto($photo);
        $sourceExists = $archiveAudit['source_exists'];
        $sourceSha1 = $archiveAudit['source_sha1'];
        $repaired = false;
        $repairNote = null;

        $shaProblem = null;
        if ($sourceExists && ! empty($photo->sha1) && $sourceSha1 !== null && $sourceSha1 !== $photo->sha1) {
            $shaProblem = 'photos_sha1_mismatch_with_original';
        }
        if ($sourceExists && empty($photo->sha1) && $sourceSha1 !== null) {
            if ($repair) {
                $photo->forceFill(['sha1' => $sourceSha1])->save();
                $repaired = true;
                $repairNote = 'Backfilled photos.sha1 from local original.';
            } else {
                $shaProblem = 'photos_sha1_missing';
            }
        }

        $repairableArchive = in_array($archiveAudit['status'], ['archive_missing', 'metadata_stale'], true);
        if ($repair && $repairableArchive) {
            $repairResult = $this->archiveService->repairPhoto($photo);
            $repaired = $repaired || ($repairResult['repaired'] ?? false);
            $repairNote = $repairResult['repair_note'] ?? $repairNote;
            $archiveAudit = $this->archiveService->auditPhoto($photo->fresh());
        }

        $effectiveStatus = $shaProblem ?? $archiveAudit['status'];

        return [
            'kind' => 'photo',
            'photo_id' => $photo->id,
            'show_class_id' => $photo->show_class_id,
            'proof_number' => $photo->proof_number,
            'status' => $effectiveStatus,
            'source_exists' => $sourceExists,
            'archive_exists' => $archiveAudit['archive_exists'],
            'archive_path' => $archiveAudit['archive_path'],
            'source_sha1' => $sourceSha1,
            'photos_sha1' => $photo->sha1,
            'archive_sha1' => $archiveAudit['archive_sha1'],
            'repaired' => $repaired,
            'repair_note' => $repairNote,
        ];
    }

    /**
     * Find groups of Photo rows sharing the same sha1. Skips empty/null sha1.
     */
    public function findDuplicateSha1Groups(): array
    {
        $rows = DB::table('photos')
            ->select('sha1', DB::raw('COUNT(*) as count'))
            ->whereNotNull('sha1')
            ->where('sha1', '!=', '')
            ->groupBy('sha1')
            ->having('count', '>', 1)
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $photos = Photo::where('sha1', $row->sha1)->get(['id', 'show_class_id', 'proof_number']);
            $groups[] = [
                'sha1' => $row->sha1,
                'count' => $row->count,
                'photos' => $photos->toArray(),
            ];
        }

        return $groups;
    }

    /**
     * Find proof numbers used by more than one photo within the same show. Proof
     * numbers are show-scoped (Redis key is per-show) so within-show duplicates
     * are the meaningful collision; cross-show same numbers are coincidental.
     */
    public function findDuplicateProofNumberGroups(): array
    {
        $rows = DB::table('photos')
            ->select('proof_number', DB::raw('COUNT(*) as count'))
            ->groupBy('proof_number')
            ->having('count', '>', 1)
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $photos = Photo::where('proof_number', $row->proof_number)->get(['id', 'show_class_id', 'proof_number', 'sha1']);
            $groups[] = [
                'proof_number' => $row->proof_number,
                'count' => $row->count,
                'photos' => $photos->toArray(),
            ];
        }

        return $groups;
    }

    /**
     * Files in any class's originals/ folder without a corresponding Photo row.
     */
    public function findOrphanOriginals(?string $showFilter = null, ?string $classFilter = null): array
    {
        $orphans = [];
        $allFiles = Storage::disk('fullsize')->allFiles();

        foreach ($allFiles as $file) {
            if (! str_contains($file, '/originals/')) {
                continue;
            }
            // path shape: {show}/{class}/originals/{filename}
            $parts = explode('/', $file);
            if (count($parts) < 4 || $parts[2] !== 'originals') {
                continue;
            }
            [$show, $class] = [$parts[0], $parts[1]];
            if ($showFilter !== null && $show !== $showFilter) {
                continue;
            }
            if ($classFilter !== null && $class !== $classFilter) {
                continue;
            }

            $proofNumber = pathinfo($parts[3], PATHINFO_FILENAME);
            $photoId = $show.'_'.$class.'_'.$proofNumber;
            if (Photo::where('id', $photoId)->exists()) {
                continue;
            }
            $orphans[] = [
                'path' => $file,
                'show' => $show,
                'class' => $class,
                'proof_number' => $proofNumber,
            ];
        }

        return $orphans;
    }

    /**
     * Photos with no local original AND no archive copy — high-risk: data exists only
     * in the DB row.
     */
    public function findPhotosWithoutOriginalOrArchive(?string $showFilter = null, ?string $classFilter = null): array
    {
        $query = Photo::query();
        if ($showFilter !== null) {
            $query->where('show_class_id', 'like', $showFilter.'_%');
        }
        if ($classFilter !== null && $showFilter !== null) {
            $query->where('show_class_id', $showFilter.'_'.$classFilter);
        }

        $missing = [];
        foreach ($query->cursor() as $photo) {
            $sourceExists = is_file($photo->full_path);
            $archiveExists = ! empty($photo->archive_path)
                && Storage::disk('archive')->exists($photo->archive_path);
            if (! $sourceExists && ! $archiveExists) {
                $missing[] = [
                    'photo_id' => $photo->id,
                    'show_class_id' => $photo->show_class_id,
                    'proof_number' => $photo->proof_number,
                ];
            }
        }

        return $missing;
    }

    /**
     * Upsert a PhotoIssue row for a non-OK finding. If an open issue with the same
     * (issue_type, photo_id) already exists, refresh evidence; otherwise create.
     */
    public function recordOrUpdateIssue(array $finding): ?PhotoIssue
    {
        $statusToType = [
            'archive_missing' => PhotoIssue::TYPE_MISSING_ARCHIVE,
            'metadata_stale' => PhotoIssue::TYPE_METADATA_MISMATCH,
            'archive_mismatched' => PhotoIssue::TYPE_ARCHIVE_CONFLICT,
            'source_missing_archive_available' => PhotoIssue::TYPE_MISSING_ORIGINAL,
            'source_missing_archive_missing' => PhotoIssue::TYPE_MISSING_ORIGINAL,
            'photos_sha1_missing' => PhotoIssue::TYPE_METADATA_MISMATCH,
            'photos_sha1_mismatch_with_original' => PhotoIssue::TYPE_METADATA_MISMATCH,
        ];

        $issueType = $statusToType[$finding['status']] ?? null;
        if ($issueType === null) {
            return null;
        }

        $existing = PhotoIssue::where('issue_type', $issueType)
            ->where('existing_photo_id', $finding['photo_id'])
            ->where('status', PhotoIssue::STATUS_OPEN)
            ->first();

        $payload = [
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => $issueType,
            'show_class_id' => $finding['show_class_id'],
            'show_id' => explode('_', $finding['show_class_id'], 2)[0] ?? null,
            'existing_photo_id' => $finding['photo_id'],
            'existing_proof_number' => $finding['proof_number'],
            'existing_sha1' => $finding['photos_sha1'],
            'evidence' => [
                'audit_status' => $finding['status'],
                'source_sha1' => $finding['source_sha1'],
                'archive_sha1' => $finding['archive_sha1'],
                'archive_path' => $finding['archive_path'],
                'source_exists' => $finding['source_exists'],
                'archive_exists' => $finding['archive_exists'],
            ],
        ];

        if ($existing !== null) {
            $existing->update($payload);

            return $existing;
        }

        return PhotoIssue::create($payload);
    }
}
