<?php

namespace App\Services;

use Illuminate\Support\Facades\Queue;
use Throwable;

class WorkerActivityService
{
    /** Queue sizes are live; reserved includes jobs currently held by a worker. */
    public function snapshot(): array
    {
        $waiting = $active = $delayed = 0;
        try {
            $queues = array_map(fn (string $name) => [config('queue.default'), $name], ['default', 'processing', 'imports', 'thumbnails']);
            foreach (['queue', 'web_queue', 'highres_queue'] as $uploadQueue) {
                $queues[] = [config('proofgen.uploads.connection', 'uploads'), config('proofgen.uploads.'.$uploadQueue)];
            }
            $queues[] = ['cards', 'cards'];

            foreach ($queues as [$connection, $queueName]) {
                $queue = Queue::connection($connection);
                $waiting += $queue->pendingSize($queueName);
                $active += $queue->reservedSize($queueName);
                $delayed += $queue->delayedSize($queueName);
            }

            return compact('waiting', 'active', 'delayed') + ['available' => true];
        } catch (Throwable $e) {
            return ['available' => false, 'waiting' => null, 'active' => null, 'delayed' => null];
        }
    }
}
