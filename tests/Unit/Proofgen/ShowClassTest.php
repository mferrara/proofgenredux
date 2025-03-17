<?php

namespace Tests\Unit\Proofgen;

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\Photo\ImportPhoto;
use App\Proofgen\ShowClass;
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
}