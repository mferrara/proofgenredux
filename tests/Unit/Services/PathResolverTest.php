<?php

use App\Services\PathResolver;
use Tests\TestCase;

class PathResolverTest extends TestCase
{
    protected PathResolver $pathResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pathResolver = new PathResolver;
    }

    /** @test */
    public function it_returns_fullsize_path()
    {
        $path = $this->pathResolver->getFullsizePath('2023R41', '121');
        $this->assertEquals('/2023R41/121', $path);
    }

    /** @test */
    public function it_returns_originals_path()
    {
        $path = $this->pathResolver->getOriginalsPath('2023R41', '121');
        $this->assertEquals('/2023R41/121/originals', $path);
    }

    /** @test */
    public function it_returns_proofs_path_with_class()
    {
        $path = $this->pathResolver->getProofsPath('2023R41', '121');
        $this->assertEquals('/proofs/2023R41/121', $path);
    }

    /** @test */
    public function it_returns_proofs_path_without_class()
    {
        $path = $this->pathResolver->getProofsPath('2023R41');
        $this->assertEquals('/proofs/2023R41', $path);
    }

    /** @test */
    public function it_returns_web_images_path_with_class()
    {
        $path = $this->pathResolver->getWebImagesPath('2023R41', '121');
        $this->assertEquals('/web_images/2023R41/121', $path);
    }

    /** @test */
    public function it_returns_web_images_path_without_class()
    {
        $path = $this->pathResolver->getWebImagesPath('2023R41');
        $this->assertEquals('/web_images/2023R41', $path);
    }

    /** @test */
    public function it_returns_remote_proofs_path_with_class()
    {
        $path = $this->pathResolver->getRemoteProofsPath('2023R41', '121');
        $this->assertEquals('/2023R41/121', $path);
    }

    /** @test */
    public function it_returns_remote_proofs_path_without_class()
    {
        $path = $this->pathResolver->getRemoteProofsPath('2023R41');
        $this->assertEquals('/2023R41', $path);
    }

    /** @test */
    public function it_returns_remote_web_images_path_with_class()
    {
        $path = $this->pathResolver->getRemoteWebImagesPath('2023R41', '121');
        $this->assertEquals('/2023R41/121', $path);
    }

    /** @test */
    public function it_returns_remote_web_images_path_without_class()
    {
        $path = $this->pathResolver->getRemoteWebImagesPath('2023R41');
        $this->assertEquals('/2023R41', $path);
    }

    /** @test */
    public function it_returns_show_level_paths()
    {
        $showProofsPath = $this->pathResolver->getShowProofsPath('2023R41');
        $showWebImagesPath = $this->pathResolver->getShowWebImagesPath('2023R41');
        $showRemoteProofsPath = $this->pathResolver->getShowRemoteProofsPath('2023R41');
        $showRemoteWebImagesPath = $this->pathResolver->getShowRemoteWebImagesPath('2023R41');

        $this->assertEquals('/proofs/2023R41', $showProofsPath);
        $this->assertEquals('/web_images/2023R41', $showWebImagesPath);
        $this->assertEquals('/2023R41', $showRemoteProofsPath);
        $this->assertEquals('/2023R41', $showRemoteWebImagesPath);
    }

    /** @test */
    public function it_normalizes_paths()
    {
        $paths = [
            '/path//with/double//slashes' => 'path/with/double/slashes',
            '//leading/double/slash' => 'leading/double/slash',
            '/leading/single/slash/' => 'leading/single/slash/',
            'no/leading/slash' => 'no/leading/slash',
        ];

        foreach ($paths as $input => $expected) {
            $this->assertEquals($expected, $this->pathResolver->normalizePath($input));
        }
    }

    /** @test */
    public function it_returns_absolute_paths()
    {
        $basePath = '/var/www/storage/app';
        $relativePath = 'proofs/2023R41/121';

        $absolutePath = $this->pathResolver->getAbsolutePath($relativePath, $basePath);
        $this->assertEquals('/var/www/storage/app/proofs/2023R41/121', $absolutePath);

        $absolutePathWithLeadingSlash = $this->pathResolver->getAbsolutePath('/'.$relativePath, $basePath);
        $this->assertEquals('/var/www/storage/app/proofs/2023R41/121', $absolutePathWithLeadingSlash);
    }
}
