<?php

namespace App\Services\Delivery;

use App\Models\Show;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Ferraraphoto\FerraraphotoApiException;
use Illuminate\Support\Facades\Log;

/**
 * Decides where a show's rsync delivery goes.
 *
 * Gallery owns the destination (`GET /api/v1/delivery-target`); this install's
 * SFTP_* settings are only the fallback for an older Gallery without that
 * endpoint, or for an install with no API token.
 *
 *  - {@see current()} never touches the network. It is what read-only checks
 *    (pending-upload dry runs, the status panel) and the rsync calls use.
 *  - {@see refresh()} runs once per class delivery. It asks Gallery, compares
 *    the answer with the show's saved baseline, and refuses to switch the
 *    destination of a show that already has uploads until the operator
 *    accepts it.
 *
 * Fallback rule: a missing endpoint (404) or missing token falls back to local
 * settings. Every other failure stops the delivery.
 */
class DeliveryTargetResolver
{
    public const SCHEMA_VERSION = 1;

    public const LAYOUT = 'proofgen-v1';

    public function __construct(private FerraraphotoApiClient $api) {}

    public function current(Show $show): DeliveryTarget
    {
        if (! $this->handshakeApplies()) {
            return $this->local($show);
        }

        $saved = $this->saved($show);

        // A baseline recorded against another API origin says nothing about
        // this one until the next refresh confirms it.
        if ($saved !== null && $saved->isFromGallery() && $saved->apiOrigin === $this->apiOrigin()) {
            return $saved;
        }

        return $this->local($show);
    }

    /**
     * @throws DeliveryTargetException
     */
    public function refresh(Show $show): DeliveryTarget
    {
        $next = $this->handshakeApplies() ? $this->fetch($show) : $this->local($show);
        $previous = $this->saved($show);

        if ($previous !== null && $previous->differsFrom($next) && $this->hasUploads($show)) {
            $show->forceFill(['delivery_target_pending' => $next->toArray()])->saveQuietly();

            Log::warning('Delivery destination changed for a show with uploads; waiting for operator acceptance.', [
                'show_id' => $show->id,
                'previous' => $previous->comparable(),
                'next' => $next->comparable(),
            ]);

            throw DeliveryTargetException::changed((string) $show->id);
        }

        if ($previous === null && $next->isFromGallery() && $next->differsFrom($this->local($show))) {
            // First handshake for this show: Gallery is authoritative, but the
            // drift from the local settings is worth a line in the log.
            Log::warning('Gallery delivery destination differs from local SFTP settings; using Gallery\'s.', [
                'show_id' => $show->id,
                'gallery' => $next->comparable(),
            ]);
        }

        $this->store($show, $next);

        return $next;
    }

    /**
     * Operator accepted the destination recorded by a blocked refresh.
     */
    public function acceptPending(Show $show): DeliveryTarget
    {
        $pending = $this->pending($show);

        if ($pending === null) {
            return $this->current($show);
        }

        $this->store($show, $pending);

        return $pending;
    }

    public function pending(Show $show): ?DeliveryTarget
    {
        $data = $show->delivery_target_pending;

        return is_array($data) && $data !== [] ? DeliveryTarget::fromArray($data) : null;
    }

    /**
     * This install's own SFTP_* settings, in the same show-scoped shape.
     */
    public function local(Show $show): DeliveryTarget
    {
        $slug = $show->ferraraphoto_slug;
        $bases = [
            'proofs' => config('proofgen.sftp.path'),
            'web_images' => config('proofgen.sftp.web_images_path'),
            'highres_images' => config('proofgen.sftp.highres_images_path'),
        ];

        $directories = [];
        foreach ($bases as $kind => $base) {
            $base = trim((string) $base);
            $directories[$kind] = $base === '' ? '' : rtrim($base, '/').'/'.$slug;
        }

        return new DeliveryTarget(
            source: DeliveryTarget::SOURCE_LOCAL,
            driver: $this->localDriver(),
            host: (string) config('proofgen.sftp.host', ''),
            port: (int) (config('proofgen.sftp.port') ?: 22),
            username: (string) config('proofgen.sftp.username', 'forge'),
            directories: $directories,
            storageProfileId: $show->storage_profile_id,
            showSlug: $slug,
        );
    }

    /**
     * The handshake only describes rsync-over-SSH delivery. The local driver
     * (dev against a sibling install on this Mac) and installs without an API
     * token keep using their own settings.
     */
    private function handshakeApplies(): bool
    {
        return $this->localDriver() === DeliveryTarget::DRIVER_RSYNC_SSH
            && filled(config('proofgen.ferraraphoto.api_token'));
    }

