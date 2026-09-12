<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Generated proof/web/highres derivatives are always encoded as .jpg, even when
 * the imported original keeps a .jpeg extension. Checks, deletes and moves must
 * follow the configured suffixes and never look for a .jpeg derivative.
 */
class PhotoDerivativePathsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true, 'proofgen.archive_enabled' => false]);
        Storage::fake('fullsize');
        $this->root = Storage::disk('fullsize')->path('');
        config(['proofgen.fullsize_home_dir' => $this->root]);
        config([
            'proofgen.thumbnails.small.suffix' => '_thumb',
            'proofgen.thumbnails.large.suffix' => '_big',
            'proofgen.web_images.suffix' => '_web2',
            'proofgen.highres_images.suffix' => '_hr',
        ]);
    }

    public function test_derivative_filenames_use_configured_suffixes_and_jpg_for_jpeg_originals(): void
    {
        $photo = $this->makePhoto();

        $this->assertSame('2023_R41/opening_ceremony/originals/00042.jpeg', $photo->relative_path);
        $this->assertSame(['00042_thumb.jpg', '00042_big.jpg'], $photo->expectedThumbnailFilenames());
        $this->assertSame(
            $this->root.'/web_images/2023_R41/opening_ceremony/00042_web2.jpg',
            $photo->expectedWebImageFilePath()
        );
        $this->assertSame(
            $this->root.'/highres_images/2023_R41/opening_ceremony/00042_hr.jpg',
            $photo->expectedHighresImageFilePath()
        );
    }

    public function test_check_path_for_proofs_ignores_jpeg_derivatives(): void
    {
        $photo = $this->makePhoto();

        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpg', 'thumb');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_big.jpg', 'big');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpeg', 'stale');

        $found = $photo->checkPathForProofs();

        $this->assertIsArray($found);
        $this->assertCount(2, $found);
        $this->assertArrayHasKey($this->root.'/proofs/2023_R41/opening_ceremony/00042_thumb.jpg', $found);
        $this->assertArrayHasKey($this->root.'/proofs/2023_R41/opening_ceremony/00042_big.jpg', $found);
        $this->assertArrayNotHasKey($this->root.'/proofs/2023_R41/opening_ceremony/00042_thumb.jpeg', $found);
    }

    public function test_delete_local_proofs_removes_only_expected_jpg_derivatives(): void
    {
        $photo = $this->makePhoto([
            'proofs_generated_at' => now(),
            'proofs_uploaded_at' => now(),
        ]);

        Storage::disk('fullsize')->put('2023_R41/opening_ceremony/originals/00042.jpeg', 'original');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpg', 'thumb');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_big.jpg', 'big');
        // A stale derivative that keeps the original .jpeg extension is not ours to delete.
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpeg', 'stale');

        $photo->deleteLocalProofs();

        $this->assertFalse(Storage::disk('fullsize')->exists('proofs/2023_R41/opening_ceremony/00042_thumb.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('proofs/2023_R41/opening_ceremony/00042_big.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists('proofs/2023_R41/opening_ceremony/00042_thumb.jpeg'));

        // The original is untouched.
        $this->assertTrue(Storage::disk('fullsize')->exists('2023_R41/opening_ceremony/originals/00042.jpeg'));

        $photo->refresh();
        $this->assertNull($photo->proofs_generated_at);
        $this->assertNull($photo->proofs_uploaded_at);
    }

    public function test_web_and_highres_checks_and_deletes_use_configured_suffixes(): void
    {
        $photo = $this->makePhoto([
            'web_image_generated_at' => now(),
            'web_image_uploaded_at' => now(),
            'highres_image_generated_at' => now(),
            'highres_image_uploaded_at' => now(),
        ]);

        Storage::disk('fullsize')->put('web_images/2023_R41/opening_ceremony/00042_web2.jpg', 'web');
        Storage::disk('fullsize')->put('highres_images/2023_R41/opening_ceremony/00042_hr.jpg', 'highres');

        $this->assertTrue($photo->checkPathForWebImage());
        $this->assertTrue($photo->checkPathForHighresImage());

        $photo->deleteLocalWebImage();
        $photo->deleteLocalHighresImage();

        $this->assertFalse(Storage::disk('fullsize')->exists('web_images/2023_R41/opening_ceremony/00042_web2.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('highres_images/2023_R41/opening_ceremony/00042_hr.jpg'));

        $photo->refresh();
        $this->assertNull($photo->web_image_generated_at);
        $this->assertNull($photo->web_image_uploaded_at);
        $this->assertNull($photo->highres_image_generated_at);
        $this->assertNull($photo->highres_image_uploaded_at);
    }

    public function test_move_moves_jpeg_original_and_jpg_derivatives_for_underscored_ids(): void
    {
        Queue::fake();

        $source = $this->sourceClass();
        $target = ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => '2023_R41_closing_ceremony',
            'show_id' => '2023_R41',
            'name' => 'closing_ceremony',
        ]));

        $photo = $this->makePhoto([
            'proofs_generated_at' => now(),
            'web_image_generated_at' => now(),
            'highres_image_generated_at' => now(),
        ], $source);

        Storage::disk('fullsize')->put('2023_R41/opening_ceremony/originals/00042.jpeg', 'original bytes');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpg', 'thumb');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_big.jpg', 'big');
        Storage::disk('fullsize')->put('proofs/2023_R41/opening_ceremony/00042_thumb.jpeg', 'stale');
        Storage::disk('fullsize')->put('web_images/2023_R41/opening_ceremony/00042_web2.jpg', 'web');
        Storage::disk('fullsize')->put('highres_images/2023_R41/opening_ceremony/00042_hr.jpg', 'highres');

        $result = app(PhotoMoveService::class)->movePhotos([$photo->id], $target->id);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['00042'], $result['success']);

        // Original keeps its .jpeg extension and bytes.
        $this->assertFalse(Storage::disk('fullsize')->exists('2023_R41/opening_ceremony/originals/00042.jpeg'));
        $this->assertSame('original bytes', Storage::disk('fullsize')->get('2023_R41/closing_ceremony/originals/00042.jpeg'));

        // .jpg derivatives move, a stale .jpeg derivative is left alone.
        $this->assertTrue(Storage::disk('fullsize')->exists('proofs/2023_R41/closing_ceremony/00042_thumb.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists('proofs/2023_R41/closing_ceremony/00042_big.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('proofs/2023_R41/closing_ceremony/00042_thumb.jpeg'));
        $this->assertTrue(Storage::disk('fullsize')->exists('proofs/2023_R41/opening_ceremony/00042_thumb.jpeg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('proofs/2023_R41/opening_ceremony/00042_thumb.jpg'));

        $this->assertTrue(Storage::disk('fullsize')->exists('web_images/2023_R41/closing_ceremony/00042_web2.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists('highres_images/2023_R41/closing_ceremony/00042_hr.jpg'));
    }

    private function sourceClass(): ShowClass
    {
        Show::withoutEvents(fn () => Show::create(['id' => '2023_R41', 'name' => '2023_R41']));

        return ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => '2023_R41_opening_ceremony',
            'show_id' => '2023_R41',
            'name' => 'opening_ceremony',
        ]));
    }

    private function makePhoto(array $overrides = [], ?ShowClass $class = null): Photo
    {
        $class ??= $this->sourceClass();

        return Photo::create(array_merge([
            'show_class_id' => $class->id,
            'proof_number' => '00042',
            'file_type' => 'jpeg',
        ], $overrides));
    }
}
