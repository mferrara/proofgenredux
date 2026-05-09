<?php

namespace Tests\Feature;

use App\Livewire\GraveyardComponent;
use App\Livewire\PhotoIssuesComponent;
use App\Models\PhotoIssue;
use App\Services\SafeFileMover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FinderRevealComponentsTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/finder_reveal_test_'.uniqid());
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

    public function test_photo_issues_component_reveal_action_shells_out_with_quarantine_path(): void
    {
        Process::fake();

        $relPath = 'SHOW1/101/_import_conflicts/IMG_0001.jpg';
        Storage::disk('fullsize')->put($relPath, 'bytes');

        PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_DUPLICATE_CONTENT,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'quarantine_path' => $relPath,
        ]);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('revealInFinder', $relPath, 'fullsize')
            ->assertSuccessful();

        $expectedAbsolute = $this->tempPath.'/fullsize/'.$relPath;
        Process::assertRan(function ($process) use ($expectedAbsolute) {
            $command = $process->command;
            if (is_array($command)) {
                return in_array($expectedAbsolute, $command, true)
                    && in_array('-R', $command, true)
                    && in_array('open', $command, true);
            }

            return is_string($command) && str_contains($command, $expectedAbsolute);
        });
    }

    public function test_graveyard_component_reveal_action_shells_out_with_buried_file_path(): void
    {
        Process::fake();

        Storage::disk('fullsize')->put('SHOW1/101/dead.jpg', 'dead-bytes');
        $buried = app(SafeFileMover::class)->bury(
            disk: 'fullsize',
            path: 'SHOW1/101/dead.jpg',
            reason: SafeFileMover::REASON_POST_IMPORT_SOURCE,
        );

        Livewire::test(GraveyardComponent::class)
            ->call('revealInFinder', $buried['graveyard_path'], 'fullsize')
            ->assertSuccessful();

        $expectedAbsolute = $this->tempPath.'/fullsize/'.$buried['graveyard_path'];
        Process::assertRan(function ($process) use ($expectedAbsolute) {
            $command = $process->command;
            if (is_array($command)) {
                return in_array($expectedAbsolute, $command, true)
                    && in_array('-R', $command, true);
            }

            return is_string($command) && str_contains($command, $expectedAbsolute);
        });
    }

    public function test_photo_issues_reveal_with_path_outside_disks_does_not_shell_out(): void
    {
        Process::fake();

        Livewire::test(PhotoIssuesComponent::class)
            ->call('revealInFinder', '../../../etc/passwd', 'fullsize')
            ->assertSuccessful();

        // Service threw and we caught — toast posted but no process ran.
        Process::assertDidntRun(function ($process) {
            $command = $process->command;
            $haystack = is_array($command) ? implode(' ', $command) : (string) $command;

            return str_contains($haystack, '/etc/passwd');
        });
    }
}
