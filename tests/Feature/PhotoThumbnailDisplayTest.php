<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PhotoThumbnailDisplayTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        // These harnesses render the partial as their root view, without the
        // page's ShareErrorsFromSession middleware.
        $this->app['view']->share('errors', new ViewErrorBag);
        config(['testing.skip_file_operations' => true]);
        config(['proofgen.thumbnails.small.suffix' => '_thm']);

        $this->tempPath = storage_path('app/photo_thumbnail_test_'.uniqid());
        File::makeDirectory($this->tempPath, 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath]);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath,
            'throw' => true,
        ]]);

        Storage::forgetDisk('fullsize');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_service_returns_data_uri_preserving_thumbnail_bytes(): void
    {
        $photo = $this->createPhoto('SHOW1', '101', '00001');
        $bytes = $this->jpegBytes('preserve');

        Storage::disk('fullsize')->put('proofs/SHOW1/101/00001_thm.jpg', $bytes);

        $uri = app(PhotoThumbnailService::class)->dataUri($photo);

        $this->assertIsString($uri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);
        $this->assertSame($bytes, base64_decode(substr($uri, strlen('data:image/jpeg;base64,'))));
    }

    public function test_service_uses_configured_suffix_and_jpg_for_jpeg_originals_with_underscored_ids(): void
    {
        config(['proofgen.thumbnails.small.suffix' => '_thumb']);

        $photo = $this->createPhoto('2023_R41', 'opening_ceremony', '00042', ['file_type' => 'jpeg']);
        $service = app(PhotoThumbnailService::class);

        // A file that keeps the original .jpeg extension must be ignored; the
        // proof pipeline always writes the thumbnail as .jpg.
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpeg', 'not a thumbnail');
        $this->assertNull($service->dataUri($photo));

        $bytes = $this->jpegBytes('underscored');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpg', $bytes);

        $this->assertSame(
            'data:image/jpeg;base64,'.base64_encode($bytes),
            $service->dataUri($photo)
        );
    }

    public function test_missing_thumbnail_returns_null_and_is_not_cached_as_success(): void
    {
        $photo = $this->createPhoto('SHOW1', '101', '00003');
        $service = app(PhotoThumbnailService::class);

        $this->assertNull($service->dataUri($photo));

        $bytes = $this->jpegBytes('appears-later');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/00003_thm.jpg', $bytes);
        $expected = 'data:image/jpeg;base64,'.base64_encode($bytes);

        // The miss must not have been cached, so the newly available file is found.
        $this->assertSame($expected, $service->dataUri($photo));

        // A success is cached: removing the file does not drop the cached URI.
        Storage::disk('fullsize')->delete('proofs/SHOW1/101/00003_thm.jpg');
        $this->assertSame($expected, $service->dataUri($photo));
    }

    public function test_cache_busts_when_photo_is_regenerated(): void
    {
        $photo = $this->createPhoto('SHOW1', '101', '00004', ['proofs_generated_at' => now()->subHour()]);
        $service = app(PhotoThumbnailService::class);

        $first = $this->jpegBytes('first');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/00004_thm.jpg', $first);
        $this->assertSame('data:image/jpeg;base64,'.base64_encode($first), $service->dataUri($photo));

        $second = $this->jpegBytes('second-generation');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/00004_thm.jpg', $second);

        // Same cache key (photo not updated yet) still serves the old bytes.
        $this->assertSame('data:image/jpeg;base64,'.base64_encode($first), $service->dataUri($photo));

        $photo->proofs_generated_at = now();
        $photo->save();

        $this->assertSame('data:image/jpeg;base64,'.base64_encode($second), $service->dataUri($photo));
    }

    public function test_table_renders_available_thumbnail_after_missing_row(): void
    {
        [$missing, $available, $bytes] = $this->twoPhotosWithSecondThumbnail('table');

        $html = $this->renderPartial('components.partials.photos-table', [
            'photos' => collect([$missing, $available]),
            'display_thumbnail' => true,
            'details' => true,
            'thumbnailSize' => 'small',
            'selectedPhotos' => [],
        ]);

        $this->assertSame(1, substr_count($html, 'data:image/jpeg;base64,'), 'Only the available thumbnail should render.');
        $this->assertStringContainsString('src="data:image/jpeg;base64,'.base64_encode($bytes).'"', $html);
    }

    public function test_grid_renders_available_thumbnail_after_missing_row(): void
    {
        [$missing, $available, $bytes] = $this->twoPhotosWithSecondThumbnail('grid');

        $html = $this->renderPartial('components.partials.photos-grid', [
            'photos' => collect([$missing, $available]),
            'thumbnailSize' => 'small',
            'selectedPhotos' => [],
        ]);

        $this->assertSame(1, substr_count($html, 'data:image/jpeg;base64,'), 'Only the available thumbnail should render.');
        $this->assertStringContainsString('src="data:image/jpeg;base64,'.base64_encode($bytes).'"', $html);
    }

    /**
     * @return array{0: Photo, 1: Photo, 2: string}
     */
    private function twoPhotosWithSecondThumbnail(string $seed): array
    {
        $missing = $this->createPhoto('SHOW1', '101', '00010');
        $available = $this->createPhoto('SHOW1', '101', '00011');

        $bytes = $this->jpegBytes($seed);
        Storage::disk('fullsize')->put('proofs/SHOW1/101/00011_thm.jpg', $bytes);
        Storage::disk('fullsize')->put($missing->relative_path, 'original');
        Storage::disk('fullsize')->put($available->relative_path, 'original');

        return [$missing, $available, $bytes];
    }

    private function renderPartial(string $view, array $data): string
    {
        return view($view, $data)->render();
    }

    private function createPhoto(string $showId, string $className, string $proofNumber, array $overrides = []): Photo
    {
        Show::withoutEvents(function () use ($showId) {
            Show::firstOrCreate(['id' => $showId], ['name' => $showId]);
        });

        ShowClass::withoutEvents(function () use ($showId, $className) {
            ShowClass::firstOrCreate(
                ['id' => $showId.'_'.$className],
                ['show_id' => $showId, 'name' => $className],
            );
        });

        return Photo::create(array_merge([
            'show_class_id' => $showId.'_'.$className,
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'proofs_generated_at' => now(),
        ], $overrides));
    }

    private function jpegBytes(string $seed): string
    {
        $image = imagecreatetruecolor(8, 8);
        $color = imagecolorallocate($image, strlen($seed) % 255, 96, 144);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagejpeg($image, null, 95);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
