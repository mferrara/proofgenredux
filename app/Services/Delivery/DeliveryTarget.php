<?php

namespace App\Services\Delivery;

use InvalidArgumentException;

/**
 * Where one show's derived files are delivered by rsync.
 *
 * Built either from Gallery's `GET /api/v1/delivery-target` handshake
 * (source "gallery") or from this install's own SFTP_* settings (source
 * "local"). Both produce the same shape: each directory is already scoped to
 * the remote show slug, so callers append only the class folder.
 *
 * The SSH private key is never part of a target; it always comes from
 * `proofgen.sftp.private_key` on this machine.
 */
final class DeliveryTarget
{
    public const SOURCE_GALLERY = 'gallery';

    public const SOURCE_LOCAL = 'local';

    /** rsync over SSH to a remote host. */
    public const DRIVER_RSYNC_SSH = 'rsync_ssh';

    /** Plain rsync between directories on this Mac (dev against a sibling install). */
    public const DRIVER_LOCAL = 'local';

    public const KINDS = ['proofs', 'web_images', 'highres_images'];

    /**
     * @param  array<string, string>  $directories  sync type => absolute show-scoped directory ('' when unconfigured)
     * @param  string  $showSlug  remote show slug the directories were resolved for
     */
    public function __construct(
        public readonly string $source,
        public readonly string $driver,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly array $directories,
        public readonly ?string $storageProfileId = null,
        public readonly ?string $apiOrigin = null,
        public readonly ?string $fetchedAt = null,
        public readonly string $showSlug = '',
    ) {}

    /**
     * Absolute show-scoped destination for a sync type, without a trailing
     * slash. Empty when that kind has no configured destination.
     */
    public function directory(string $syncType): string
    {
        if (! in_array($syncType, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown sync type: {$syncType}");
        }

        return rtrim(trim((string) ($this->directories[$syncType] ?? '')), '/');
    }

    public function isFromGallery(): bool
    {
        return $this->source === self::SOURCE_GALLERY;
    }

    public function usesSsh(): bool
    {
        return $this->driver === self::DRIVER_RSYNC_SSH;
    }

    /**
     * True when files would land somewhere else: transport, any directory, or
     * the storage profile differs. The source, API origin and fetch time are
     * deliberately ignored - the same physical destination reported by a
     * different origin (beta and production share a filesystem) is no change.
     */
    public function differsFrom(self $other): bool
    {
        return $this->comparable() !== $other->comparable();
    }

    /**
     * @return array<string, mixed>
     */
    public function comparable(): array
    {
        return [
            'driver' => $this->driver,
            'host' => $this->usesSsh() ? $this->host : '',
            'port' => $this->usesSsh() ? $this->port : 0,
            'username' => $this->usesSsh() ? $this->username : '',
            'directories' => array_combine(self::KINDS, array_map(fn (string $kind) => $this->directory($kind), self::KINDS)),
            'storage_profile_id' => (string) $this->storageProfileId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'driver' => $this->driver,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'directories' => $this->directories,
            'storage_profile_id' => $this->storageProfileId,
            'api_origin' => $this->apiOrigin,
            'fetched_at' => $this->fetchedAt,
            'show_slug' => $this->showSlug,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a previous {@see toArray()} result
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: (string) ($data['source'] ?? self::SOURCE_LOCAL),
            driver: (string) ($data['driver'] ?? self::DRIVER_RSYNC_SSH),
            host: (string) ($data['host'] ?? ''),
            port: (int) ($data['port'] ?? 22),
            username: (string) ($data['username'] ?? ''),
            directories: array_map('strval', (array) ($data['directories'] ?? [])),
            storageProfileId: isset($data['storage_profile_id']) ? (string) $data['storage_profile_id'] : null,
            apiOrigin: isset($data['api_origin']) ? (string) $data['api_origin'] : null,
            fetchedAt: isset($data['fetched_at']) ? (string) $data['fetched_at'] : null,
            showSlug: (string) ($data['show_slug'] ?? ''),
        );
    }
}
