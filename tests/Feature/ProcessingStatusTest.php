<?php

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\ClassProcessingStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['testing.skip_file_operations' => true, 'proofgen.generate_web_images.enabled' => true, 'proofgen.generate_highres_images.enabled' => true]);
    Storage::fake('fullsize');
    Show::withoutEvents(fn () => Show::create(['id' => 'LIVE', 'name' => 'LIVE']));
    $this->class = ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'LIVE_001', 'show_id' => 'LIVE', 'name' => '001']));
    foreach (['00001', '00002'] as $number) {
        Photo::create(['show_class_id' => $this->class->id, 'proof_number' => $number, 'file_type' => 'jpg']);
    }
    $this->photos = $this->class->photos;
    Storage::disk('fullsize')->put($this->photos[0]->relative_path, 'original');
});

it('distinguishes missing originals from ready work and observes restored files', function () {
    $service = app(ClassProcessingStatus::class);
    expect($service->snapshot($this->class))->toMatchArray(['missing_originals' => 1, 'ready' => ['proofs' => 1, 'web' => 1, 'highres' => 1]]);
    Storage::disk('fullsize')->put($this->photos[1]->relative_path, 'restored');
    expect($service->snapshot($this->class))->toMatchArray(['missing_originals' => 0, 'ready' => ['proofs' => 2, 'web' => 2, 'highres' => 2]]);
});

it('only dispatches generation for photos with originals', function ($method, $job) {
    Bus::fake();
    expect($this->class->$method($this->photos))->toBe(1);
    Bus::assertDispatchedTimes($job, 1);
    Bus::assertDispatched($job, fn ($queued) => $queued->photo_id === $this->photos[0]->id);
})->with([
    ['queueThumbnailGeneration', GenerateThumbnails::class],
    ['queueWebImageGeneration', GenerateWebImage::class],
    ['queueHighresImageGeneration', GenerateHighresImage::class],
]);

it('shows recent failures for the exact class without mixing in similarly named classes', function () {
    foreach ([[$this->photos[0]->id, now()], ['LIVE_001_extra_00001', now()], [$this->photos[1]->id, now()->subDays(2)]] as [$id, $at]) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'thumbnails',
            'payload' => json_encode(['data' => ['command' => serialize(new GenerateThumbnails($id, '/proofs'))]]),
            'exception' => "Original missing\nStack trace should not appear", 'failed_at' => $at,
        ]);
    }
    $status = app(ClassProcessingStatus::class)->snapshot($this->class);
    expect($status['failed_count'])->toBe(1)->and($status['failures'][0]['message'])->toBe('Original missing');
});

it('preserves generated state when regeneration has no original', function ($method, $field) {
    Bus::fake();
    $photo = $this->photos[1];
    $photo->forceFill([$field => now()])->saveQuietly();
    $this->class->$method();
    expect($photo->fresh()->getAttribute($field))->not->toBeNull();
})->with([
    ['regenerateProofs', 'proofs_generated_at'],
    ['regenerateWebImages', 'web_image_generated_at'],
    ['regenerateHighresImages', 'highres_image_generated_at'],
]);
