<?php

namespace Tests\Unit\Services;

use App\Services\SafeFileMover;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SafeFileMoverTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/safe_file_mover_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/archive', 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.archive_home_dir' => $this->tempPath.'/archive']);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/fullsize',
            'throw' => true,
        ]]);
        config(['filesystems.disks.archive' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/archive',
            'throw' => true,
        ]]);
        Storage::forgetDisk('fullsize');
        Storage::forgetDisk('archive');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_bury_moves_file_to_dated_graveyard_with_sidecar(): void
    {
        Carbon::setTestNow('2026-05-09 14:30:25');
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0001.jpg', 'raw image data');

        $result = app(SafeFileMover::class)->bury(
            disk: 'fullsize',
            path: 'SHOW1/101/IMG_0001.jpg',
            reason: SafeFileMover::REASON_POST_IMPORT_SOURCE,
            context: ['photo_id' => 'SHOW1_101_00001'],
        );

        $this->assertFalse(Storage::disk('fullsize')->exists('SHOW1/101/IMG_0001.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists($result['graveyard_path']));
        $this->assertSame('raw image data', Storage::disk('fullsize')->get($result['graveyard_path']));
        $this->assertSame(sha1('raw image data'), $result['sha1']);

        // Path layout: _graveyard/{date}/{original-dir}/{stem}_{stamp}_{sha8}.{ext}
        $this->assertStringStartsWith('_graveyard/2026-05-09/SHOW1/101/IMG_0001_', $result['graveyard_path']);
        $this->assertStringEndsWith('_'.substr(sha1('raw image data'), 0, 8).'.jpg', $result['graveyard_path']);

        // Sidecar contents
        $sidecar = json_decode(Storage::disk('fullsize')->get($result['sidecar_path']), true);
        $this->assertSame('proofgen.graveyard.v1', $sidecar['schema']);
        $this->assertSame('fullsize', $sidecar['disk']);
        $this->assertSame('SHOW1/101/IMG_0001.jpg', $sidecar['original_path']);
        $this->assertSame(sha1('raw image data'), $sidecar['sha1']);
        $this->assertSame(strlen('raw image data'), $sidecar['size']);
        $this->assertSame(SafeFileMover::REASON_POST_IMPORT_SOURCE, $sidecar['reason']);
        $this->assertSame('SHOW1_101_00001', $sidecar['context']['photo_id']);
        $this->assertArrayNotHasKey('sha1', $sidecar['context']);
    }

    public function test_bury_uses_provided_sha1_and_size_without_rereading(): void
    {
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0002.jpg', 'bytes here');

        $result = app(SafeFileMover::class)->bury(
            disk: 'fullsize',
            path: 'SHOW1/101/IMG_0002.jpg',
            reason: SafeFileMover::REASON_POST_IMPORT_SOURCE,
            context: ['sha1' => str_repeat('a', 40), 'size' => 999],
        );

        $this->assertSame(str_repeat('a', 40), $result['sha1']);
        $this->assertSame(999, $result['size']);
        $this->assertStringEndsWith('_aaaaaaaa.jpg', $result['graveyard_path']);

        $sidecar = json_decode(Storage::disk('fullsize')->get($result['sidecar_path']), true);
        $this->assertSame(str_repeat('a', 40), $sidecar['sha1']);
        $this->assertSame(999, $sidecar['size']);
    }

    public function test_bury_handles_collisions_by_appending_counter(): void
    {
        Carbon::setTestNow('2026-05-09 14:30:25');

        Storage::disk('fullsize')->put('SHOW1/101/IMG_0003.jpg', 'same bytes');
        $first = app(SafeFileMover::class)->bury('fullsize', 'SHOW1/101/IMG_0003.jpg', SafeFileMover::REASON_POST_IMPORT_SOURCE);

        Storage::disk('fullsize')->put('SHOW1/101/IMG_0003.jpg', 'same bytes');
        $second = app(SafeFileMover::class)->bury('fullsize', 'SHOW1/101/IMG_0003.jpg', SafeFileMover::REASON_POST_IMPORT_SOURCE);

        $this->assertNotSame($first['graveyard_path'], $second['graveyard_path']);
        $this->assertStringEndsWith('_1.jpg', $second['graveyard_path']);
        $this->assertTrue(Storage::disk('fullsize')->exists($first['graveyard_path']));
        $this->assertTrue(Storage::disk('fullsize')->exists($second['graveyard_path']));
    }

    public function test_bury_throws_when_source_missing(): void
    {
        $this->expectException(RuntimeException::class);
        app(SafeFileMover::class)->bury('fullsize', 'SHOW1/101/missing.jpg', SafeFileMover::REASON_PHOTO_DELETED);
    }

    public function test_bury_absolute_resolves_to_matching_disk(): void
    {
        Storage::disk('archive')->put('SHOW1/101/SHOW1_00010.jpg', 'archive bytes');

        $result = app(SafeFileMover::class)->buryAbsolute(
            $this->tempPath.'/archive/SHOW1/101/SHOW1_00010.jpg',
            SafeFileMover::REASON_REDUNDANT_ARCHIVE_SOURCE,
        );

        $this->assertSame('archive', $result['disk']);
        $this->assertStringStartsWith('_graveyard/', $result['graveyard_path']);
        $this->assertTrue(Storage::disk('archive')->exists($result['graveyard_path']));
    }

    public function test_bury_absolute_throws_for_unknown_root(): void
    {
        $this->expectException(RuntimeException::class);
        app(SafeFileMover::class)->buryAbsolute('/tmp/nowhere/file.jpg', SafeFileMover::REASON_PHOTO_DELETED);
    }

    public function test_custom_graveyard_path_config_is_honored(): void
    {
        config(['proofgen.graveyard.path' => '_trash']);
        Storage::disk('fullsize')->put('SHOW1/101/IMG_0004.jpg', 'data');

        $result = app(SafeFileMover::class)->bury('fullsize', 'SHOW1/101/IMG_0004.jpg', SafeFileMover::REASON_PHOTO_DELETED);

        $this->assertStringStartsWith('_trash/', $result['graveyard_path']);
    }
}
