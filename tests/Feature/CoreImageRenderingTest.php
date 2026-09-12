<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Real Metal rendering; opt in locally with PROOFGEN_TEST_CORE_IMAGE=1. */
class CoreImageRenderingTest extends TestCase
{
    public function test_adjustment_controls_change_real_rendered_pixels(): void
    {
        if (getenv('PROOFGEN_TEST_CORE_IMAGE') !== '1' || PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('Set PROOFGEN_TEST_CORE_IMAGE=1 on macOS to run the Metal renderer.');
        }

        $root = sys_get_temp_dir().'/proofgen-render-'.uniqid();
        mkdir($root);
        try {
            $compile = new Process([
                'swiftc', '-O', '-module-cache-path', $root.'/modules', '-o', $root.'/enhancer',
                dirname(__DIR__, 2).'/app/Services/CoreImage/ProofgenImageEnhancerDaemon.swift',
            ]);
            $compile->setTimeout(180)->mustRun();
            $source = imagecreatetruecolor(512, 128);
            for ($x = 0; $x < 512; $x++) {
                $value = (int) round(20 + $x * 215 / 511);
                imageline($source, $x, 0, $x, 127, imagecolorallocate($source, $value, $value, $value));
            }
            imagepng($source, $root.'/gradient.png');
            imagefill($source, 0, 0, imagecolorallocate($source, 100, 100, 100));
            imagefilledrectangle($source, 0, 0, 511, 127, imagecolorallocate($source, 100, 100, 100));
            imagepng($source, $root.'/flat.png');

            $auto = ['auto_levels_target_brightness' => 128, 'auto_levels_contrast_threshold' => 0,
                'auto_levels_contrast_boost' => 1, 'auto_levels_black_point' => 0, 'auto_levels_white_point' => 100];
            $tone = ['tone_mapping_percentile_low' => 0, 'tone_mapping_percentile_high' => 100,
                'tone_mapping_shadow_amount' => 0, 'tone_mapping_highlight_amount' => 0,
                'tone_mapping_shadow_radius' => 30, 'tone_mapping_midtone_gamma' => 1];
            $cases = [
                'auto' => ['adjustable_auto_levels', $auto],
                'clipped' => ['adjustable_auto_levels', array_replace($auto, ['auto_levels_black_point' => 5, 'auto_levels_white_point' => 95])],
                'brighter' => ['adjustable_auto_levels', array_replace($auto, ['auto_levels_target_brightness' => 160])],
                'contrast' => ['adjustable_auto_levels', array_replace($auto, ['auto_levels_contrast_threshold' => 255, 'auto_levels_contrast_boost' => 1.4])],
                'tone' => ['advanced_tone_mapping', $tone],
                'flat' => ['advanced_tone_mapping', $tone],
                'shadow' => ['advanced_tone_mapping', array_replace($tone, ['tone_mapping_shadow_amount' => 50])],
                'dark-shadow' => ['advanced_tone_mapping', array_replace($tone, ['tone_mapping_shadow_amount' => -50])],
                'highlight' => ['advanced_tone_mapping', array_replace($tone, ['tone_mapping_highlight_amount' => -50])],
                'gamma' => ['advanced_tone_mapping', array_replace($tone, ['tone_mapping_midtone_gamma' => 1.5])],
            ];
            $requests = [];
            foreach ($cases as $name => [$method, $parameters]) {
                $requests[] = json_encode(['method' => $method, 'inputPath' => $root.($name === 'flat' ? '/flat.png' : '/gradient.png'),
                    'outputPath' => $root.'/'.$name.'.jpg', 'parameters' => $parameters]);
            }
            $requests[] = json_encode(['method' => 'advanced_tone_mapping', 'inputPath' => $root.'/gradient.png',
                'outputPath' => $root.'/unsupported.jpg', 'parameters' => array_replace($tone, ['tone_mapping_highlight_amount' => 20])]);
            $process = new Process([$root.'/enhancer', '--base-path', $root]);
            $process->setInput(implode("\n", $requests)."\nEXIT\n")->setTimeout(60)->mustRun();

            $this->assertFileDoesNotExist($root.'/unsupported.jpg');
            $this->assertStringContainsString('Positive highlight brightening is not supported', $process->getOutput());
            $pixels = [];
            foreach ($cases as $name => $case) {
                $path = $root.'/'.$name.'.jpg';
                $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
                $image = imagecreatefromjpeg($path);
                $pixels[$name] = array_map(fn ($x) => imagecolorat($image, $x, 64) & 255, range(0, 511));
            }
            $mean = fn ($values) => array_sum($values) / count($values);
            $this->assertEqualsWithDelta(100, $mean($pixels['flat']), 1);
            $this->assertEqualsWithDelta(128, $mean($pixels['auto']), 3);
            $this->assertEqualsWithDelta(160, $mean($pixels['brighter']), 4);
            $this->assertEqualsWithDelta(128, $mean($pixels['clipped']), 5);
            $this->assertLessThan(10, $pixels['clipped'][0]);
            $this->assertGreaterThan(245, $pixels['clipped'][511]);
            $this->assertLessThan($pixels['auto'][128], $pixels['contrast'][128]);
            $this->assertGreaterThan($pixels['auto'][384], $pixels['contrast'][384]);
            $this->assertGreaterThan($pixels['tone'][51], $pixels['shadow'][51]);
            $this->assertGreaterThan(245, $pixels['shadow'][511]);
            $this->assertLessThan($pixels['tone'][51], $pixels['dark-shadow'][51]);
            $this->assertLessThan($pixels['tone'][460], $pixels['highlight'][460]);
            $this->assertLessThan($pixels['tone'][256], $pixels['gamma'][256]);
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
