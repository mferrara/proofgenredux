<?php

namespace App\Services\Cards;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Lists the storage devices the operator may pick as a card source.
 *
 * Nothing is matched on volume name or camera brand: photographers bring
 * different bodies and readers (built-in SD slot, USB CF/CFexpress readers).
 * Anything mounted under /Volumes qualifies except the places Proofgen itself
 * writes to, network shares, and (unless allowed for testing) disk images.
 */
class CardVolumeFinder
{
    private const NETWORK_FILESYSTEMS = ['smbfs', 'afpfs', 'nfs', 'webdav', 'ftp'];

    /**
     * @return array<int, CardVolume>
     */
    public function all(): array
    {
        $volumes = [];

        foreach ($this->mountPoints() as $mountPoint) {
            try {
                $volume = $this->inspect($mountPoint);
            } catch (Throwable) {
                continue;
            }

            if ($volume !== null) {
                $volumes[] = $volume;
            }
        }

        usort($volumes, fn (CardVolume $a, CardVolume $b) => [$b->hasDcim, $a->name] <=> [$a->hasDcim, $b->name]);

        return $volumes;
    }

    public function byMountPoint(string $mountPoint): ?CardVolume
    {
        foreach ($this->all() as $volume) {
            if ($volume->mountPoint === $mountPoint) {
                return $volume;
            }
        }

        return null;
    }

    /**
     * The card currently in a remembered reader. When the remembered reader is
     * empty and exactly one camera card is present, that one is offered.
     */
    public function inSlot(?string $slotKey): ?CardVolume
    {
        $volumes = $this->all();

        foreach ($volumes as $volume) {
            if ($slotKey !== null && $volume->slotKey === $slotKey) {
                return $volume;
            }
        }

        $cards = array_values(array_filter($volumes, fn (CardVolume $volume) => $volume->hasDcim));

        return $slotKey === null && count($cards) === 1 ? $cards[0] : null;
    }

    /**
     * @return array<int, string>
     */
    protected function mountPoints(): array
    {
        return array_values(array_filter(
            glob('/Volumes/*') ?: [],
            fn (string $path) => is_dir($path) && ! is_link($path),
        ));
    }

    protected function inspect(string $mountPoint): ?CardVolume
    {
        $info = $this->diskutilInfo($mountPoint);

        if ($info === null || ($info['MountPoint'] ?? null) !== $mountPoint) {
            return null;
        }

        $protocol = (string) ($info['BusProtocol'] ?? '');

        if (in_array($info['FilesystemType'] ?? '', self::NETWORK_FILESYSTEMS, true)
            || ($protocol === 'Disk Image' && ! config('proofgen.cards.allow_disk_images'))
            || $this->holdsProofgenData($mountPoint)) {
            return null;
        }

        $listing = @scandir($mountPoint);

        return new CardVolume(
            mountPoint: $mountPoint,
            name: (string) ($info['VolumeName'] ?? basename($mountPoint)),
            volumeUuid: (string) ($info['VolumeUUID'] ?? ''),
            deviceIdentifier: (string) ($info['DeviceIdentifier'] ?? ''),
            parentWholeDisk: (string) ($info['ParentWholeDisk'] ?? ''),
            busProtocol: $protocol,
            mediaName: trim((string) ($info['MediaName'] ?? '')),
            slotKey: sha1($protocol.'|'.trim((string) ($info['MediaName'] ?? '')).'|'.($info['DeviceTreePath'] ?? '')),
            filesystem: (string) ($info['FilesystemName'] ?? $info['FilesystemType'] ?? ''),
            totalBytes: (int) ($info['TotalSize'] ?? 0),
            freeBytes: (int) ($info['FreeSpace'] ?? 0),
            writable: (bool) ($info['WritableVolume'] ?? false),
            hasDcim: is_array($listing) && in_array('DCIM', array_map('strtoupper', $listing), true),
            readable: is_array($listing),
        );
    }

    /**
     * Never offer the working or archive drive as a "card".
     */
    private function holdsProofgenData(string $mountPoint): bool
    {
        foreach (['proofgen.fullsize_home_dir', 'proofgen.archive_home_dir'] as $key) {
            $path = (string) config($key);

            if ($path !== '' && str_starts_with(rtrim($path, '/').'/', rtrim($mountPoint, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function diskutilInfo(string $mountPoint): ?array
    {
        $result = Process::timeout(10)->run(['/usr/sbin/diskutil', 'info', '-plist', $mountPoint]);

        if (! $result->successful()) {
            return null;
        }

        $json = Process::timeout(10)->input($result->output())->run(['/usr/bin/plutil', '-convert', 'json', '-o', '-', '-']);
        $data = $json->successful() ? json_decode($json->output(), true) : null;

        return is_array($data) ? $data : null;
    }
}
