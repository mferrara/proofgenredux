<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\ShowClass;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

/** Reads actual queue contents so paused workers and delayed retries stay busy. */
class QueuedWorkStatus
{
    public const ACTION_TARGETS = 'importPendingImages,processPendingClassImages,processImage,proofPendingPhotos,webImagePendingPhotos,highresImagePendingPhotos,proofPhoto,generateWebImage,generateHighresImage,regenerateProofs,regenerateWebImages,regenerateHighresImages,resetPhotos,uploadPendingProofs,uploadPendingProofsAndWebImages,checkProofAndWebImageUploads';

    public function snapshot(string $showId, ?string $className = null): array
    {
        $status = ['available' => true, 'busy' => false, 'waiting' => 0, 'active' => 0, 'delayed' => 0, 'classes' => []];

        try {
            $classes = ShowClass::where('show_id', $showId)->pluck('name', 'id')->all();
            $photos = Photo::whereIn('show_class_id', array_keys($classes))->pluck('show_class_id', 'id')->all();
            $seen = [];
            foreach ($this->queuedPayloads() as [$state, $payload]) {
                $decoded = json_decode($payload, true);
                $uuid = $decoded['uuid'] ?? sha1($payload);
                if (isset($seen[$uuid])) {
                    continue;
                }
                $seen[$uuid] = true;
                $scopes = $this->commandScopes($decoded['data']['command'] ?? '', $showId, $classes, $photos);
                if ($scopes === [] || ($className !== null && ! in_array($className, $scopes, true) && ! in_array('*', $scopes, true))) {
                    continue;
                }
                $status[$state]++;
                foreach ($scopes as $scope) {
                    $status['classes'][$scope] = true;
                }
            }
            $status['busy'] = $status['waiting'] + $status['active'] + $status['delayed'] > 0;
        } catch (Throwable $e) {
            // A queue outage must not look like an idle queue to the action buttons.
            $status['available'] = false;
            $status['busy'] = true;
        }

        return $status;
    }

    protected function queuedPayloads(): iterable
    {
        $default = config('queue.default');
        $queues = array_map(fn ($name) => [$default, $name], array_unique([
            config("queue.connections.{$default}.queue", 'default'),
            'default', 'imports', 'processing', 'thumbnails', 'generate-web', 'generate-highres',
        ]));
        foreach (['queue', 'web_queue', 'highres_queue'] as $uploadQueue) {
            $queues[] = [config('proofgen.uploads.connection', 'uploads'), config('proofgen.uploads.'.$uploadQueue)];
        }

        foreach (array_unique($queues, SORT_REGULAR) as [$connection, $name]) {
            if (config("queue.connections.{$connection}.driver") === 'sync') {
                continue;
            }
            $queue = Queue::connection($connection);
            if (! $queue instanceof RedisQueue) {
                throw new RuntimeException('Queue activity requires the configured Horizon Redis queue.');
            }
            $redis = $queue->getConnection();
            $key = $queue->getQueue($name);
            // Laravel's InspectedJob API omits command identifiers; read the same
            // payloads here to associate jobs (including chains) with their class.
            foreach ($redis->lrange($key, 0, -1) as $payload) {
                yield ['waiting', $payload];
            }
            foreach ($redis->zrange($key.':reserved', 0, -1) as $payload) {
                yield ['active', $payload];
            }
            foreach ($redis->zrange($key.':delayed', 0, -1) as $payload) {
                yield ['delayed', $payload];
            }
        }
    }

    private function commandScopes(string $serialized, string $showId, array $classes, array $photos): array
    {
        $command = @unserialize($serialized, ['allowed_classes' => false]);
        if (! is_object($command)) {
            return [];
        }
        $data = (array) $command;
        $scopes = [];
        foreach ($data['chained'] ?? [] as $next) {
            $scopes = array_merge($scopes, $this->commandScopes($next, $showId, $classes, $photos));
        }
        $classId = $data['classId'] ?? $photos[$data['photo_id'] ?? ''] ?? null;
        if (isset($classes[$classId])) {
            $scopes[] = $classes[$classId];
        }
        $path = explode('/', ltrim($data['image_path'] ?? '', '/'));
        if (($path[0] ?? '') === $showId && isset($path[1])) {
            $scopes[] = $path[1];
        }
        if (($data['show_id'] ?? $data['showId'] ?? $data['show'] ?? null) === $showId) {
            // A class upload chain starts by ensuring the show exists. Its child
            // commands identify the affected classes more precisely than that job.
            if (isset($data['class'])) {
                $scopes[] = $data['class'];
            } elseif ($scopes === []) {
                $scopes[] = '*';
            }
        }

        return array_values(array_unique($scopes));
    }
}
