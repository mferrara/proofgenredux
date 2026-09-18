<?php

namespace App\Services\Cards;

/**
 * One mounted storage device that could be a camera card.
 *
 * `slotKey` identifies the READER (bus + reader model + device-tree position),
 * not the card: it is what lets the next card inserted into the same reader be
 * selected automatically. `volumeUuid` identifies this particular card and is
 * re-checked before anything is copied from or deleted on it.
 */
final class CardVolume
{
    public function __construct(
        public readonly string $mountPoint,
        public readonly string $name,
        public readonly string $volumeUuid,
        public readonly string $deviceIdentifier,
        public readonly string $parentWholeDisk,
        public readonly string $busProtocol,
        public readonly string $mediaName,
        public readonly string $slotKey,
        public readonly string $filesystem,
        public readonly int $totalBytes,
        public readonly int $freeBytes,
        public readonly bool $writable,
        public readonly bool $hasDcim,
        /** False when macOS privacy denies this app the volume's contents. */
        public readonly bool $readable,
    ) {}

    public function label(): string
    {
        $reader = $this->mediaName !== '' ? $this->mediaName : $this->busProtocol;

        return $this->name.' — '.$reader.', '.round($this->totalBytes / 1_000_000_000, 1).' GB';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this) + ['label' => $this->label()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        unset($data['label']);

        return new self(...$data);
    }
}
