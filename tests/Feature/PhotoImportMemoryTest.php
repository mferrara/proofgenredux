<?php

namespace Tests\Feature;

use App\Jobs\Photo\GenerateThumbnails;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhotoImportMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_jpeg_import_finishes_with_the_worker_memory_limit(): void
    {
        // A full-suite process retains allocations from unrelated image tests.
        // Run this hard memory cap in a fresh Pest process, like a fresh worker.
        if (getenv('PROOFGEN_IMPORT_MEMORY_CHILD') !== '1') {
            $process = new Process(
                [PHP_BINARY, base_path('vendor/bin/pest'), __FILE__, '--compact'],
                base_path(),
                ['PROOFGEN_IMPORT_MEMORY_CHILD' => '1'],
            );
            $process->setTimeout(30)->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

            return;
        }

        config([
            'testing.skip_file_operations' => false,
            'proofgen.archive_enabled' => false,
            'proofgen.rename_files' => true,
        ]);
        Storage::fake('fullsize');
        config(['proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path('')]);
        Show::withoutEvents(fn () => Show::create(['id' => 'MEMORY', 'name' => 'MEMORY']));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => 'MEMORY_001', 'show_id' => 'MEMORY', 'name' => '001',
        ]));
        Bus::fake();

        Storage::disk('fullsize')->makeDirectory('MEMORY/001');
        $source = Storage::disk('fullsize')->path('MEMORY/001/IMG_0001.JPG');
        $image = imagecreatetruecolor(16, 16);
        imagejpeg($image, $source);
        unset($image);
        // JPEG permits trailing bytes. Grow the fixture on disk without keeping
        // a 30 MiB fixture string in the test's own memory footprint.
        $handle = fopen($source, 'ab');
        for ($i = 0; $i < 30; $i++) {
            fwrite($handle, str_repeat('x', 1024 * 1024));
        }
        fclose($handle);
        clearstatcache(true, $source);
        $expectedSize = filesize($source);
        $expectedSha = sha1_file($source);

        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        try {
            $result = app(PhotoService::class)->processPhoto('MEMORY/001/IMG_0001.JPG', 'MEMORY_00001');
            $photo = $result['photo']->fresh();
            $this->assertSame(1, Photo::count());
            $this->assertSame($expectedSha, $photo->sha1);
            $this->assertSame($expectedSize, $photo->metadata->file_size);
            $this->assertSame($expectedSha, sha1_file($photo->full_path));
            Storage::disk('fullsize')->assertMissing('MEMORY/001/IMG_0001.JPG');
            Bus::assertDispatched(GenerateThumbnails::class);
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }
}
