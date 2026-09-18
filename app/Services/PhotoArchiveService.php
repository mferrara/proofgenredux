<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\ShowClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PhotoArchiveService
{
    private const CONFLICT_DIRECTORY = '_conflicts';

    public function __construct(private ?PathResolver $pathResolver = null)
    {
        $this->pathResolver ??= app(PathResolver::class);
    }

    public function enabled(): bool
    {
        return (bool) config('proofgen.archive_enabled');
    }

    public function configured(): bool
    {
        $root = config('proofgen.archive_home_dir');

        return is_string($root) && trim($root) !== '';
    }

    public function assertConfiguredRootAvailable(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Archive root is not configured; set archive_home_dir before using archive backups.');
        }

        $root = $this->archiveRoot();
        if ($root === null || trim($root) === '') {
            throw new RuntimeException('Archive filesystem root is not configured.');
        }

        if (! is_dir($root)) {
            throw new RuntimeException('Archive root does not exist: '.$root);
        }

        if (! is_writable($root)) {
            throw new RuntimeException('Archive root is not writable: '.$root);
        }
    }

    public function pathFor(string $show, string $class, string $filename): string
    {
        return $this->pathResolver->normalizePath(
            $this->pathResolver->getArchivePath($show, $class).'/'.$filename
        );
    }

    /**
     * Move the Card Reader's archive copy of this content to $archivePath.
     * False (and nothing changed) when there is none, or it cannot be moved:
     * the caller then writes the copy itself, as before.
     */
    private function claimCardCopy(string $sha1, int $size, string $archivePath): bool
    {
        try {
            $copies = DB::table('card_files')->where('sha1', $sha1)->whereNull('claimed_at')->orderBy('id')->get();

            foreach ($copies as $copy) {
                $disk = Storage::disk('archive');

                if (! $disk->exists($copy->archive_path) || $disk->size($copy->archive_path) !== $size) {
                    continue;
                }

                $disk->move($copy->archive_path, $archivePath);
                DB::table('card_files')->where('id', $copy->id)->update(['claimed_at' => now(), 'claimed_path' => $archivePath]);

                return true;
            }
        } catch (\Throwable $exception) {
            Log::warning('Could not reuse the card copy on the archive drive; writing a new one.', [
                'archive_path' => $archivePath,
                'reason' => $exception->getMessage(),
            ]);
        }

        return false;
    }

    public function pathForPhoto(Photo $photo, ?ShowClass $targetClass = null): string
    {
        [$show, $class] = $targetClass
            ? [$targetClass->show_id, $targetClass->name]
            : $this->photoShowClassParts($photo);

        return $this->pathFor($show, $class, $this->filenameForPhoto($photo));
    }

    public function assertReadyForPath(string $archivePath): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->assertConfiguredRootAvailable();

        $directory = $this->directoryFor($archivePath);
        // Several import workers reach a brand-new class at the same moment.
        SafeDirectory::ensure(Storage::disk('archive'), $directory);

        $probePath = trim($directory.'/.proofgen-archive-write-test-'.uniqid('', true), '/');
        Storage::disk('archive')->put($probePath, 'ok');

        if (Storage::disk('archive')->get($probePath) !== 'ok') {
            throw new RuntimeException('Archive write probe failed for '.$directory);
        }

        Storage::disk('archive')->delete($probePath);
    }

    public function storeContents(string $archivePath, string $contents): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Archive writes are disabled.');
        }

        $archivePath = $this->pathResolver->normalizePath($archivePath);
        $this->assertReadyForPath($archivePath);

        $incomingSha1 = sha1($contents);
        $incomingSize = strlen($contents);

        if (Storage::disk('archive')->exists($archivePath)) {
            $existingContents = Storage::disk('archive')->get($archivePath);

            if (sha1($existingContents) === $incomingSha1 && strlen($existingContents) === $incomingSize) {
                return $this->metadataForPath($archivePath) + [
                    'created' => false,
                    'conflict_path' => null,
                ];
            }

            $conflictPath = $this->moveConflictingArchiveAside($archivePath, sha1($existingContents));
            Log::warning('Moved conflicting archive copy aside before writing replacement.', [
                'archive_path' => $archivePath,
                'conflict_path' => $conflictPath,
            ]);
        } else {
            $conflictPath = null;
        }

        // The Card Reader may already have put these exact bytes on the archive
        // drive when the card was dumped. Renaming that file is instant and
        // writes nothing; the read-back below verifies it like any other copy.
        if (! $this->claimCardCopy($incomingSha1, $incomingSize, $archivePath)) {
            Storage::disk('archive')->put($archivePath, $contents);
        }

        if (! Storage::disk('archive')->exists($archivePath)) {
            throw new RuntimeException('Archive copy was not created at '.$archivePath);
        }

        $writtenContents = Storage::disk('archive')->get($archivePath);
        if (sha1($writtenContents) !== $incomingSha1 || strlen($writtenContents) !== $incomingSize) {
            throw new RuntimeException('Archive verification failed for '.$archivePath);
        }

        return $this->metadataForPath($archivePath) + [
            'created' => true,
            'conflict_path' => $conflictPath,
        ];
    }

    public function archivePhoto(Photo $photo, ?string $sourcePath = null, ?ShowClass $targetClass = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $sourcePath ??= $photo->full_path;

        if (! is_file($sourcePath)) {
            throw new RuntimeException('Cannot archive missing source file: '.$sourcePath);
        }

        $contents = file_get_contents($sourcePath);
        if ($contents === false) {
            throw new RuntimeException('Cannot read source file for archive: '.$sourcePath);
        }

        return $this->storeContents($this->pathForPhoto($photo, $targetClass), $contents);
    }

    public function markPhotoArchived(Photo $photo, array $metadata): void
    {
        $photo->forceFill([
            'archive_path' => $metadata['archive_path'],
            'archive_sha1' => $metadata['archive_sha1'],
            'archive_size' => $metadata['archive_size'],
            'archived_at' => $metadata['archived_at'],
        ])->save();
    }

    public function clearPhotoArchive(Photo $photo): void
    {
        $photo->forceFill([
            'archive_path' => null,
            'archive_sha1' => null,
            'archive_size' => null,
            'archived_at' => null,
        ])->save();
    }

    public function movePhotoArchive(Photo $photo, ShowClass $targetClass, ?string $newOriginalPath = null): ?array
    {
        if (! $this->enabled() || ! $this->configured()) {
            return null;
        }

        $targetPath = $this->pathForPhoto($photo, $targetClass);

        foreach ($this->sourceCandidatesForPhoto($photo) as $sourcePath) {
            if (Storage::disk('archive')->exists($sourcePath)) {
                $this->moveArchiveFile($sourcePath, $targetPath);

                return $this->metadataForPath($targetPath);
            }
        }

        if ($this->enabled() && $newOriginalPath && is_file($newOriginalPath)) {
            $contents = file_get_contents($newOriginalPath);
            if ($contents === false) {
                throw new RuntimeException('Cannot read moved original for archive: '.$newOriginalPath);
            }

            return $this->storeContents($targetPath, $contents);
        }

        return null;
    }

    public function movePhotoArchiveToFilename(Photo $photo, string $filename): ?array
    {
        if (! $this->enabled() || ! $this->configured()) {
            return null;
        }

        [$show, $class] = $this->photoShowClassParts($photo);
        $targetPath = $this->pathFor($show, $class, $filename);

        foreach ($this->sourceCandidatesForPhoto($photo) as $sourcePath) {
            if (Storage::disk('archive')->exists($sourcePath)) {
                $this->moveArchiveFile($sourcePath, $targetPath);

                return $this->metadataForPath($targetPath);
            }
        }

        return null;
    }

    /**
     * Move an archive copy by raw filename without requiring a Photo row.
     * Used by resetPhotos to keep the archive in sync with the local disk
     * even when an original on disk has no matching DB row (orphan original).
     * Returns the target metadata if a move happened, null if the source
     * archive didn't exist or archive is disabled.
     */
    public function movePhysicalArchive(string $show, string $class, string $oldFilename, string $newFilename): ?array
    {
        if (! $this->enabled() || ! $this->configured()) {
            return null;
        }

        $sourcePath = $this->pathFor($show, $class, $oldFilename);
        if (! Storage::disk('archive')->exists($sourcePath)) {
            return null;
        }
        $targetPath = $this->pathFor($show, $class, $newFilename);
        $this->moveArchiveFile($sourcePath, $targetPath);

        return $this->metadataForPath($targetPath);
    }

    public function auditPhoto(Photo $photo): array
    {
        $expectedPath = $this->pathForPhoto($photo);
        $sourcePath = $photo->full_path;
        $sourceExists = is_file($sourcePath);
        $archiveExists = Storage::disk('archive')->exists($expectedPath);

        $sourceSha1 = null;
        $sourceSize = null;
        if ($sourceExists) {
            $sourceContents = file_get_contents($sourcePath);
            if ($sourceContents !== false) {
                $sourceSha1 = sha1($sourceContents);
                $sourceSize = strlen($sourceContents);
            }
        }

        $archiveSha1 = null;
        $archiveSize = null;
        if ($archiveExists) {
            $metadata = $this->metadataForPath($expectedPath);
            $archiveSha1 = $metadata['archive_sha1'];
            $archiveSize = $metadata['archive_size'];
        }

        $metadataMatches = $archiveExists
            && $photo->archive_path === $expectedPath
            && $photo->archive_sha1 === $archiveSha1
            && (int) $photo->archive_size === (int) $archiveSize
            && $photo->archived_at !== null;

        $status = 'ok';
        if (! $sourceExists && ! $archiveExists) {
            $status = 'source_missing_archive_missing';
        } elseif (! $sourceExists) {
            $status = 'source_missing_archive_available';
        } elseif (! $archiveExists) {
            $status = 'archive_missing';
        } elseif ($sourceSha1 !== $archiveSha1 || $sourceSize !== $archiveSize) {
            $status = 'archive_mismatched';
        } elseif (! $metadataMatches) {
            $status = 'metadata_stale';
        }

        return [
            'photo_id' => $photo->id,
            'source_path' => $sourcePath,
            'source_exists' => $sourceExists,
            'source_sha1' => $sourceSha1,
            'source_size' => $sourceSize,
            'archive_path' => $expectedPath,
            'archive_exists' => $archiveExists,
            'archive_sha1' => $archiveSha1,
            'archive_size' => $archiveSize,
            'metadata_matches' => $metadataMatches,
            'status' => $status,
        ];
    }

    public function repairPhoto(Photo $photo): array
    {
        $audit = $this->auditPhoto($photo);
        $audit['repaired'] = false;

        if (! $this->enabled()) {
            $audit['repair_note'] = 'Archive disabled.';

            return $audit;
        }

        if (! $audit['source_exists']) {
            $audit['repair_note'] = 'Source file missing.';

            return $audit;
        }

        if ($audit['status'] === 'ok') {
            return $audit;
        }

        if ($audit['status'] === 'metadata_stale') {
            $this->markPhotoArchived($photo, $this->metadataForPath($audit['archive_path']));
            $audit['repaired'] = true;
            $audit['repair_note'] = 'Metadata refreshed.';

            return $this->auditPhoto($photo) + ['repaired' => true, 'repair_note' => 'Metadata refreshed.'];
        }

        $metadata = $this->archivePhoto($photo);
        if ($metadata) {
            $this->markPhotoArchived($photo, $metadata);
            $audit['repaired'] = true;
            $audit['repair_note'] = 'Archive copy written.';

            return $this->auditPhoto($photo) + ['repaired' => true, 'repair_note' => 'Archive copy written.'];
        }

        return $audit;
    }

    public function metadataForPath(string $archivePath): array
    {
        $archivePath = $this->pathResolver->normalizePath($archivePath);
        $contents = Storage::disk('archive')->get($archivePath);

        return [
            'archive_path' => $archivePath,
            'archive_sha1' => sha1($contents),
            'archive_size' => strlen($contents),
            'archived_at' => $this->lastModifiedCarbon($archivePath),
        ];
    }

    private function moveArchiveFile(string $sourcePath, string $targetPath): void
    {
        $sourcePath = $this->pathResolver->normalizePath($sourcePath);
        $targetPath = $this->pathResolver->normalizePath($targetPath);

        if ($sourcePath === $targetPath) {
            return;
        }

        $this->assertReadyForPath($targetPath);

        if (Storage::disk('archive')->exists($targetPath)) {
            $sourceContents = Storage::disk('archive')->get($sourcePath);
            $targetContents = Storage::disk('archive')->get($targetPath);

            if (sha1($sourceContents) === sha1($targetContents) && strlen($sourceContents) === strlen($targetContents)) {
                app(SafeFileMover::class)->bury(
                    disk: 'archive',
                    path: $sourcePath,
                    reason: SafeFileMover::REASON_REDUNDANT_ARCHIVE_SOURCE,
                    context: [
                        'sha1' => sha1($sourceContents),
                        'size' => strlen($sourceContents),
                        'kept_copy_at' => $targetPath,
                    ],
                );

                return;
            }

            $this->moveConflictingArchiveAside($targetPath, sha1($targetContents));
        }

        Storage::disk('archive')->move($sourcePath, $targetPath);

        if (! Storage::disk('archive')->exists($targetPath)) {
            throw new RuntimeException("Archive move failed from {$sourcePath} to {$targetPath}");
        }
    }

    private function moveConflictingArchiveAside(string $archivePath, string $existingSha1): string
    {
        $directory = $this->directoryFor($archivePath);
        $filename = basename($archivePath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $conflictDirectory = trim($directory.'/'.self::CONFLICT_DIRECTORY, '/');
        SafeDirectory::ensure(Storage::disk('archive'), $conflictDirectory);

        $suffix = Carbon::now()->format('Ymd_His').'_'.$existingSha1;
        $candidate = trim($conflictDirectory.'/'.$name.'_'.$suffix.($extension ? '.'.$extension : ''), '/');
        $counter = 1;

        while (Storage::disk('archive')->exists($candidate)) {
            $candidate = trim($conflictDirectory.'/'.$name.'_'.$suffix.'_'.$counter.($extension ? '.'.$extension : ''), '/');
            $counter++;
        }

        Storage::disk('archive')->move($archivePath, $candidate);

        return $candidate;
    }

    private function sourceCandidatesForPhoto(Photo $photo): array
    {
        return array_values(array_unique(array_filter([
            $photo->archive_path,
            $this->pathForPhoto($photo),
        ])));
    }

    private function filenameForPhoto(Photo $photo): string
    {
        return $photo->proof_number.'.'.$photo->file_type;
    }

    private function photoShowClassParts(Photo $photo): array
    {
        $class = $photo->showClass;
        if (! $class) {
            throw new RuntimeException('Invalid show_class_id for archive path: '.$photo->show_class_id);
        }

        return [$class->show_id, $class->name];
    }

    private function directoryFor(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : $this->pathResolver->normalizePath($directory);
    }

    private function lastModifiedCarbon(string $archivePath): Carbon
    {
        try {
            return Carbon::createFromTimestamp(Storage::disk('archive')->lastModified($archivePath));
        } catch (\Throwable) {
            return now();
        }
    }

    private function archiveRoot(): ?string
    {
        $root = config('filesystems.disks.archive.root') ?? config('proofgen.archive_home_dir');

        return is_string($root) ? $root : null;
    }
}
