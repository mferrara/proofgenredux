<?php

namespace App\Services;

use App\Helpers\DirectoryNameValidator;
use App\Models\Photo;
use App\Models\ShowClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ClassRenameService
{
    public function renameClass(ShowClass $showClass, string $newName): array
    {
        $oldName = $showClass->name;
        $showId = $showClass->show_id;

        // Validate the new name
        if (! DirectoryNameValidator::isValid($newName)) {
            return [
                'success' => false,
                'error' => DirectoryNameValidator::getValidationError($newName),
            ];
        }

        // Check if the new class already exists
        $newClassId = $showId.'_'.$newName;
        if (ShowClass::find($newClassId)) {
            return [
                'success' => false,
                'error' => "A class with the name '{$newName}' already exists in this show.",
            ];
        }

        // Store paths for potential rollback
        $oldRelativePath = $showId.'/'.$oldName;
        $newRelativePath = $showId.'/'.$newName;
        $renamedPaths = [];

        // Pre-flight check: Ensure we have permissions for all operations
        $preflightResult = $this->preflightCheck($oldRelativePath, $newRelativePath);
        if (! $preflightResult['success']) {
            return [
                'success' => false,
                'error' => $preflightResult['error'],
            ];
        }

        try {
            // First, perform all file system operations (these are the risky ones)
            $renamedPaths = $this->renameDirectories($oldRelativePath, $newRelativePath);

            // Then, perform database operations in a transaction
            DB::transaction(function () use ($showClass, $newName) {
                // Update Photo records
                $this->updatePhotoRecords($showClass, $newName);

                // Update ShowClass record
                $this->updateShowClassRecord($showClass, $newName);
            });

            Log::info("Successfully renamed class from '{$oldName}' to '{$newName}' for show '{$showId}'");

            return [
                'success' => true,
                'message' => "Class renamed from '{$oldName}' to '{$newName}'.",
            ];
        } catch (\Exception $e) {
            Log::error("Failed to rename class: {$e->getMessage()}", [
                'show_id' => $showId,
                'old_name' => $oldName,
                'new_name' => $newName,
                'error' => $e->getTraceAsString(),
            ]);

            // Attempt to rollback file system changes
            $this->rollbackRenamedPaths($renamedPaths);

            return [
                'success' => false,
                'error' => 'Failed to rename class. Please check permissions and try again.',
            ];
        }
    }

    private function renameDirectories(string $oldPath, string $newPath): array
    {
        $renamedPaths = [];

        // Rename in fullsize storage
        if (Storage::disk('fullsize')->exists($oldPath)) {
            Storage::disk('fullsize')->move($oldPath, $newPath);
            $renamedPaths[] = ['disk' => 'fullsize', 'old' => $oldPath, 'new' => $newPath];
            Log::debug("Renamed fullsize directory from '{$oldPath}' to '{$newPath}'");
        }

        // Rename in web_images storage
        $webImagesOldPath = 'web_images/'.$oldPath;
        $webImagesNewPath = 'web_images/'.$newPath;
        if (Storage::disk('fullsize')->exists($webImagesOldPath)) {
            Storage::disk('fullsize')->move($webImagesOldPath, $webImagesNewPath);
            $renamedPaths[] = ['disk' => 'fullsize', 'old' => $webImagesOldPath, 'new' => $webImagesNewPath];
            Log::debug("Renamed web_images directory from '{$webImagesOldPath}' to '{$webImagesNewPath}'");
        }

        // Rename in highres_images storage
        $highresOldPath = 'highres_images/'.$oldPath;
        $highresNewPath = 'highres_images/'.$newPath;
        if (Storage::disk('fullsize')->exists($highresOldPath)) {
            Storage::disk('fullsize')->move($highresOldPath, $highresNewPath);
            $renamedPaths[] = ['disk' => 'fullsize', 'old' => $highresOldPath, 'new' => $highresNewPath];
            Log::debug("Renamed highres_images directory from '{$highresOldPath}' to '{$highresNewPath}'");
        }

        // Rename in archive storage if configured
        if (config('proofgen.archive_home_dir')) {
            if (Storage::disk('archive')->exists($oldPath)) {
                Storage::disk('archive')->move($oldPath, $newPath);
                $renamedPaths[] = ['disk' => 'archive', 'old' => $oldPath, 'new' => $newPath];
                Log::debug("Renamed archive directory from '{$oldPath}' to '{$newPath}'");
            }
        }

        return $renamedPaths;
    }

    private function rollbackRenamedPaths(array $renamedPaths): void
    {
        foreach (array_reverse($renamedPaths) as $path) {
            try {
                if (Storage::disk($path['disk'])->exists($path['new'])) {
                    Storage::disk($path['disk'])->move($path['new'], $path['old']);
                    Log::info("Rolled back directory rename from '{$path['new']}' to '{$path['old']}' on disk '{$path['disk']}'");
                }
            } catch (\Exception $e) {
                Log::error("Failed to rollback directory rename: {$e->getMessage()}", [
                    'disk' => $path['disk'],
                    'old_path' => $path['old'],
                    'new_path' => $path['new'],
                ]);
            }
        }
    }

    private function preflightCheck(string $oldPath, string $newPath): array
    {
        $errors = [];

        // Check all the directories we need to rename
        $pathsToCheck = [
            ['disk' => 'fullsize', 'path' => $oldPath, 'type' => 'main'],
            ['disk' => 'fullsize', 'path' => 'web_images/'.$oldPath, 'type' => 'web_images'],
            ['disk' => 'fullsize', 'path' => 'highres_images/'.$oldPath, 'type' => 'highres_images'],
        ];

        // Add archive if configured
        if (config('proofgen.archive_home_dir')) {
            $pathsToCheck[] = ['disk' => 'archive', 'path' => $oldPath, 'type' => 'archive'];
        }

        foreach ($pathsToCheck as $check) {
            if (Storage::disk($check['disk'])->exists($check['path'])) {
                // Check if we can write to the parent directory
                $parentDir = dirname($check['path']);

                try {
                    // Get the full path to check permissions
                    $diskConfig = config('filesystems.disks.'.$check['disk']);
                    $rootPath = $diskConfig['root'] ?? '';
                    $fullPath = $rootPath.'/'.$check['path'];
                    $fullParentPath = dirname($fullPath);

                    // Check if parent directory is writable
                    if (! is_writable($fullParentPath)) {
                        $errors[] = "No write permission for {$check['type']} directory parent: {$fullParentPath}";
                    }

                    // Check if the source directory itself is readable
                    if (! is_readable($fullPath)) {
                        $errors[] = "No read permission for {$check['type']} directory: {$fullPath}";
                    }

                    // Check if destination doesn't already exist
                    $newFullPath = str_replace($oldPath, $newPath, $check['path']);
                    if (Storage::disk($check['disk'])->exists($newFullPath)) {
                        $errors[] = "Destination already exists for {$check['type']}: {$newFullPath}";
                    }
                } catch (\Exception $e) {
                    $errors[] = "Failed to check permissions for {$check['type']}: ".$e->getMessage();
                }
            }
        }

        if (! empty($errors)) {
            return [
                'success' => false,
                'error' => "Pre-flight check failed:\n".implode("\n", $errors),
            ];
        }

        return ['success' => true];
    }

    private function updatePhotoRecords(ShowClass $showClass, string $newName): void
    {
        $photos = $showClass->photos()->get();
        $newClassId = $showClass->show_id.'_'.$newName;

        foreach ($photos as $photo) {
            $newPhotoId = $newClassId.'_'.$photo->proof_number;

            // Create new photo record with updated ID
            $newPhoto = $photo->replicate();
            $newPhoto->id = $newPhotoId;
            $newPhoto->show_class_id = $newClassId;

            // Save without triggering events
            $newPhoto->saveQuietly();

            // Update metadata if exists
            if ($photo->metadata) {
                $photo->metadata->update(['photo_id' => $newPhotoId]);
            }

            // Delete old photo record
            $photo->delete();
        }

        Log::debug('Updated '.count($photos).' photo records for class rename');
    }

    private function updateShowClassRecord(ShowClass $showClass, string $newName): void
    {
        $newId = $showClass->show_id.'_'.$newName;

        // Create new ShowClass record
        $newShowClass = $showClass->replicate();
        $newShowClass->id = $newId;
        $newShowClass->name = $newName;

        // Save without triggering events
        $newShowClass->saveQuietly();

        // Delete old ShowClass record
        $showClass->delete();

        Log::debug("Updated ShowClass record from '{$showClass->id}' to '{$newId}'");
    }
}
