<?php

namespace Tests\Feature;

use App\Jobs\Photo\ImportPhoto;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Jobs\ShowClass\UploadProofs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ImageProcessingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected string $show = 'testshow';

    protected string $class = 'testclass';

    protected function setUp(): void
    {
        parent::setUp();

        // Create fake storage disks
        Storage::fake('fullsize');
        Storage::fake('archive');
        Storage::fake('remote_proofs');
        Storage::fake('remote_web_images');

        // Set up configuration
        Config::set('proofgen.fullsize_home_dir', '/test/fullsize');
        Config::set('proofgen.archive_home_dir', '/test/archive');
        Config::set('proofgen.rename_files', true);
        Config::set('proofgen.sftp.host', 'test.example.com');
        Config::set('proofgen.sftp.path', '/remote/proofs');
        Config::set('proofgen.sftp.web_images_path', '/remote/web_images');
        Config::set('proofgen.sftp.private_key', '/path/to/private_key');

        // Set up directories
        Storage::disk('fullsize')->makeDirectory("/{$this->show}/{$this->class}");
        Storage::disk('fullsize')->makeDirectory("/proofs/{$this->show}/{$this->class}");
        Storage::disk('fullsize')->makeDirectory("/web_images/{$this->show}/{$this->class}");

        // Mock Redis for proof numbers
        $this->mock = Mockery::mock('alias:'.Redis::class);
        $this->mock->shouldReceive('client')->andReturn($this->mock);
        $this->mock->shouldReceive('exists')->andReturn(false);
        $this->mock->shouldReceive('rpush')->andReturn(true);
        $this->mock->shouldReceive('lpop')->andReturn('TEST001');
        $this->mock->shouldReceive('llen')->andReturn(0);
    }

    /**
     * Test the full image processing workflow with job dispatching
     */
    public function test_image_processing_workflow()
    {
        // Use fake for job dispatching
        Bus::fake();

        // Create a mock for Utility class
        $mockUtility = Mockery::mock('alias:App\Proofgen\Utility');

        // Set up proper mock objects
        $mockImage1 = Mockery::mock();
        $mockImage1->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/image1.jpg");

        $mockImage2 = Mockery::mock();
        $mockImage2->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/image2.jpg");

        $mockContents = [
            'images' => [$mockImage1, $mockImage2],
        ];

        $mockUtility->shouldReceive('getContentsOfPath')
            ->andReturn($mockContents);

        $mockUtility->shouldReceive('generateProofNumbers')
            ->andReturn(['TEST001', 'TEST002']);

        // Create test images for the ImportPhoto job
        Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/image1.jpg", 'test content');

        // 1. Dispatch ImportPhotos job directly
        ImportClassPhotos::dispatch($this->show, $this->class)->onQueue('imports');

        // Verify ImportPhotos job was dispatched
        Bus::assertDispatched(ImportClassPhotos::class, function ($job) {
            return $job->show === $this->show && $job->class === $this->class;
        });

        // 2. Dispatch UploadProofs job directly
        UploadProofs::dispatch($this->show, $this->class);

        // Verify that UploadProofs job was dispatched via Bus
        Bus::assertDispatched(UploadProofs::class, function ($job) {
            return $job->show === $this->show && $job->class === $this->class;
        });
    }
}
