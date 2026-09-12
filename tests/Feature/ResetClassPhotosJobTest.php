<?php

namespace Tests\Feature;

use App\Jobs\ShowClass\ResetClassPhotos;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ResetClassPhotosJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true, 'proofgen.archive_enabled' => false]);
        Storage::fake('fullsize');
        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW_1', 'name' => 'Display Name']));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => 'SHOW_1_101', 'show_id' => 'SHOW_1', 'name' => '101',
        ]));
    }

    public function test_reset_uses_show_identity_instead_of_display_name(): void
    {
        $photo = Photo::create(['show_class_id' => 'SHOW_1_101', 'proof_number' => '100', 'file_type' => 'jpg']);
        Storage::disk('fullsize')->put('SHOW_1/101/originals/100.jpg', 'original bytes');

        (new ResetClassPhotos('SHOW_1', '101'))->handle();

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $released = Storage::disk('fullsize')->files('SHOW_1/101');
        $this->assertCount(1, $released);
        $this->assertSame('original bytes', Storage::disk('fullsize')->get($released[0]));
    }

    public function test_partial_reset_is_reported_as_a_failed_job(): void
    {
        $photo = Photo::create(['show_class_id' => 'SHOW_1_101', 'proof_number' => '100', 'file_type' => 'jpg']);

        try {
            (new ResetClassPhotos('SHOW_1', '101'))->handle();
            $this->fail('A missing original must not be reported as a successful reset.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failure(s)', $exception->getMessage());
        }

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    }
}
