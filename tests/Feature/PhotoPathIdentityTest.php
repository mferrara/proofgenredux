<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoArchiveService;
use App\Services\PhotoMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoPathIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_archive_and_move_paths_use_actual_show_and_class_fields(): void
    {
        config(['testing.skip_file_operations' => true, 'proofgen.archive_enabled' => false]);
        Storage::fake('fullsize');
        Queue::fake();
        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW_1', 'name' => 'Display name']));
        $source = ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => 'SHOW_1_warm_up', 'show_id' => 'SHOW_1', 'name' => 'warm_up',
        ]));
        $target = ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => 'SHOW_1_final', 'show_id' => 'SHOW_1', 'name' => 'final',
        ]));
        $photo = Photo::create(['show_class_id' => $source->id, 'proof_number' => '100', 'file_type' => 'jpg']);
        Storage::disk('fullsize')->put('SHOW_1/warm_up/originals/100.jpg', 'original bytes');

        $this->assertSame('SHOW_1/warm_up', $source->relative_path);
        $this->assertSame('SHOW_1/warm_up/100.jpg', app(PhotoArchiveService::class)->pathForPhoto($photo));

        $result = app(PhotoMoveService::class)->movePhotos([$photo->id], $target->id);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['100'], $result['success']);
        $this->assertSame('original bytes', Storage::disk('fullsize')->get('SHOW_1/final/originals/100.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('SHOW_1/warm_up/originals/100.jpg'));
    }
}
