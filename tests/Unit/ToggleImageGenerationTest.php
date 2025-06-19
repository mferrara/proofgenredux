<?php

namespace Tests\Unit;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateWebImage;
use App\Models\Configuration;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ToggleImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected ShowClass $showClass;

    protected Show $show;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip file operations during tests
        Config::set('testing.skip_file_operations', true);

        // Create test show and class
        $this->show = Show::create([
            'id' => '2023Test',
            'name' => '2023Test',
        ]);
        $this->showClass = ShowClass::create([
            'id' => '2023Test_101',
            'show_id' => '2023Test',
            'name' => '101',
        ]);
    }

    public function test_web_images_generation_respects_toggle_when_disabled()
    {
        Bus::fake();

        // Disable web images generation
        Config::set('proofgen.generate_web_images.enabled', false);

        // Create test photos directly
        $photos = collect();
        for ($i = 1; $i <= 3; $i++) {
            $photos->push(Photo::create([
                'id' => $this->showClass->id.'_TEST00'.$i,
                'show_class_id' => $this->showClass->id,
                'proof_number' => 'TEST00'.$i,
                'original_filename' => 'test_image_'.$i.'.jpg',
                'filename' => 'TEST00'.$i.'.jpg',
                'relative_path' => $this->show.'/'.$this->showClass->name.'/originals/TEST00'.$i.'.jpg',
                'file_type' => 'image/jpeg',
            ]));
        }

        // Try to queue web images
        $queued = $this->showClass->queueWebImageGeneration($photos);

        // Assert no jobs were queued
        $this->assertEquals(0, $queued);
        Bus::assertNotDispatched(GenerateWebImage::class);
    }

    public function test_web_images_generation_works_when_enabled()
    {
        Bus::fake();

        // Enable web images generation (default)
        Config::set('proofgen.generate_web_images.enabled', true);

        // Create test photos directly
        $photos = collect();
        for ($i = 1; $i <= 3; $i++) {
            $photos->push(Photo::create([
                'id' => $this->showClass->id.'_TEST00'.$i,
                'show_class_id' => $this->showClass->id,
                'proof_number' => 'TEST00'.$i,
                'original_filename' => 'test_image_'.$i.'.jpg',
                'filename' => 'TEST00'.$i.'.jpg',
                'relative_path' => $this->show.'/'.$this->showClass->name.'/originals/TEST00'.$i.'.jpg',
                'file_type' => 'image/jpeg',
            ]));
        }

        // Queue web images
        $queued = $this->showClass->queueWebImageGeneration($photos);

        // Assert jobs were queued
        $this->assertEquals(3, $queued);
        Bus::assertDispatchedTimes(GenerateWebImage::class, 3);
    }

    public function test_highres_images_generation_respects_toggle_when_disabled()
    {
        Bus::fake();

        // Disable highres images generation
        Config::set('proofgen.generate_highres_images.enabled', false);

        // Create test photos directly
        $photos = collect();
        for ($i = 1; $i <= 3; $i++) {
            $photos->push(Photo::create([
                'id' => $this->showClass->id.'_TEST00'.$i,
                'show_class_id' => $this->showClass->id,
                'proof_number' => 'TEST00'.$i,
                'original_filename' => 'test_image_'.$i.'.jpg',
                'filename' => 'TEST00'.$i.'.jpg',
                'relative_path' => $this->show.'/'.$this->showClass->name.'/originals/TEST00'.$i.'.jpg',
                'file_type' => 'image/jpeg',
            ]));
        }

        // Try to queue highres images
        $queued = $this->showClass->queueHighresImageGeneration($photos);

        // Assert no jobs were queued
        $this->assertEquals(0, $queued);
        Bus::assertNotDispatched(GenerateHighresImage::class);
    }

    public function test_highres_images_generation_works_when_enabled()
    {
        Bus::fake();

        // Enable highres images generation (default)
        Config::set('proofgen.generate_highres_images.enabled', true);

        // Create test photos directly
        $photos = collect();
        for ($i = 1; $i <= 3; $i++) {
            $photos->push(Photo::create([
                'id' => $this->showClass->id.'_TEST00'.$i,
                'show_class_id' => $this->showClass->id,
                'proof_number' => 'TEST00'.$i,
                'original_filename' => 'test_image_'.$i.'.jpg',
                'filename' => 'TEST00'.$i.'.jpg',
                'relative_path' => $this->show.'/'.$this->showClass->name.'/originals/TEST00'.$i.'.jpg',
                'file_type' => 'image/jpeg',
            ]));
        }

        // Queue highres images
        $queued = $this->showClass->queueHighresImageGeneration($photos);

        // Assert jobs were queued
        $this->assertEquals(3, $queued);
        Bus::assertDispatchedTimes(GenerateHighresImage::class, 3);
    }

    public function test_configuration_toggles_are_created_by_migration()
    {
        // Run the migration
        $migration = include database_path('migrations/2025_06_19_221039_add_image_generation_toggles.php');
        $migration->up();

        // Check that configurations exist
        $webImagesConfig = Configuration::where('key', 'generate_web_images.enabled')->first();
        $highresImagesConfig = Configuration::where('key', 'generate_highres_images.enabled')->first();

        $this->assertNotNull($webImagesConfig);
        $this->assertEquals('boolean', $webImagesConfig->type);
        $this->assertEquals('web_images', $webImagesConfig->category);

        $this->assertNotNull($highresImagesConfig);
        $this->assertEquals('boolean', $highresImagesConfig->type);
        $this->assertEquals('highres_images', $highresImagesConfig->category);
    }

    public function test_default_values_are_true_when_not_configured()
    {
        Bus::fake();

        // Don't set any config for these keys - they should default to true
        // The config array won't have these keys at all
        Config::offsetUnset('proofgen.generate_web_images');
        Config::offsetUnset('proofgen.generate_highres_images');

        // Create test photo directly
        $photos = collect([
            Photo::create([
                'id' => $this->showClass->id.'_TEST001',
                'show_class_id' => $this->showClass->id,
                'proof_number' => 'TEST001',
                'original_filename' => 'test_image.jpg',
                'filename' => 'TEST001.jpg',
                'relative_path' => $this->show.'/'.$this->showClass->name.'/originals/TEST001.jpg',
                'file_type' => 'image/jpeg',
            ]),
        ]);

        // Both should default to enabled (true)
        $webQueued = $this->showClass->queueWebImageGeneration($photos);
        $highresQueued = $this->showClass->queueHighresImageGeneration($photos);

        $this->assertEquals(1, $webQueued);
        $this->assertEquals(1, $highresQueued);
        Bus::assertDispatched(GenerateWebImage::class);
        Bus::assertDispatched(GenerateHighresImage::class);
    }

    public function test_env_configuration_works_correctly()
    {
        Bus::fake();

        // Create test photo directly
        $photos = collect([
            Photo::create([
                'id' => $this->showClass->id.'_TEST001',
                'show_class_id' => $this->showClass->id,
                'proof_number' => 'TEST001',
                'original_filename' => 'test_image.jpg',
                'filename' => 'TEST001.jpg',
                'relative_path' => $this->show.'/'.$this->showClass->name.'/originals/TEST001.jpg',
                'file_type' => 'image/jpeg',
            ]),
        ]);

        // Test FALSE value
        putenv('GENERATE_WEB_IMAGES_ENABLED=FALSE');
        Config::set('proofgen.generate_web_images.enabled', getenv('GENERATE_WEB_IMAGES_ENABLED') !== 'FALSE');

        $queued = $this->showClass->queueWebImageGeneration($photos);
        $this->assertEquals(0, $queued);

        // Test TRUE value
        putenv('GENERATE_WEB_IMAGES_ENABLED=TRUE');
        Config::set('proofgen.generate_web_images.enabled', getenv('GENERATE_WEB_IMAGES_ENABLED') !== 'FALSE');

        $queued = $this->showClass->queueWebImageGeneration($photos);
        $this->assertEquals(1, $queued);

        // Clean up env
        putenv('GENERATE_WEB_IMAGES_ENABLED');
    }
}
