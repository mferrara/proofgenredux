<?php

namespace App\Services\Ferraraphoto;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

class FerraraphotoApiClient
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly LoggerInterface $logger,
    ) {}

    public function upsertStorageProfile(StorageProfile $profile): array
    {
        return $this->request('post', '/storage-profiles', $profile->toApiPayload());
    }

    public function upsertShow(Show $show): array
    {
        return $this->request('post', '/shows', [
            'slug' => $show->ferraraphoto_slug,
            'name' => $show->name,
            'start_date' => null,
            'end_date' => null,
            'storage_profile_id' => $show->storage_profile_id,
            'auto_import' => false,
        ]);
    }

    public function upsertClass(ShowClass $class): array
    {
        return $this->request('post', '/shows/'.$this->segment($class->show->ferraraphoto_slug).'/classes', [
            'class_number' => $class->name,
            'name' => $class->name,
            'session_name' => 'Imported Classes',
        ]);
    }

    /**
     * @param  Collection<int, Photo>  $photos
     */
    public function upsertPhotos(ShowClass $class, Collection $photos): array
    {
        return $this->request(
            'post',
            '/shows/'.$this->segment($class->show->ferraraphoto_slug).'/classes/'.$this->segment($class->name).'/photos',
            [
                'photos' => $photos->map(fn (Photo $photo) => $this->photoPayload($photo))->values()->all(),
            ],
        );
    }

    public function readShow(string $slug): ?array
    {
        try {
            return $this->request('get', '/shows/'.$this->segment($slug));
        } catch (FerraraphotoApiException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Shows that exist on the website, newest first. Null when this Gallery
     * predates the endpoint (404) - shows are then still created through
     * {@see upsertShow()} as before.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function listShows(?string $search = null, int $limit = 100): ?array
    {
        try {
            return array_values($this->request('get', '/shows', query: array_filter([
                'q' => $search,
                'limit' => $limit,
            ], fn ($value) => $value !== null && $value !== '')));
        } catch (FerraraphotoApiException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function readDeliveries(string $slug, int $page = 1, int $perPage = 100): array
    {
        return $this->request('get', '/shows/'.$this->segment($slug).'/deliveries', query: [
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Gallery's delivery-target handshake for one show slug. Null when this
     * Gallery predates the endpoint (404); every other failure throws.
     */
    public function deliveryTarget(string $slug): ?array
    {
        try {
            return $this->request('get', '/delivery-target', query: ['show_slug' => $slug]);
        } catch (FerraraphotoApiException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function profileHealth(string $profileId): array
    {
        return $this->request('get', '/storage-profiles/'.$this->segment($profileId).'/health');
    }

    private function request(string $method, string $path, array $body = [], array $query = []): array
    {
        if (blank($this->apiToken)) {
            throw new FerraraphotoApiException('missing_api_token', 'Ferraraphoto API token is not configured.', 0);
        }

        $method = strtoupper($method);
        $url = rtrim($this->baseUrl, '/').'/api/v1'.$path;
        $response = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken($this->apiToken)
                    ->timeout(30)
                    ->acceptJson()
                    ->asJson()
                    ->send($method, $url, $this->requestOptions($method, $body, $query));
            } catch (ConnectionException $exception) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new FerraraphotoApiException('network_error', $exception->getMessage(), 0, previous: $exception);
                }

                $this->pauseBeforeRetry($attempt, $method, $url, $exception->getMessage());

                continue;
            }

            if (! $response->serverError() || $attempt === self::MAX_ATTEMPTS) {
                break;
            }

            $this->pauseBeforeRetry($attempt, $method, $url, 'HTTP '.$response->status());
        }

        if (! $response instanceof Response) {
            throw new FerraraphotoApiException('network_error', 'No response returned from ferraraphoto API.', 0);
        }

        if (! $response->ok()) {
            $this->throwForResponse($response);
        }

        return $response->json('data') ?? [];
    }

    private function requestOptions(string $method, array $body, array $query): array
    {
        if ($method === 'GET') {
            return empty($query) ? [] : ['query' => $query];
        }

        return ['json' => $body];
    }

    private function throwForResponse(Response $response): never
    {
        $error = $response->json('error');

        if (! is_array($error)) {
            $error = [
                'code' => 'unknown',
                'message' => $response->body(),
                'context' => [],
            ];
        }

        throw new FerraraphotoApiException(
            apiCode: (string) ($error['code'] ?? 'unknown'),
            message: (string) ($error['message'] ?? 'Ferraraphoto API request failed.'),
            status: $response->status(),
            context: is_array($error['context'] ?? null) ? $error['context'] : [],
        );
    }

    private function photoPayload(Photo $photo): array
    {
        $metadata = $photo->metadata;

        return [
            'proof_number' => $photo->proof_number,
            'object_keys' => $photo->object_keys,
            'sha1' => $photo->sha1,
            'size_bytes' => $metadata?->file_size,
            'captured_at' => $metadata?->exif_timestamp?->toIso8601String(),
        ];
    }

    private function pauseBeforeRetry(int $attempt, string $method, string $url, string $reason): void
    {
        $this->logger->warning('Retrying ferraraphoto API request', [
            'attempt' => $attempt,
            'method' => $method,
            'url' => $url,
            'reason' => $reason,
        ]);

        usleep(250_000);
    }

    private function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
