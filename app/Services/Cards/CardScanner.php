<?php

namespace App\Services\Cards;

use App\Models\Photo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads what is on a card without changing it: the images (sorted by capture
 * time), everything that will NOT be imported, and how the images fall into
 * classes by the pauses between them.
 *
 * Cameras are synchronised and given distinct filename prefixes before a show,
 * so capture time is the one ordering that holds across bodies.
 */
class CardScanner
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg'];

    private const IGNORED_DIRECTORIES = ['.fseventsd', '.spotlight-v100', '.trashes', '.temporaryitems', 'canonmsc', 'misc'];

    /**
     * @return array{images: array<int, array<string, mixed>>, skipped: array<string, int>, total_bytes: int}
     */
    public function scan(CardVolume $volume): array
    {
        $root = $this->imageRoot($volume->mountPoint);
        $images = [];
        $skipped = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($volume->mountPoint)), '/');

            if (! $file->isFile() || $this->isIgnored($relative)) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                $skipped[$extension === '' ? '(none)' : $extension] = ($skipped[$extension === '' ? '(none)' : $extension] ?? 0) + 1;

                continue;
            }

            $images[] = [
                'path' => $relative,
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'taken_at' => $this->capturedAt($file),
            ];
        }

        usort($images, fn (array $a, array $b) => [$a['taken_at'], $a['name']] <=> [$b['taken_at'], $b['name']]);

        $known = $this->likelyImported($images);
        foreach ($images as &$image) {
            $image['likely_imported'] = isset($known[$image['name'].'|'.$image['size']]);
        }
        unset($image);

        ksort($skipped);

        return ['images' => $images, 'skipped' => $skipped, 'total_bytes' => array_sum(array_column($images, 'size'))];
    }

    /**
     * Split time-sorted images wherever the pause before an image is at least
     * $gapMinutes: classes are separated by pauses in shooting.
     *
     * @param  array<int, array<string, mixed>>  $images  from {@see scan()}
     * @return array<int, array<int, int>> groups of image indexes
     */
    public function groupByPauses(array $images, int $gapMinutes): array
    {
        $groups = [];
        $previous = null;

        foreach ($images as $index => $image) {
            if ($previous === null || max(1, $gapMinutes) * 60 <= $image['taken_at'] - $previous) {
                $groups[] = [];
            }

            $groups[array_key_last($groups)][] = $index;
            $previous = $image['taken_at'];
        }

        return $groups;
    }

    /**
     * Cameras write to /DCIM (the DCF standard). A volume without it is still
     * usable - someone may have copied files to a plain drive - so the whole
     * volume is scanned instead.
     */
    private function imageRoot(string $mountPoint): string
    {
        foreach (scandir($mountPoint) ?: [] as $entry) {
            if (strtoupper($entry) === 'DCIM' && is_dir($mountPoint.'/'.$entry)) {
                return $mountPoint.'/'.$entry;
            }
        }

        return $mountPoint;
    }

    private function isIgnored(string $relativePath): bool
    {
        foreach (explode('/', strtolower($relativePath)) as $segment) {
            // "._name" is macOS's AppleDouble sidecar, not a photo.
            if (in_array($segment, self::IGNORED_DIRECTORIES, true) || str_starts_with($segment, '._')) {
                return true;
            }
        }

        return false;
    }

    /**
     * EXIF capture time; the file's modified time when EXIF is missing.
     * Compared only with other files on the same card, so the timezone the
     * camera was set to does not matter.
     */
    private function capturedAt(SplFileInfo $file): int
    {
        $exif = @exif_read_data($file->getPathname(), 'EXIF', false, false);
        $taken = is_array($exif) ? ($exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null) : null;
        $timestamp = is_string($taken) ? strtotime($taken) : false;

        return $timestamp !== false ? $timestamp : $file->getMTime();
    }

    /**
     * A cheap "seen this before" hint (same camera filename and byte size).
     * The authoritative check is the content hash, computed while copying.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<string, true>
     */
    private function likelyImported(array $images): array
    {
        $known = [];

        foreach (array_chunk(array_unique(array_column($images, 'name')), 400) as $names) {
            Photo::query()
                ->whereIn('original_filename', $names)
                ->with('metadata:photo_id,file_size')
                ->get(['id', 'original_filename'])
                ->each(function (Photo $photo) use (&$known) {
                    $known[$photo->original_filename.'|'.(int) $photo->metadata?->file_size] = true;
                });
        }

        return $known;
    }
}
