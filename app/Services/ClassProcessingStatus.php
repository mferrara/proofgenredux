<?php

namespace App\Services;

use App\Models\ShowClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClassProcessingStatus
{
    public function snapshot(ShowClass $class): array
    {
        $missing = 0;
        $ready = ['proofs' => 0, 'web' => 0, 'highres' => 0];
        foreach ($class->photos as $photo) {
            if (! Storage::disk('fullsize')->exists($photo->relative_path)) {
                $missing++;

                continue;
            }
            $ready['proofs'] += (int) ($photo->proofs_generated_at === null);
            $ready['web'] += (int) ($photo->web_image_generated_at === null);
            $ready['highres'] += (int) ($photo->highres_image_generated_at === null);
        }

        $photoIds = $class->photos->keyBy('id');
        $failures = [];
        $failedCount = 0;
        // Match serialized public job identifiers without instantiating job classes.
        $rows = DB::table(config('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', now()->subDay())->orderByDesc('id')
            ->cursor(['payload', 'exception', 'failed_at']);
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            $serialized = $payload['data']['command'] ?? '';
            $data = @unserialize($serialized, ['allowed_classes' => false]);
            $data = is_object($data) ? (array) $data : [];
            $matches = $photoIds->has($data['photo_id'] ?? '')
                || str_starts_with($data['image_path'] ?? '', $class->show_id.'/'.$class->name.'/')
                || (($data['show'] ?? null) === $class->show_id && ($data['class'] ?? null) === $class->name);
            if (! $matches) {
                continue;
            }
            $failedCount++;
            if (count($failures) < 3) {
                $failures[] = ['message' => mb_substr(strtok($row->exception, "\n"), 0, 350), 'at' => $row->failed_at];
            }
        }

        return ['missing_originals' => $missing, 'ready' => $ready, 'failed_count' => $failedCount, 'failures' => $failures];
    }
}
