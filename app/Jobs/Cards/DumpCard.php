<?php

namespace App\Jobs\Cards;

use App\Jobs\ShowClass\ImportClassPhotos;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\Cards\CardDumper;
use App\Services\Cards\CardVolumeFinder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One card dump, on its own queue so import and generation work from earlier
 * cards can never make a card wait.
 *
 * Order matters: every file is copied and verified first; only then are
 * imports queued, verified files cleared from the card, and the card ejected.
 * A failure part-way leaves the card exactly as it was.
 */
class DumpCard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    /**
     * @param  array<string, array<int, string>>  $assignments  class folder => paths relative to the card
     * @param  array{import: bool, clear: bool, eject: bool}  $options
     */
    public function __construct(
        public string $dumpId,
        public string $showId,
        public string $mountPoint,
        public string $volumeUuid,
        public array $assignments,
        public array $options,
    ) {
        $this->onConnection('cards');
        $this->onQueue('cards');
    }

    public static function progressKey(string $dumpId): string
    {
        return 'card-dump:'.$dumpId;
    }

    /** The files of a dump in the order they are copied, written once so the progress itself stays small. */
    public static function filesKey(string $dumpId): string
    {
        return 'card-dump:'.$dumpId.':files';
    }

    public function handle(CardDumper $dumper, CardVolumeFinder $finder): void
    {
        $total = array_sum(array_map('count', $this->assignments));

        $files = [];
        foreach ($this->assignments as $classFolder => $paths) {
            foreach ($paths as $relative) {
                $files[] = ['class' => (string) $classFolder, 'name' => basename($relative)];
            }
        }
        Cache::put(self::filesKey($this->dumpId), $files, 86400);
        $progress = [
            'state' => 'copying', 'total' => $total, 'done' => 0, 'bytes' => 0, 'current' => null,
            'copied' => 0, 'already_imported' => 0, 'already_present' => 0, 'cleared' => 0,
            'errors' => [], 'message' => null, 'started_at' => now()->timestamp, 'finished_at' => null,
        ];
        $this->publish($progress);

        try {
            $this->assertSameCard($finder);
            Show::findOrFail($this->showId);

            $toArchive = $dumper->archiveUsable($this->showId);
            $cardFolder = now()->format('Y-m-d_His').'-'.substr($this->volumeUuid !== '' ? $this->volumeUuid : sha1($this->mountPoint), 0, 8);
            $manifest = [];

            foreach ($this->assignments as $classFolder => $paths) {
                foreach ($paths as $relative) {
                    $progress['current'] = basename($relative);
                    $this->publish($progress);

                    $result = $dumper->copy($this->mountPoint.'/'.$relative, $this->showId, (string) $classFolder, $cardFolder, $toArchive);

                    $manifest[(string) $classFolder][] = $result + ['card_path' => $relative];
                    $progress[$result['status']]++;
                    $progress['done']++;
                    $progress['bytes'] += $result['size'];
                    $this->publish($progress);
                }

                if ($toArchive) {
                    Storage::disk('archive')->put(
                        $this->showId.'/_cards/'.$classFolder.'/'.$cardFolder.'/manifest.json',
                        json_encode([
                            'show' => $this->showId, 'class' => (string) $classFolder, 'dumped_at' => now()->toIso8601String(),
                            'card' => ['volume_uuid' => $this->volumeUuid, 'mount_point' => $this->mountPoint],
                            'files' => $manifest[(string) $classFolder],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    );
                }
            }

            if ($this->options['import'] ?? true) {
                foreach (array_keys($this->assignments) as $classFolder) {
                    ShowClass::firstOrCreate(
                        ['id' => $this->showId.'_'.$classFolder],
                        ['show_id' => $this->showId, 'name' => (string) $classFolder],
                    );
                    ImportClassPhotos::dispatch($this->showId, (string) $classFolder);
                }
            }

            if (($this->options['clear'] ?? false) && $toArchive) {
                $progress['state'] = 'clearing';
                $this->publish($progress);
                $progress['cleared'] = $this->clearVerified($finder, $manifest);
            }

            if ($this->options['eject'] ?? false) {
                $progress['state'] = 'ejecting';
                $this->publish($progress);
                $progress['message'] = $this->eject($finder);
            }

            $progress['state'] = 'done';
        } catch (Throwable $exception) {
            Log::error('Card dump failed: '.$exception->getMessage(), ['dump' => $this->dumpId, 'show' => $this->showId]);
            $progress['state'] = 'failed';
            $progress['errors'][] = $exception->getMessage();
            $progress['message'] = 'Nothing was removed from the card.';
        }

        $progress['current'] = null;
        $progress['finished_at'] = now()->timestamp;
        $this->publish($progress);
    }

    /**
     * Delete from the card only what has a verified copy in BOTH places, and
     * only if the card in the reader is still the one that was scanned.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $manifest
     */
    private function clearVerified(CardVolumeFinder $finder, array $manifest): int
    {
        $this->assertSameCard($finder);
        $cleared = 0;

        foreach ($manifest as $files) {
            foreach ($files as $file) {
                $onCard = $this->mountPoint.'/'.$file['card_path'];

                if ($file['verified'] && is_file($onCard) && filesize($onCard) === $file['size'] && @unlink($onCard)) {
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    private function eject(CardVolumeFinder $finder): string
    {
        $volume = $finder->byMountPoint($this->mountPoint);

        if ($volume === null || $volume->volumeUuid !== $this->volumeUuid) {
            return 'The card was already removed.';
        }

        // Spotlight or fseventsd can hold the volume for a moment.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $result = Process::timeout(30)->run(['/usr/sbin/diskutil', 'eject', $volume->parentWholeDisk ?: $this->mountPoint]);

            if ($result->successful()) {
                return 'Ejected — remove the card.';
            }

            usleep(750_000);
        }

        return 'Could not eject ('.trim($result->errorOutput() ?: $result->output()).'). Eject it in Finder.';
    }

    private function assertSameCard(CardVolumeFinder $finder): void
    {
        $volume = $finder->byMountPoint($this->mountPoint);

        if ($volume === null || $volume->volumeUuid !== $this->volumeUuid) {
            throw new \RuntimeException('The card that was scanned is no longer in the reader.');
        }
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function publish(array $progress): void
    {
        Cache::put(self::progressKey($this->dumpId), $progress, 86400);
    }
}
