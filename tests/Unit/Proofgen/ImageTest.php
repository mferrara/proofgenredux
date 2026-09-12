<?php

namespace Tests\Unit\Proofgen;

use App\Proofgen\Image;
use App\Services\PathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageTest extends TestCase
{
    use RefreshDatabase;

    protected PathResolver $pathResolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Create fake storage disks for testing
        Storage::fake('fullsize');
        Storage::fake('archive');

        // Set up test configuration
        Config::set('proofgen.rename_files', true);
        Config::set('proofgen.fullsize_home_dir', '/test/fullsize');
        Config::set('proofgen.archive_home_dir', '/test/archive');
        Config::set('proofgen.archive_enabled', true);
        Config::set('testing.skip_file_operations', true);
        Config::set('filesystems.disks.fullsize.root', Storage::disk('fullsize')->path(''));
        Config::set('filesystems.disks.archive.root', Storage::disk('archive')->path(''));

        // Create path resolver instance for tests
        $this->pathResolver = new PathResolver;
    }

    public function test_proof_checks_use_exact_jpg_names_with_custom_suffixes(): void
    {
        Config::set('proofgen.thumbnails.small.suffix', '_small');
        Config::set('proofgen.thumbnails.large.suffix', '_large');
        Storage::disk('fullsize')->put('proofs/show123/class456/100_small_small.jpg.json', 'sidecar');
        $image = new Image('show123/class456/100_small.jpeg');
        $this->assertFalse($image->checkForProofs());
        $this->assertSame(['_small', '_large'], $image->missing_proofs);

        Storage::disk('fullsize')->put('proofs/show123/class456/100_small_small.jpg', 'small');
        Storage::disk('fullsize')->put('proofs/show123/class456/100_small_large.jpg', 'large');
        $this->assertTrue($image->checkForProofs());
        $this->assertSame([], $image->missing_proofs);
    }

    /**
     * Test image path parsing during construction
     */
    public function test_image_constructor_parses_path_correctly()
    {
        // Create an image with a test path
        $image = new Image('show123/class456/test_image.jpg', $this->pathResolver);

        // Check that the path components were parsed correctly
        $this->assertEquals('show123', $image->show);
        $this->assertEquals('class456', $image->class);
        $this->assertEquals('test_image.jpg', $image->filename);
        $this->assertFalse($image->is_original);

        // Test with an "originals" path
        $image = new Image('show123/class456/originals/test_image.jpg', $this->pathResolver);
        $this->assertEquals('show123', $image->show);
        $this->assertEquals('class456', $image->class);
        $this->assertEquals('test_image.jpg', $image->filename);
        $this->assertTrue($image->is_original);
    }

    /**
     * Test the processImage method
     */
    public function test_process_image_renames_and_moves_image()
    {
        // Create test image content
        $imageContent = 'test image content';

        // Set up the test image in storage
        $imagePath = 'testshow/testclass/test_image.jpg';
        Storage::disk('fullsize')->put($imagePath, $imageContent);

        // Create the Image object with PathResolver
        $image = new Image($imagePath, $this->pathResolver);

        // Process the image
        $photo = $image->processImage('PROOF123', false);

        // Original image should be deleted
        $this->assertFalse(Storage::disk('fullsize')->exists($imagePath));

        // Image should be moved to originals folder with new name
        $expectedOriginalPath = $this->pathResolver->normalizePath('testshow/testclass/originals/PROOF123.jpg');
        $this->assertEquals($expectedOriginalPath, $photo->relative_path);
        $this->assertTrue(Storage::disk('fullsize')->exists($expectedOriginalPath));

        // Image should be copied to archive
        $expectedArchivePath = $this->pathResolver->normalizePath('testshow/testclass/PROOF123.jpg');
        $this->assertTrue(Storage::disk('archive')->exists($expectedArchivePath));
        $this->assertSame(sha1($imageContent), $photo->sha1);
        $this->assertSame($expectedArchivePath, $photo->archive_path);
        $this->assertSame(sha1($imageContent), $photo->archive_sha1);
        $this->assertSame(strlen($imageContent), $photo->archive_size);

        // Content should be preserved
        $this->assertEquals($imageContent, Storage::disk('fullsize')->get($expectedOriginalPath));
        $this->assertEquals($imageContent, Storage::disk('archive')->get($expectedArchivePath));
    }

    /**
     * Test processImage without renaming (config option)
     */
    public function test_process_image_preserves_filename_when_renaming_disabled()
    {
        // Configure to not rename files
        Config::set('proofgen.rename_files', false);

        // Create test image content
        $imageContent = 'test image content';

        // Set up the test image in storage
        $imagePath = 'testshow/testclass/IMG_1234.jpg';
        Storage::disk('fullsize')->put($imagePath, $imageContent);

        // Create the Image object with PathResolver
        $image = new Image($imagePath, $this->pathResolver);

        // Process the image (proof number should be ignored)
        $photo = $image->processImage('PROOF123', false);

        // Original image should be deleted
        $this->assertFalse(Storage::disk('fullsize')->exists($imagePath));

        // Image should be moved to originals folder with original name
        $expectedOriginalPath = $this->pathResolver->normalizePath('testshow/testclass/originals/IMG_1234.jpg');
        $this->assertEquals($expectedOriginalPath, $photo->relative_path);
        $this->assertTrue(Storage::disk('fullsize')->exists($expectedOriginalPath));

        // Image should be copied to archive with original name
        $expectedArchivePath = $this->pathResolver->normalizePath('testshow/testclass/IMG_1234.jpg');
        $this->assertTrue(Storage::disk('archive')->exists($expectedArchivePath));
        $this->assertSame('IMG_1234', $photo->proof_number);
        $this->assertSame(sha1($imageContent), $photo->sha1);
        $this->assertSame($expectedArchivePath, $photo->archive_path);
    }
}
