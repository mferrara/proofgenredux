<?php

namespace Tests\Unit\Proofgen;

use App\Proofgen\Image;
use App\Services\PathResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageTest extends TestCase
{
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
        
        // Create path resolver instance for tests
        $this->pathResolver = new PathResolver();
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
        $processedPath = $image->processImage('PROOF123', false);
        
        // Original image should be deleted
        $this->assertFalse(Storage::disk('fullsize')->exists($imagePath));
        
        // Image should be moved to originals folder with new name
        $expectedOriginalPath = $this->pathResolver->normalizePath('testshow/testclass/originals/PROOF123.jpg');
        $this->assertEquals($expectedOriginalPath, $processedPath);
        $this->assertTrue(Storage::disk('fullsize')->exists($expectedOriginalPath));
        
        // Image should be copied to archive
        $expectedArchivePath = $this->pathResolver->normalizePath('testshow/testclass/PROOF123.jpg');
        $this->assertTrue(Storage::disk('archive')->exists($expectedArchivePath));
        
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
        $processedPath = $image->processImage('PROOF123', false);
        
        // Original image should be deleted
        $this->assertFalse(Storage::disk('fullsize')->exists($imagePath));
        
        // Image should be moved to originals folder with original name
        $expectedOriginalPath = $this->pathResolver->normalizePath('testshow/testclass/originals/IMG_1234.jpg');
        $this->assertEquals($expectedOriginalPath, $processedPath);
        $this->assertTrue(Storage::disk('fullsize')->exists($expectedOriginalPath));
        
        // Image should be copied to archive with original name
        $expectedArchivePath = $this->pathResolver->normalizePath('testshow/testclass/IMG_1234.jpg');
        $this->assertTrue(Storage::disk('archive')->exists($expectedArchivePath));
    }
}