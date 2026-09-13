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
            foreach (['default', 'processing', 'imports', 'thumbnails', 'uploads'] as $name) {
                $queue = Queue::connection($name === 'uploads' ? config('proofgen.uploads.connection', 'uploads') : config('queue.default'));
                $queueName = $name === 'uploads' ? config('proofgen.uploads.queue', 'uploads') : $name;
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
