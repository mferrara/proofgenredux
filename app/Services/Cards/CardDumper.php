<?php

namespace App\Services\Cards;

use App\Models\Photo;
use App\Services\PathResolver;
use App\Services\PhotoArchiveService;
use App\Services\SafeDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copies files off a card so that two verified copies exist as fast as the
 * drives allow: each file is read from the card ONCE, in chunks, and every
 * chunk is written to the class folder and to the archive drive in the same
 * pass while the content hash is computed. Both copies are then flushed and
 * read back; only a file whose read-back hashes match the card counts as safe.
 *
 * The class-folder copy is written under a hidden staging folder and renamed
 * into place, so the importer never sees a half-written file.
 *
 * Nothing here ever writes to, deletes from, or ejects the card.
 */
class CardDumper
{
    private const CHUNK_BYTES = 4 * 1024 * 1024;

    public function __construct(private PathResolver $paths, private PhotoArchiveService $archive) {}

    public function archiveUsable(string $showId): bool
    {
        try {
            $this->archive->assertReadyForPath($showId.'/_cards/.probe');

            return $this->archive->enabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{name: string, sha1: string, size: int, status: string, working: ?string, archive: ?string, verified: bool}
     *                                                                                                                          status: copied | already_imported | already_present
     */
    public function copy(string $sourcePath, string $showId, string $classFolder, string $cardFolder, bool $toArchive): array
    {
        $name = basename($sourcePath);
        $working = Storage::disk('fullsize');
        $classPath = $showId.'/'.$classFolder;
        $staging = $classPath.'/.incoming-'.Str::lower(Str::random(8));

        SafeDirectory::ensure($working, $staging);
        $workingPart = $working->path($staging.'/'.$name.'.part');

        $archivePath = null;
        $archivePart = null;
        if ($toArchive) {
            $archiveDisk = Storage::disk('archive');
            $archiveDirectory = $showId.'/_cards/'.$classFolder.'/'.$cardFolder;
            SafeDirectory::ensure($archiveDisk, $archiveDirectory);
            $archivePath = $archiveDirectory.'/'.$name;
            $archivePart = $archiveDisk->path($archivePath.'.part');
        }

        try {
            [$sha1, $size] = $this->tee($sourcePath, array_filter([$workingPart, $archivePart]));

            foreach (array_filter([$workingPart, $archivePart]) as $written) {
                if (sha1_file($written) !== $sha1 || filesize($written) !== $size) {
                    throw new RuntimeException('Read-back of '.$name.' does not match the card.');
                }
            }

            // Already in the catalog: a second working copy would only be
            // quarantined as a duplicate by the importer. The archive copy is kept.
            $importedPhoto = Photo::where('sha1', $sha1)->first();
            $imported = $importedPhoto !== null;
            // "Already imported" only counts as a second copy when that
            // photo's original is really on disk with the same content.
            $importedOriginalIntact = $imported
                && is_file($importedPhoto->full_path)
                && sha1_file($importedPhoto->full_path) === $sha1;

            $final = $classPath.'/'.$name;
            $status = 'copied';

            if ($imported) {
                @unlink($workingPart);
                $final = null;
                $status = 'already_imported';
            } elseif ($working->exists($final)) {
                if (sha1_file($working->path($final)) === $sha1) {
                    @unlink($workingPart);
                    $status = 'already_present';
                } else {
                    // Same camera name, different photo (counter rollover): keep both.
                    $final = $classPath.'/'.pathinfo($name, PATHINFO_FILENAME).'-'.substr($sha1, 0, 8).'.'.pathinfo($name, PATHINFO_EXTENSION);
                    $this->moveIntoPlace($workingPart, $working->path($final));
                }
            } else {
                $this->moveIntoPlace($workingPart, $working->path($final));
            }

            if ($archivePart !== null) {
                $existingArchive = $importedOriginalIntact ? $this->archive->pathForPhoto($importedPhoto) : null;

                if ($existingArchive !== null
                    && Storage::disk('archive')->exists($existingArchive)
                    && sha1_file(Storage::disk('archive')->path($existingArchive)) === $sha1) {
                    // Already imported AND already archived: a third copy is clutter.
                    @unlink($archivePart);
                    $archivePath = $existingArchive;
                } else {
                    $this->moveIntoPlace($archivePart, Storage::disk('archive')->path($archivePath));

                    // So the import can rename this copy instead of writing another.
                    DB::table('card_files')->insert([
                        'sha1' => $sha1, 'size' => $size, 'show_id' => $showId, 'class_folder' => $classFolder,
                        'archive_path' => $archivePath, 'dumped_at' => now(),
                    ]);
                }
            }

            return [
                'name' => $name, 'sha1' => $sha1, 'size' => $size, 'status' => $status,
                'working' => $final, 'archive' => $archivePath,
                // Safe to clear from the card only with a verified copy in BOTH places.
                'verified' => $archivePath !== null && ($final !== null || $importedOriginalIntact),
            ];
        } finally {
            foreach (array_filter([$workingPart, $archivePart]) as $leftover) {
                if (is_file($leftover)) {
                    @unlink($leftover);
                }
            }
            @rmdir($working->path($staging));
        }
    }

    /**
     * @param  array<int, string>  $destinations
     * @return array{0: string, 1: int} sha1 and byte count of what was read
     */
    private function tee(string $sourcePath, array $destinations): array
    {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException('Could not read '.$sourcePath.' from the card.');
        }

        $handles = [];
        foreach ($destinations as $destination) {
            $handle = fopen($destination, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Could not write '.$destination.'.');
            }
            $handles[] = $handle;
        }

        $hash = hash_init('sha1');
        $size = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Reading '.$sourcePath.' failed part-way (card removed?).');
                }
                if ($chunk === '') {
                    continue;
                }

                hash_update($hash, $chunk);
                $size += strlen($chunk);

                foreach ($handles as $handle) {
                    if (fwrite($handle, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Short write while copying '.basename($sourcePath).' (disk full?).');
                    }
                }
            }

            foreach ($handles as $handle) {
                fflush($handle);
                fsync($handle);
            }
        } finally {
            fclose($source);
            array_map('fclose', $handles);
        }

        return [hash_final($hash), $size];
    }

    private function moveIntoPlace(string $from, string $to): void
    {
        if (! rename($from, $to)) {
            throw new RuntimeException('Could not move '.basename($to).' into place.');
        }
    }
}
