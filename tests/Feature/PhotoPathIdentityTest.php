<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Proofgen\Image;
use App\Services\PhotoArchiveService;
use App\Services\PhotoMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoPathIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true, 'proofgen.archive_enabled' => false]);
        Storage::fake('fullsize');
        config(['proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path('')]);
    }

    public function test_class_archive_and_move_paths_use_actual_show_and_class_fields(): void
    {
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
        $this->assertSame('SHOW_1/warm_up/originals/100.jpg', $photo->relative_path);
        $this->assertSame('/proofs/SHOW_1/warm_up', $photo->proofs_path);
        $this->assertSame('SHOW_1/warm_up/100.jpg', app(PhotoArchiveService::class)->pathForPhoto($photo));

        $result = app(PhotoMoveService::class)->movePhotos([$photo->id], $target->id);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['100'], $result['success']);
        $this->assertSame('original bytes', Storage::disk('fullsize')->get('SHOW_1/final/originals/100.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('SHOW_1/warm_up/originals/100.jpg'));
    }

    public function test_relation_is_authoritative_for_underscored_show_and_class_ids(): void
    {
        Show::withoutEvents(fn () => Show::create(['id' => '2023_R41', 'name' => 'Ferrara 2023']));
        $class = ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => '2023_R41_opening_ceremony',
            'show_id' => '2023_R41',
            'name' => 'opening_ceremony',
        ]));

        $photo = Photo::create([
            'show_class_id' => $class->id,
            'proof_number' => '00042',
            'file_type' => 'jpeg',
        ]);

        // A naive first-underscore split would produce "2023/R41_opening_ceremony".
        $this->assertSame('2023_R41/opening_ceremony/originals/00042.jpeg', $photo->relative_path);
        $this->assertSame('/proofs/2023_R41/opening_ceremony', $photo->proofs_path);
        $this->assertSame(
            Storage::disk('fullsize')->path('').'/2023_R41/opening_ceremony/originals/00042.jpeg',
            $photo->full_path
        );
    }

    public function test_explicit_import_context_resolves_paths_without_a_show_class_row(): void
    {
        $photo = new Photo;
        $photo->show_class_id = '2023_R41_opening_ceremony';
        $photo->proof_number = '00042';
        $photo->file_type = 'jpeg';
        $photo->setShowClassContext('2023_R41', 'opening_ceremony')->save();

        $this->assertNull(ShowClass::find('2023_R41_opening_ceremony'));
        $this->assertSame('2023_R41/opening_ceremony/originals/00042.jpeg', $photo->relative_path);
        $this->assertSame('/proofs/2023_R41/opening_ceremony', $photo->proofs_path);
    }

    public function test_direct_import_photo_result_paths_resolve_from_explicit_context(): void
    {
        // Mirrors the direct Image::processImage/importPhoto path: the Photo is
        // returned before any ShowClass row exists, so the caller supplies the
        // real show/class names instead of letting the model split the id.
        Storage::disk('fullsize')->makeDirectory('2023_R41/opening_ceremony/originals');
        $path = Storage::disk('fullsize')->path('2023_R41/opening_ceremony/originals/00042.jpeg');
        $image = imagecreatetruecolor(30, 20);
        imagejpeg($image, $path);
        config(['testing.skip_file_operations' => false]);
        $photo = Image::importPhoto('00042', 'jpeg', '2023_R41', 'opening_ceremony', sha1_file($path));
        $this->assertTrue($photo->metadata()->exists());

        $this->assertNull(ShowClass::find('2023_R41_opening_ceremony'));
        $this->assertSame('2023_R41/opening_ceremony/originals/00042.jpeg', $photo->relative_path);
        $this->assertSame('/proofs/2023_R41/opening_ceremony', $photo->proofs_path);
    }

    public function test_relation_takes_precedence_over_explicit_import_context(): void
    {
        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW_2', 'name' => 'Real show']));
        $class = ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => 'SHOW_2_class_name',
            'show_id' => 'SHOW_2',
            'name' => 'class_name',
        ]));

        $photo = new Photo;
        $photo->show_class_id = $class->id;
        $photo->proof_number = '00007';
        $photo->file_type = 'jpg';
        $photo->setShowClassContext('WRONG_SHOW', 'wrong_class')->save();

        $this->assertSame('SHOW_2/class_name/originals/00007.jpg', $photo->relative_path);
        $this->assertSame('/proofs/SHOW_2/class_name', $photo->proofs_path);
    }

    public function test_paths_throw_rather_than_guess_when_identity_is_unresolvable(): void
    {
        $photo = new Photo;
        $photo->show_class_id = 'SHOW_1_warm_up';
        $photo->proof_number = '100';
        $photo->file_type = 'jpg';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve show/class for photo');

        $photo->relative_path;
    }
}
