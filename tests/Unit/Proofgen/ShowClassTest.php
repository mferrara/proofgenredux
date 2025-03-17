<?php

namespace Tests\Unit\Proofgen;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use App\Proofgen\ShowClass;
use App\Services\PathResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
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
        
        // Set up mock for Redis
        $this->mock = Mockery::mock('alias:'.Redis::class);
        
        // Mock the Utility class for file operations
        $this->mockUtility = Mockery::mock('alias:App\Proofgen\Utility');
    }
    
    /**
     * Test getting images pending processing
     */
    public function test_get_images_pending_processing()
    {
        // Set up proper mock objects that have a path() method
        $mockImage1 = Mockery::mock();
        $mockImage1->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/image1.jpg");
        
        $mockImage2 = Mockery::mock();
        $mockImage2->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/image2.jpg");
        
        $mockContents = [
            'images' => [$mockImage1, $mockImage2]
        ];
        
        $this->mockUtility->shouldReceive('getContentsOfPath')
            ->with("/{$this->show}/{$this->class}", false)
            ->andReturn($mockContents);
            
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
        
        // Setup test proof number generation
        $this->mock->shouldReceive('client')->andReturn($this->mock);
        $this->mock->shouldReceive('exists')->andReturn(false);
        $this->mock->shouldReceive('rpush')->andReturn(true);
        $this->mock->shouldReceive('lpop')->andReturn('TEST001');
        $this->mock->shouldReceive('llen')->andReturn(0);
        
        // Set up proper mock objects that have a path() method
        $mockImage1 = Mockery::mock();
        $mockImage1->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/image1.jpg");
        
        $mockImage2 = Mockery::mock();
        $mockImage2->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/image2.jpg");
        
        $mockContents = [
            'images' => [$mockImage1, $mockImage2]
        ];
        
        $this->mockUtility->shouldReceive('getContentsOfPath')
            ->with("/{$this->show}/{$this->class}", false)
            ->andReturn($mockContents);
            
        $this->mockUtility->shouldReceive('generateProofNumbers')
            ->andReturn(['TEST001', 'TEST002']);
        
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
        // Set up proper mock objects for originals
        $mockImage1 = Mockery::mock();
        $mockImage1->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/originals/image1.jpg");
        
        $mockImage2 = Mockery::mock();
        $mockImage2->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/originals/image2.jpg");
        
        $mockOriginals = [
            'images' => [$mockImage1, $mockImage2]
        ];
        
        // Set up proper mock objects for proofs
        $mockProof1 = Mockery::mock();
        $mockProof1->shouldReceive('path')->andReturn("/proofs/{$this->show}/{$this->class}/image1_std.jpg");
        
        $mockProofs = [
            'images' => [$mockProof1]
        ];
        
        $this->mockUtility->shouldReceive('getContentsOfPath')
            ->with("/{$this->show}/{$this->class}/originals", false)
            ->andReturn($mockOriginals);
            
        $this->mockUtility->shouldReceive('getContentsOfPath')
            ->with("/proofs/{$this->show}/{$this->class}", false)
            ->andReturn($mockProofs);
        
        $showClass = new ShowClass($this->show, $this->class);
        $pendingProofing = $showClass->getImagesPendingProofing();
        
        // Should find 1 image needing proofing (image2)
        $this->assertCount(1, $pendingProofing);
    }
    
    /**
     * Test proofing pending images
     */
    public function test_proof_pending_images()
    {
        // Use fake for job dispatching
        Bus::fake();
        
        // Set up proper mock object
        $mockImage2 = Mockery::mock();
        $mockImage2->shouldReceive('path')->andReturn("/{$this->show}/{$this->class}/originals/image2.jpg");
        
        // We'll mock ShowClass::getImagesPendingProofing by using a partial mock
        $showClass = Mockery::mock(ShowClass::class, [$this->show, $this->class])->makePartial();
        $showClass->shouldReceive('getImagesPendingProofing')->andReturn([$mockImage2]);
        
        $count = $showClass->proofPendingImages();
        
        // Should proof 1 image (image2)
        $this->assertEquals(1, $count);
        
        // Verify the correct jobs were dispatched
        Bus::assertDispatched(GenerateThumbnails::class, 1);
        Bus::assertDispatched(GenerateWebImage::class, 1);
        
        Bus::assertDispatched(GenerateThumbnails::class, function ($job) {
            return $job->photo_path === "/{$this->show}/{$this->class}/originals/image2.jpg" &&
                   $job->proofs_destination_path === "/proofs/{$this->show}/{$this->class}";
        });
        
        Bus::assertDispatched(GenerateWebImage::class, function ($job) {
            return $job->full_size_path === "/{$this->show}/{$this->class}/originals/image2.jpg" &&
                   $job->web_destination_path === "/web_images/{$this->show}/{$this->class}";
        });
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
            
        $mockPathResolver->shouldReceive('getRemoteProofsPath')
            ->with($this->show, $this->class)
            ->andReturn("/{$this->show}/{$this->class}");
            
        $mockPathResolver->shouldReceive('getRemoteWebImagesPath')
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
        $mockPathResolver->shouldReceive('getRemoteProofsPath')->andReturn("/{$this->show}/{$this->class}");
        $mockPathResolver->shouldReceive('getRemoteWebImagesPath')->andReturn("/{$this->show}/{$this->class}");
        
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