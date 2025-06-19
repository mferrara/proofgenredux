<?php

namespace Tests\Unit\Proofgen;

use App\Proofgen\Show;
use App\Services\PathResolver;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class ShowTest extends TestCase
{
    protected string $show = 'testshow';

    protected function setUp(): void
    {
        parent::setUp();

        // Set up configuration
        Config::set('proofgen.fullsize_home_dir', '/test/fullsize');
        Config::set('proofgen.sftp.host', 'example.com');
        Config::set('proofgen.sftp.path', '/remote/proofs');
        Config::set('proofgen.sftp.web_images_path', '/remote/web_images');
        Config::set('proofgen.sftp.private_key', '/test/key');
    }

    /**
     * Test constructor sets up paths correctly using PathResolver
     */
    private function createMockPathResolver()
    {
        $pathResolver = Mockery::mock(PathResolver::class);
        $pathResolver->shouldReceive('getShowProofsPath')
            ->with($this->show)
            ->andReturn("/proofs/{$this->show}");

        $pathResolver->shouldReceive('getShowWebImagesPath')
            ->with($this->show)
            ->andReturn("/web_images/{$this->show}");

        $pathResolver->shouldReceive('getShowRemoteProofsPath')
            ->with($this->show)
            ->andReturn("/{$this->show}");

        $pathResolver->shouldReceive('getShowRemoteWebImagesPath')
            ->with($this->show)
            ->andReturn("/{$this->show}");

        // Add expectations for getAbsolutePath method
        $pathResolver->shouldReceive('getAbsolutePath')
            ->with("/proofs/{$this->show}", '/test/fullsize')
            ->andReturn('/test/fullsize/proofs/'.$this->show);

        $pathResolver->shouldReceive('getAbsolutePath')
            ->with("/web_images/{$this->show}", '/test/fullsize')
            ->andReturn('/test/fullsize/web_images/'.$this->show);

        // Add expectations for normalizePath method (for path concatenations)
        $pathResolver->shouldReceive('normalizePath')
            ->andReturnUsing(function ($path) {
                // Simple implementation that just ensures no double slashes
                return str_replace('//', '/', $path);
            });

        return $pathResolver;
    }

    public function test_constructor_uses_path_resolver()
    {
        $pathResolver = $this->createMockPathResolver();

        // Create the Show instance with the mock PathResolver
        $show = new Show($this->show, $pathResolver);

        // Test rsyncProofsCommand to verify paths are set correctly
        $command = $show->rsyncProofsCommand(true);

        // Verify command contains correct paths
        $this->assertStringContainsString("/test/fullsize/proofs/{$this->show}/", $command);
        $this->assertStringContainsString('--dry-run', $command);
        $this->assertStringContainsString(":/remote/proofs/{$this->show}", $command);

        // Test rsyncWebImagesCommand
        $webCommand = $show->rsyncWebImagesCommand(true);
        $this->assertStringContainsString("/test/fullsize/web_images/{$this->show}/", $webCommand);
        $this->assertStringContainsString(":/remote/web_images/{$this->show}", $webCommand);
    }

    public function test_path_manipulation_in_show_class()
    {
        // Create a simple PathResolver that can manipulate paths
        $pathResolver = new PathResolver;

        // Test the normalizePath method with a sample path
        $samplePath = '/proofs//testshow/class//image.jpg';
        $normalizedPath = $pathResolver->normalizePath($samplePath);

        // Should remove double slashes
        $this->assertEquals('proofs/testshow/class/image.jpg', $normalizedPath);

        // Test getAbsolutePath
        $absolutePath = $pathResolver->getAbsolutePath('/proofs/testshow', '/base/path');
        $this->assertEquals('/base/path/proofs/testshow', $absolutePath);

        // Test show-level path methods
        $proofsPath = $pathResolver->getProofsPath('testshow');
        $webImagesPath = $pathResolver->getWebImagesPath('testshow');

        $this->assertEquals('/proofs/testshow', $proofsPath);
        $this->assertEquals('/web_images/testshow', $webImagesPath);

        // Also check the specific show methods
        $showProofsPath = $pathResolver->getShowProofsPath('testshow');
        $showWebImagesPath = $pathResolver->getShowWebImagesPath('testshow');

        $this->assertEquals('/proofs/testshow', $showProofsPath);
        $this->assertEquals('/web_images/testshow', $showWebImagesPath);

        // Check remote path methods
        $remoteProofsPath = $pathResolver->getRemoteProofsPath('testshow');
        $remoteWebImagesPath = $pathResolver->getRemoteWebImagesPath('testshow');

        $this->assertEquals('/testshow', $remoteProofsPath);
        $this->assertEquals('/testshow', $remoteWebImagesPath);

        // Check show-level remote path methods
        $showRemoteProofsPath = $pathResolver->getShowRemoteProofsPath('testshow');
        $showRemoteWebImagesPath = $pathResolver->getShowRemoteWebImagesPath('testshow');

        $this->assertEquals('/testshow', $showRemoteProofsPath);
        $this->assertEquals('/testshow', $showRemoteWebImagesPath);
    }
}
