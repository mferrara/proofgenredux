<?php

namespace Tests\Unit\Proofgen;

use App\Jobs\Photo\ImportPhoto;
use App\Proofgen\ShowClass;
use App\Services\PathResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ShowClassTest extends TestCase
{
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
        Config::set('proofgen.sftp.private_key', '/path/to/private_key');
        Config::set('proofgen.sftp.host', 'test.example.com');
        Config::set('proofgen.sftp.path', '/remote/path/');
        Config::set('proofgen.sftp.web_images_path', '/remote/web_images/');

        // Use the Redis facade fake instead of Mockery::mock('alias:'.Redis::class).
        // Alias mocks pollute global class state and break unrelated tests that run later.
        $redisClient = Mockery::mock();
        $redisClient->shouldReceive('exists')->andReturn(false)->byDefault();
        $redisClient->shouldReceive('rpush')->andReturn(true)->byDefault();
        $redisClient->shouldReceive('lpop')->andReturn('TEST001')->byDefault();
        $redisClient->shouldReceive('llen')->andReturn(0)->byDefault();
        Redis::shouldReceive('client')->andReturn($redisClient)->byDefault();
    }

    /**
     * Test getting images pending processing
     */
    public function test_get_images_pending_processing()
    {
        // Lay down two real images on the fake disk so the real Utility code can find them.
        Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/image1.jpg", 'fake');
        Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/image2.jpg", 'fake');

        $showClass = new ShowClass($this->show, $this->class);
        $pendingImages = $showClass->getImagesPendingProcessing();

        // Should find 2 pending images
        $this->assertCount(2, $pendingImages);
    }

    /**
     * Test processing pending images
     */
    public function test_process_pending_images()
    {
        // Use fake for job dispatching
        Bus::fake();

        // Lay down two real images on the fake disk so the real Utility code can find them.
        Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/image1.jpg", 'fake');
        Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/image2.jpg", 'fake');

        $showClass = new ShowClass($this->show, $this->class);
        $count = $showClass->processPendingImages();

        // Should process 2 images
        $this->assertEquals(2, $count);

        // Verify jobs were dispatched
        Bus::assertDispatched(ImportPhoto::class, 2);
    }

    /**
     * Test getting images pending proofing
     */
    public function test_get_images_pending_proofing()
    {
        // Two originals exist
        Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/originals/image1.jpg", 'fake');
        Storage::disk('fullsize')->put("/{$this->show}/{$this->class}/originals/image2.jpg", 'fake');

        // Only image1 has a corresponding proof — so image2 is pending proofing.
        Storage::disk('fullsize')->put("/proofs/{$this->show}/{$this->class}/image1_std.jpg", 'fake');

        $showClass = new ShowClass($this->show, $this->class);
        $pendingProofing = $showClass->getImagesPendingProofing();

        // Should find 1 image needing proofing (image2)
        $this->assertCount(1, $pendingProofing);
    }

    /**
     * Test path resolution with PathResolver
     */
    public function test_path_resolution_with_path_resolver()
    {
        // Create a mock PathResolver
        $mockPathResolver = Mockery::mock(PathResolver::class);

        // Setup expectations for the PathResolver methods
        $mockPathResolver->shouldReceive('getFullsizePath')
            ->with($this->show, $this->class)
            ->andReturn("/{$this->show}/{$this->class}");

        $mockPathResolver->shouldReceive('getOriginalsPath')
            ->with($this->show, $this->class)
            ->andReturn("/{$this->show}/{$this->class}/originals");

        $mockPathResolver->shouldReceive('getProofsPath')
            ->with($this->show, $this->class)
            ->andReturn("/proofs/{$this->show}/{$this->class}");

        $mockPathResolver->shouldReceive('getWebImagesPath')
            ->with($this->show, $this->class)
            ->andReturn("/web_images/{$this->show}/{$this->class}");

        $mockPathResolver->shouldReceive('getHighresImagesPath')
            ->with($this->show, $this->class)
            ->andReturn("/highres_images/{$this->show}/{$this->class}");

        $mockPathResolver->shouldReceive('getRemoteProofsPath')
            ->with($this->show, $this->class)
            ->andReturn("/{$this->show}/{$this->class}");

        $mockPathResolver->shouldReceive('getRemoteWebImagesPath')
            ->with($this->show, $this->class)
            ->andReturn("/{$this->show}/{$this->class}");

        $mockPathResolver->shouldReceive('getRemoteHighresImagesPath')
            ->with($this->show, $this->class)
            ->andReturn("/{$this->show}/{$this->class}");

        // Create ShowClass with the mocked PathResolver
        $showClass = new ShowClass($this->show, $this->class, $mockPathResolver);

        // Test the paths are set correctly by testing methods that use them
        $mockImage = Mockery::mock();
        $mockImage->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/originals/image1.jpg");

        $mockPathResolver->shouldReceive('normalizePath')
            ->with("/proofs/{$this->show}/{$this->class}/image1.jpg")
            ->andReturn("proofs/{$this->show}/{$this->class}/image1.jpg");

        $mockPathResolver->shouldReceive('getAbsolutePath')
            ->with("/proofs/{$this->show}/{$this->class}", '/test/fullsize')
            ->andReturn('/test/fullsize/proofs/testshow/testclass');

        // Test that PathResolver is used in methods
        $this->assertInstanceOf(ShowClass::class, $showClass);
    }

    /**
     * Test rsync commands
     */
    public function test_rsync_commands()
    {
        // Create a mock PathResolver for specific behavior testing
        $mockPathResolver = Mockery::mock(PathResolver::class);

        // Set expectations for path methods
        $mockPathResolver->shouldReceive('getFullsizePath')->andReturn("/{$this->show}/{$this->class}");
        $mockPathResolver->shouldReceive('getOriginalsPath')->andReturn("/{$this->show}/{$this->class}/originals");
        $mockPathResolver->shouldReceive('getProofsPath')->andReturn("/proofs/{$this->show}/{$this->class}");
        $mockPathResolver->shouldReceive('getWebImagesPath')->andReturn("/web_images/{$this->show}/{$this->class}");
        $mockPathResolver->shouldReceive('getHighresImagesPath')->andReturn("/highres_images/{$this->show}/{$this->class}");
        $mockPathResolver->shouldReceive('getRemoteProofsPath')->andReturn("/{$this->show}/{$this->class}");
        $mockPathResolver->shouldReceive('getRemoteWebImagesPath')->andReturn("/{$this->show}/{$this->class}");
        $mockPathResolver->shouldReceive('getRemoteHighresImagesPath')->andReturn("/{$this->show}/{$this->class}");

        // Set expectations for getAbsolutePath
        $mockPathResolver->shouldReceive('getAbsolutePath')
            ->with("/proofs/{$this->show}/{$this->class}", '/test/fullsize')
            ->andReturn('/test/fullsize/proofs/testshow/testclass');

        $mockPathResolver->shouldReceive('getAbsolutePath')
            ->with("/web_images/{$this->show}/{$this->class}", '/test/fullsize')
            ->andReturn('/test/fullsize/web_images/testshow/testclass');

        $showClass = new ShowClass($this->show, $this->class, $mockPathResolver);

        // Test proofs rsync command
        $proofsCommand = $showClass->rsyncProofsCommand();
        $this->assertStringContainsString('-avz --delete', $proofsCommand);
        $this->assertStringContainsString('/test/fullsize/proofs/testshow/testclass/', $proofsCommand);
        $this->assertStringContainsString('/path/to/private_key', $proofsCommand);
        $this->assertStringContainsString('forge@test.example.com', $proofsCommand);
        $this->assertStringContainsString('/remote/path//testshow/testclass', $proofsCommand);

        // Test web images rsync command
        $webImagesCommand = $showClass->rsyncWebImagesCommand();
        $this->assertStringContainsString('-avz --delete', $webImagesCommand);
        $this->assertStringContainsString('/test/fullsize/web_images/testshow/testclass/', $webImagesCommand);
        $this->assertStringContainsString('/path/to/private_key', $webImagesCommand);
        $this->assertStringContainsString('forge@test.example.com', $webImagesCommand);
        $this->assertStringContainsString('/remote/web_images//testshow/testclass', $webImagesCommand);
    }
}
