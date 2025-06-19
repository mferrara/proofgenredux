<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PathResolver;
use App\Services\PhotoMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PhotoMoveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PhotoMoveService $photoMoveService;

    protected PathResolver $pathResolver;

    protected string $tempPath;

    protected Show $show;

    protected ShowClass $sourceClass;

    protected ShowClass $targetClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->photoMoveService = app(PhotoMoveService::class);
        $this->pathResolver = app(PathResolver::class);

        // Create temp directory
        $this->tempPath = storage_path('app/test_photo_move_'.uniqid());
        File::makeDirectory($this->tempPath, 0755, true);

        // Configure test disk
        config(['proofgen.fullsize_home_dir' => $this->tempPath]);

        // Create test show and classes
        $this->show = Show::create([
            'id' => 'TestShow2024',
            'name' => 'Test Show 2024',
        ]);

        $this->sourceClass = ShowClass::create([
            'id' => 'TestShow2024_ClassA',
            'show_id' => 'TestShow2024',
            'name' => 'ClassA',
        ]);

        $this->targetClass = ShowClass::create([
            'id' => 'TestShow2024_ClassB',
            'show_id' => 'TestShow2024',
            'name' => 'ClassB',
        ]);

        // Create directory structure
        $this->createDirectoryStructure();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    protected function createDirectoryStructure(): void
    {
        // Create originals directories
        File::makeDirectory($this->tempPath.'/TestShow2024/ClassA/originals', 0755, true);
        File::makeDirectory($this->tempPath.'/TestShow2024/ClassB/originals', 0755, true);

        // Create proofs directories
        File::makeDirectory($this->tempPath.'/proofs/TestShow2024/ClassA', 0755, true);
        File::makeDirectory($this->tempPath.'/proofs/TestShow2024/ClassB', 0755, true);

        // Create web_images directories
        File::makeDirectory($this->tempPath.'/web_images/TestShow2024/ClassA', 0755, true);
        File::makeDirectory($this->tempPath.'/web_images/TestShow2024/ClassB', 0755, true);

        // Create highres_images directories
        File::makeDirectory($this->tempPath.'/highres_images/TestShow2024/ClassA', 0755, true);
        File::makeDirectory($this->tempPath.'/highres_images/TestShow2024/ClassB', 0755, true);
    }

    protected function createTestImage(string $path, string $content = 'test image'): void
    {
        // Create a simple valid JPEG image
        $image = imagecreatetruecolor(100, 100);
        $color = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $color);

        // Add text
        $textColor = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 10, 40, $content, $textColor);

        // Save as JPEG
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    protected function createPhotoWithAllFiles(string $proofNumber = '12345'): Photo
    {
        // Create original file FIRST (before photo record)
        $this->createTestImage(
            $this->tempPath.'/TestShow2024/ClassA/originals/'.$proofNumber.'.jpg',
            'original image '.$proofNumber
        );

        // Create photo record
        $photo = Photo::create([
            'id' => 'TestShow2024_ClassA_'.$proofNumber,
            'proof_number' => $proofNumber,
            'show_class_id' => 'TestShow2024_ClassA',
            'sha1' => sha1($proofNumber),
            'file_type' => 'jpg',
            'proofs_generated_at' => now(),
            'proofs_uploaded_at' => now(),
            'web_image_generated_at' => now(),
            'web_image_uploaded_at' => now(),
            'highres_image_generated_at' => now(),
            'highres_image_uploaded_at' => now(),
        ]);

        // Create proof files (based on config)
        $thumbnailSizes = config('proofgen.thumbnails', [
            'small' => ['suffix' => '_thm'],
            'large' => ['suffix' => '_std'],
        ]);

        foreach ($thumbnailSizes as $size => $config) {
            $this->createTestImage(
                $this->tempPath.'/proofs/TestShow2024/ClassA/'.$proofNumber.$config['suffix'].'.jpg',
                'proof '.$size.' '.$proofNumber
            );
        }

        // Create web image
        $this->createTestImage(
            $this->tempPath.'/web_images/TestShow2024/ClassA/'.$proofNumber.'_web.jpg',
            'web image '.$proofNumber
        );

        // Create highres image
        $this->createTestImage(
            $this->tempPath.'/highres_images/TestShow2024/ClassA/'.$proofNumber.'_highres.jpg',
            'highres image '.$proofNumber
        );

        return $photo;
    }

    public function test_moves_photo_with_all_files_successfully()
    {
        // Arrange
        $photo = $this->createPhotoWithAllFiles('12345');

        // Act
        $results = $this->photoMoveService->movePhotos([$photo->id], $this->targetClass->id);

        // Assert
        $this->assertCount(1, $results['success']);
        $this->assertCount(0, $results['errors']);
        $this->assertContains('12345', $results['success']);

        // Verify new photo exists
        $newPhotoId = 'TestShow2024_ClassB_12345';
        $newPhoto = Photo::find($newPhotoId);
        $this->assertNotNull($newPhoto);
        $this->assertEquals('12345', $newPhoto->proof_number);
        $this->assertEquals('TestShow2024_ClassB', $newPhoto->show_class_id);

        // Verify old photo doesn't exist
        $this->assertNull(Photo::find($photo->id));

        // Verify files were moved
        // Original
        $this->assertFileDoesNotExist($this->tempPath.'/TestShow2024/ClassA/originals/12345.jpg');
        $this->assertFileExists($this->tempPath.'/TestShow2024/ClassB/originals/12345.jpg');

        // Proofs
        $this->assertFileDoesNotExist($this->tempPath.'/proofs/TestShow2024/ClassA/12345_thm.jpg');
        $this->assertFileExists($this->tempPath.'/proofs/TestShow2024/ClassB/12345_thm.jpg');

        // Web image
        $this->assertFileDoesNotExist($this->tempPath.'/web_images/TestShow2024/ClassA/12345_web.jpg');
        $this->assertFileExists($this->tempPath.'/web_images/TestShow2024/ClassB/12345_web.jpg');

        // Highres image
        $this->assertFileDoesNotExist($this->tempPath.'/highres_images/TestShow2024/ClassA/12345_highres.jpg');
        $this->assertFileExists($this->tempPath.'/highres_images/TestShow2024/ClassB/12345_highres.jpg');

        // Verify timestamps were preserved
        $this->assertNotNull($newPhoto->proofs_generated_at);
        $this->assertNotNull($newPhoto->proofs_uploaded_at);
        $this->assertNotNull($newPhoto->web_image_generated_at);
        $this->assertNotNull($newPhoto->web_image_uploaded_at);
        $this->assertNotNull($newPhoto->highres_image_generated_at);
        $this->assertNotNull($newPhoto->highres_image_uploaded_at);
    }

    public function test_moves_photo_with_partial_files()
    {
        // Create only original and proof files FIRST
        $this->createTestImage(
            $this->tempPath.'/TestShow2024/ClassA/originals/54321.jpg',
            'original image 54321'
        );

        // Arrange - Create photo with only original and proofs
        $photo = Photo::create([
            'id' => 'TestShow2024_ClassA_54321',
            'proof_number' => '54321',
            'show_class_id' => 'TestShow2024_ClassA',
            'sha1' => sha1('54321'),
            'file_type' => 'jpg',
            'proofs_generated_at' => now(),
            'proofs_uploaded_at' => now(),
        ]);
        $this->createTestImage(
            $this->tempPath.'/proofs/TestShow2024/ClassA/54321_thm.jpg',
            'proof thumbnail 54321'
        );

        // Act
        $results = $this->photoMoveService->movePhotos([$photo->id], $this->targetClass->id);

        // Assert
        $this->assertCount(1, $results['success']);
        $this->assertCount(0, $results['errors']);

        // Verify files were moved
        $this->assertFileDoesNotExist($this->tempPath.'/TestShow2024/ClassA/originals/54321.jpg');
        $this->assertFileExists($this->tempPath.'/TestShow2024/ClassB/originals/54321.jpg');
        $this->assertFileDoesNotExist($this->tempPath.'/proofs/TestShow2024/ClassA/54321_thm.jpg');
        $this->assertFileExists($this->tempPath.'/proofs/TestShow2024/ClassB/54321_thm.jpg');
    }

    public function test_fails_when_photo_not_found()
    {
        // Act
        $results = $this->photoMoveService->movePhotos(['NonExistentId'], $this->targetClass->id);

        // Assert
        $this->assertCount(0, $results['success']);
        $this->assertCount(1, $results['errors']);
        $this->assertArrayHasKey('NonExistentId', $results['errors']);
        $this->assertStringContainsString('Photo not found', $results['errors']['NonExistentId']);
    }

    public function test_fails_when_target_class_not_found()
    {
        // Arrange
        $photo = $this->createPhotoWithAllFiles('99999');

        // Act
        $results = $this->photoMoveService->movePhotos([$photo->id], 'NonExistentClass');

        // Assert
        $this->assertCount(0, $results['success']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('Target class not found', $results['errors'][$photo->id]);
    }

    public function test_fails_when_proof_number_exists_in_target_class()
    {
        // Arrange
        $photo1 = $this->createPhotoWithAllFiles('11111');

        // Create a photo with same proof number in target class (with file first)
        $this->createTestImage(
            $this->tempPath.'/TestShow2024/ClassB/originals/11111.jpg',
            'conflict image 11111'
        );

        Photo::create([
            'id' => 'TestShow2024_ClassB_11111',
            'proof_number' => '11111',
            'show_class_id' => 'TestShow2024_ClassB',
            'sha1' => sha1('11111-target'),
            'file_type' => 'jpg',
        ]);

        // Act
        $results = $this->photoMoveService->movePhotos([$photo1->id], $this->targetClass->id);

        // Assert
        $this->assertCount(0, $results['success']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('already exists in target class', $results['errors'][$photo1->id]);
    }

    public function test_moves_multiple_photos_with_partial_failures()
    {
        // Arrange
        $photo1 = $this->createPhotoWithAllFiles('22222');
        $photo2 = $this->createPhotoWithAllFiles('33333');

        // Create conflict for photo2 (with file first)
        $this->createTestImage(
            $this->tempPath.'/TestShow2024/ClassB/originals/33333.jpg',
            'conflict image 33333'
        );

        Photo::create([
            'id' => 'TestShow2024_ClassB_33333',
            'proof_number' => '33333',
            'show_class_id' => 'TestShow2024_ClassB',
            'sha1' => sha1('33333-conflict'),
            'file_type' => 'jpg',
        ]);

        // Act
        $results = $this->photoMoveService->movePhotos([$photo1->id, $photo2->id], $this->targetClass->id);

        // Assert
        $this->assertCount(1, $results['success']);
        $this->assertCount(1, $results['errors']);
        $this->assertContains('22222', $results['success']);
        $this->assertArrayHasKey($photo2->id, $results['errors']);

        // Verify photo1 was moved
        $this->assertNotNull(Photo::find('TestShow2024_ClassB_22222'));
        $this->assertNull(Photo::find($photo1->id));

        // Verify photo2 was not moved
        $this->assertNotNull(Photo::find($photo2->id));
    }

    public function test_creates_missing_directories_during_move()
    {
        // Arrange
        $photo = $this->createPhotoWithAllFiles('44444');

        // Remove target directories
        File::deleteDirectory($this->tempPath.'/TestShow2024/ClassB');
        File::deleteDirectory($this->tempPath.'/proofs/TestShow2024/ClassB');
        File::deleteDirectory($this->tempPath.'/web_images/TestShow2024/ClassB');
        File::deleteDirectory($this->tempPath.'/highres_images/TestShow2024/ClassB');

        // Act
        $results = $this->photoMoveService->movePhotos([$photo->id], $this->targetClass->id);

        // Assert
        $this->assertCount(1, $results['success']);

        // Verify directories were created and files moved
        $this->assertFileExists($this->tempPath.'/TestShow2024/ClassB/originals/44444.jpg');
        $this->assertFileExists($this->tempPath.'/proofs/TestShow2024/ClassB/44444_thm.jpg');
        $this->assertFileExists($this->tempPath.'/web_images/TestShow2024/ClassB/44444_web.jpg');
        $this->assertFileExists($this->tempPath.'/highres_images/TestShow2024/ClassB/44444_highres.jpg');
    }
}
