<?php

namespace Tests\Feature;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Normal import dispatch must respect the same product toggles as the manual
 * regeneration actions: proofs are never suppressed, while web/highres jobs
 * follow proofgen.generate_web_images.enabled / generate_highres_images.enabled.
 */
class PhotoImportProductSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'testing.skip_file_operations' => true,
            'proofgen.archive_enabled' => false,
            'proofgen.rename_files' => true,
        ]);
        Storage::fake('fullsize');
        config(['proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path('')]);

        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => 'SHOW1_101',
            'show_id' => 'SHOW1',
            'name' => '101',
        ]));
    }

    public function test_both_enabled_dispatches_proofs_web_and_highres(): void
    {
        Bus::fake();

        $result = $this->importPhoto(web: true, highres: true);

        $this->assertNotNull($result['photo']);
        Bus::assertDispatchedTimes(GenerateThumbnails::class, 1);
        Bus::assertDispatchedTimes(GenerateWebImage::class, 1);
        Bus::assertDispatchedTimes(GenerateHighresImage::class, 1);
    }

    public function test_web_disabled_still_dispatches_proofs_and_highres(): void
    {
        Bus::fake();

        $result = $this->importPhoto(web: false, highres: true);

        $this->assertNotNull($result['photo']);
        Bus::assertDispatchedTimes(GenerateThumbnails::class, 1);
        Bus::assertNotDispatched(GenerateWebImage::class);
        Bus::assertDispatchedTimes(GenerateHighresImage::class, 1);
    }

    public function test_highres_disabled_still_dispatches_proofs_and_web(): void
    {
        Bus::fake();

        $result = $this->importPhoto(web: true, highres: false);

        $this->assertNotNull($result['photo']);
        Bus::assertDispatchedTimes(GenerateThumbnails::class, 1);
        Bus::assertDispatchedTimes(GenerateWebImage::class, 1);
        Bus::assertNotDispatched(GenerateHighresImage::class);
    }

    public function test_both_disabled_still_dispatches_proofs_only(): void
    {
        Bus::fake();

        $result = $this->importPhoto(web: false, highres: false);

        $this->assertNotNull($result['photo']);
        Bus::assertDispatchedTimes(GenerateThumbnails::class, 1);
        Bus::assertNotDispatched(GenerateWebImage::class);
        Bus::assertNotDispatched(GenerateHighresImage::class);
    }

    public function test_dispatch_jobs_false_imports_without_dispatching_anything(): void
    {
        Bus::fake();

        $result = $this->importPhoto(web: true, highres: true, dispatchJobs: false);

        $this->assertNotNull($result['photo']);
        $this->assertNotNull(Photo::find($result['photo']->id));
        Bus::assertNothingDispatched();
    }

    /**
     * @return array{photo: Photo|null, plan: mixed, issue: mixed, proofDestPath: ?string, webImagesPath: ?string, highresImagesPath: ?string}
     */
    private function importPhoto(bool $web, bool $highres, bool $dispatchJobs = true): array
    {
        config([
            'proofgen.generate_web_images.enabled' => $web,
            'proofgen.generate_highres_images.enabled' => $highres,
        ]);

        Storage::disk('fullsize')->put('SHOW1/101/IMG_00001.jpg', 'image bytes');

        return app(PhotoService::class)->processPhoto(
            'SHOW1/101/IMG_00001.jpg',
            '00001',
            dispatchJobs: $dispatchJobs,
            bypassResolver: true,
        );
    }
}