    private function fetch(Show $show): DeliveryTarget
    {
        $showId = (string) $show->id;

        try {
            $data = $this->api->deliveryTarget($show->ferraraphoto_slug);
        } catch (FerraraphotoApiException $exception) {
            $reason = $exception->getMessage().' ('.$exception->apiCode.', HTTP '.$exception->status.')';

            throw $exception->status === 0 || $exception->status >= 500
                ? DeliveryTargetException::unavailable($showId, $reason, $exception)
                : DeliveryTargetException::unsupported($showId, $reason, $exception);
        }

        if ($data === null) {
            // Older Gallery without the endpoint.
            return $this->local($show);
        }

        return $this->fromHandshake($show, $data);
    }

    /**
     * @param  array<string, mixed>  $data  the handshake's `data` object
     */
    private function fromHandshake(Show $show, array $data): DeliveryTarget
    {
        $showId = (string) $show->id;

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw DeliveryTargetException::unsupported($showId, 'unknown schema_version '.json_encode($data['schema_version'] ?? null).'.');
        }

        if (($data['layout'] ?? null) !== self::LAYOUT) {
            throw DeliveryTargetException::unsupported($showId, 'unknown layout '.json_encode($data['layout'] ?? null).'.');
        }

        if (($data['storage_profile']['driver'] ?? null) !== 'local') {
            throw DeliveryTargetException::unsupported($showId, 'storage profile driver '.json_encode($data['storage_profile']['driver'] ?? null).' is not delivered by rsync.');
        }

        $directories = [];
        foreach (DeliveryTarget::KINDS as $kind) {
            $directory = $data['destinations'][$kind]['directory'] ?? null;

            if (! is_string($directory) || ! str_starts_with($directory, '/') || rtrim($directory, '/') === '') {
                throw DeliveryTargetException::unsupported($showId, 'missing absolute '.$kind.' directory.');
            }

            $directories[$kind] = rtrim($directory, '/');
        }

        // Without `transport`, Gallery has no explicit SSH origin configured:
        // keep its directories and reach them with this install's host settings.
        $local = $this->local($show);
        $transport = $data['transport'] ?? null;
        $host = $local->host;
        $port = $local->port;
        $username = $local->username;

        if (is_array($transport)) {
            if (($transport['driver'] ?? null) !== DeliveryTarget::DRIVER_RSYNC_SSH) {
                throw DeliveryTargetException::unsupported($showId, 'unknown transport driver '.json_encode($transport['driver'] ?? null).'.');
            }

            if (blank($transport['host'] ?? null)) {
                throw DeliveryTargetException::unsupported($showId, 'transport has no host.');
            }

            $host = (string) $transport['host'];
            $port = (int) ($transport['port'] ?? 22) ?: 22;
            $username = filled($transport['username'] ?? null) ? (string) $transport['username'] : $local->username;
        }

        return new DeliveryTarget(
            source: DeliveryTarget::SOURCE_GALLERY,
            driver: DeliveryTarget::DRIVER_RSYNC_SSH,
            host: $host,
            port: $port,
            username: $username,
            directories: $directories,
            storageProfileId: isset($data['storage_profile']['id']) ? (string) $data['storage_profile']['id'] : null,
            apiOrigin: $this->apiOrigin(),
            fetchedAt: now()->toIso8601String(),
            showSlug: $show->ferraraphoto_slug,
        );
    }

    private function saved(Show $show): ?DeliveryTarget
    {
        $data = $show->delivery_target;

        return is_array($data) && $data !== [] ? DeliveryTarget::fromArray($data) : null;
    }

    /**
     * Only Gallery answers are kept as a baseline; local settings are always
     * read fresh, so an operator edit takes effect immediately.
     */
    private function store(Show $show, DeliveryTarget $target): void
    {
        $show->forceFill([
            'delivery_target' => $target->isFromGallery() ? $target->toArray() : null,
            'delivery_target_pending' => null,
        ])->saveQuietly();
    }

    private function hasUploads(Show $show): bool
    {
        return $show->photos()
            ->where(fn ($query) => $query
                ->whereNotNull('proofs_uploaded_at')
                ->orWhereNotNull('web_image_uploaded_at')
                ->orWhereNotNull('highres_image_uploaded_at'))
            ->exists();
    }

    private function localDriver(): string
    {
        return config('proofgen.sftp.driver', 'sftp') === 'local'
            ? DeliveryTarget::DRIVER_LOCAL
            : DeliveryTarget::DRIVER_RSYNC_SSH;
    }

    private function apiOrigin(): string
    {
        return rtrim((string) config('proofgen.ferraraphoto.base_url'), '/');
    }
}
