<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\PhotoMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Produces non-binding placement hints for DUPLICATE_CONTENT plans.
 *
 * Given a duplicate-by-content import plan, this service inspects both the
 * incoming source (filename ordinal + EXIF capture time) and the candidate
 * classes (the existing photo's class plus the incoming target class), and
 * reports how well the incoming photo "fits" each class by ordinal and by
 * capture time. The output is purely informational: no files are moved and
 * no records are written.
 */
class ImportConflictHintService
{
    public function hintsFor(PhotoImportPlan $plan): array
    {
        $incomingOrdinal = $this->extractOrdinal($plan->originalFilename);
        $incomingCaptureTime = $this->readIncomingCaptureTime($plan->sourcePath);

        $candidateClassIds = $this->candidateClassIds($plan);

        $candidates = [];
        foreach ($candidateClassIds as $classId) {
            $candidates[$classId] = $this->buildCandidate(
                showClassId: $classId,
                incomingOrdinal: $incomingOrdinal,
                incomingCaptureTime: $incomingCaptureTime,
            );
        }

        return [
            'incoming' => [
                'original_filename' => $plan->originalFilename,
                'ordinal' => $incomingOrdinal,
                'capture_time' => $incomingCaptureTime?->toIso8601String(),
            ],
            'candidates' => $candidates,
        ];
    }

    /**
     * @return string[]
     */
    private function candidateClassIds(PhotoImportPlan $plan): array
    {
        $ids = [];

        // Target class is implied by the source path layout: {show}/{class}/{filename}
        $segments = explode('/', $plan->sourcePath);
        if (count($segments) >= 3) {
            $ids[] = $segments[0].'_'.$segments[1];
        }

        if ($plan->existingByContent !== null) {
            $ids[] = $plan->existingByContent->show_class_id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function buildCandidate(
        string $showClassId,
        ?int $incomingOrdinal,
        ?CarbonImmutable $incomingCaptureTime,
    ): array {
        $siblings = Photo::query()
            ->with('metadata')
            ->where('show_class_id', $showClassId)
            ->get();

        $notes = [];

        $ordinalFit = $this->computeOrdinalFit($siblings, $incomingOrdinal, $notes);
        $timeFit = $this->computeTimeFit($siblings, $incomingCaptureTime, $notes);

        return [
            'show_class_id' => $showClassId,
            'photo_count' => $siblings->count(),
            'ordinal_fit' => $ordinalFit,
            'time_fit' => $timeFit,
            'notes' => $notes,
        ];
    }

    private function computeOrdinalFit(Collection $siblings, ?int $incomingOrdinal, array &$notes): ?array
    {
        if ($incomingOrdinal === null) {
            $notes[] = 'incoming filename has no numeric ordinal';

            return null;
        }

        $entries = [];
        $missingCount = 0;
        foreach ($siblings as $photo) {
            $ordinal = $this->extractOrdinal((string) $photo->original_filename);
            if ($ordinal === null) {
                if ($photo->original_filename === null || $photo->original_filename === '') {
                    $missingCount++;
                }

                continue;
            }
            $entries[] = [
                'ordinal' => $ordinal,
                'original_filename' => $photo->original_filename,
                'proof_number' => $photo->proof_number,
            ];
        }

        if ($missingCount > 0) {
            $notes[] = "{$missingCount} sibling photo(s) missing original_filename; ordinal fit ignores them";
        }

        if ($entries === []) {
            $notes[] = 'no siblings with parseable ordinals; cannot evaluate ordinal fit';

            return null;
        }

        usort($entries, fn ($a, $b) => $a['ordinal'] <=> $b['ordinal']);

        $min = $entries[0]['ordinal'];
        $max = $entries[count($entries) - 1]['ordinal'];

        $nearestBefore = null;
        $nearestAfter = null;
        foreach ($entries as $entry) {
            if ($entry['ordinal'] <= $incomingOrdinal) {
                if ($nearestBefore === null || $entry['ordinal'] > $nearestBefore['ordinal']) {
                    $nearestBefore = $entry;
                }
            }
            if ($entry['ordinal'] >= $incomingOrdinal) {
                if ($nearestAfter === null || $entry['ordinal'] < $nearestAfter['ordinal']) {
                    $nearestAfter = $entry;
                }
            }
        }

        return [
            'within_range' => $incomingOrdinal >= $min && $incomingOrdinal <= $max,
            'min' => $min,
            'max' => $max,
            'nearest_before' => $nearestBefore,
            'nearest_after' => $nearestAfter,
        ];
    }

    private function computeTimeFit(Collection $siblings, ?CarbonImmutable $incomingCaptureTime, array &$notes): ?array
    {
        if ($incomingCaptureTime === null) {
            $notes[] = 'incoming source has no EXIF capture time';

            return null;
        }

        $entries = [];
        foreach ($siblings as $photo) {
            /** @var PhotoMetadata|null $metadata */
            $metadata = $photo->metadata;
            if ($metadata === null || $metadata->exif_timestamp === null) {
                continue;
            }
            $entries[] = [
                'capture_time' => CarbonImmutable::instance($metadata->exif_timestamp),
                'proof_number' => $photo->proof_number,
                'original_filename' => $photo->original_filename,
            ];
        }

        if ($entries === []) {
            $notes[] = 'no siblings with EXIF capture times; cannot evaluate time fit';

            return null;
        }

        usort($entries, fn ($a, $b) => $a['capture_time']->getTimestamp() <=> $b['capture_time']->getTimestamp());

        $minTs = $entries[0]['capture_time']->getTimestamp();
        $maxTs = $entries[count($entries) - 1]['capture_time']->getTimestamp();
        $incomingTs = $incomingCaptureTime->getTimestamp();

        $nearestBefore = null;
        $nearestAfter = null;
        foreach ($entries as $entry) {
            $ts = $entry['capture_time']->getTimestamp();
            if ($ts <= $incomingTs) {
                if ($nearestBefore === null || $ts > $nearestBefore['capture_time']->getTimestamp()) {
                    $nearestBefore = $entry;
                }
            }
            if ($ts >= $incomingTs) {
                if ($nearestAfter === null || $ts < $nearestAfter['capture_time']->getTimestamp()) {
                    $nearestAfter = $entry;
                }
            }
        }

        return [
            'within_range' => $incomingTs >= $minTs && $incomingTs <= $maxTs,
            'nearest_before_seconds' => $nearestBefore !== null
                ? $incomingTs - $nearestBefore['capture_time']->getTimestamp()
                : null,
            'nearest_after_seconds' => $nearestAfter !== null
                ? $nearestAfter['capture_time']->getTimestamp() - $incomingTs
                : null,
            'nearest_before' => $nearestBefore !== null ? $this->formatTimeEntry($nearestBefore) : null,
            'nearest_after' => $nearestAfter !== null ? $this->formatTimeEntry($nearestAfter) : null,
        ];
    }

    private function formatTimeEntry(array $entry): array
    {
        return [
            'capture_time' => $entry['capture_time']->toIso8601String(),
            'proof_number' => $entry['proof_number'],
            'original_filename' => $entry['original_filename'],
        ];
    }

    private function extractOrdinal(?string $filename): ?int
    {
        if ($filename === null || $filename === '') {
            return null;
        }
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        // Camera filenames put the sequence counter at the tail (IMG_02631, DSC00123).
        if (! preg_match('/(\d+)(?!.*\d)/', $stem, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    private function readIncomingCaptureTime(string $sourcePath): ?CarbonImmutable
    {
        $absolutePath = config('proofgen.fullsize_home_dir').'/'.$sourcePath;
        if (! is_file($absolutePath)) {
            return null;
        }

        $exif = @exif_read_data($absolutePath, 'EXIF', true);
        if ($exif === false) {
            return null;
        }

        $raw = $exif['EXIF']['DateTimeOriginal']
            ?? $exif['IFD0']['DateTime']
            ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            // EXIF DateTimeOriginal is "Y:m:d H:i:s"
            return CarbonImmutable::createFromFormat('Y:m:d H:i:s', $raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
