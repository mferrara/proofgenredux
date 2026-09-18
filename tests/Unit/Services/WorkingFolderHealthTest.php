<?php

namespace Tests\Unit\Services;

use App\Services\WorkingFolderHealth;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A working folder that macOS hands to iCloud loses file contents behind the
 * app's back. The operator is warned; nothing is moved automatically.
 */
class WorkingFolderHealthTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = storage_path('app/fake_home_'.uniqid());
        File::makeDirectory($this->home.'/Documents/shows', 0755, true);
        File::makeDirectory($this->home.'/ProofgenShows', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);

        parent::tearDown();
    }

    private function health(bool $evicted = false): WorkingFolderHealth
    {
        return new class($this->home, $evicted) extends WorkingFolderHealth
        {
            public function __construct(private string $fakeHome, private bool $evicted) {}

            protected function homeDirectory(): ?string
            {
                return $this->fakeHome;
            }

            protected function hasEvictedFiles(string $path): bool
            {
                return $this->evicted;
            }
        };
    }

    public function test_documents_is_a_problem_only_when_icloud_manages_it(): void
    {
        config(['proofgen.fullsize_home_dir' => $this->home.'/Documents/shows']);

        $this->assertNull($this->health()->problem());

        cache()->flush();
        File::makeDirectory($this->home.'/Library/Mobile Documents/com~apple~CloudDocs/Documents', 0755, true);

        $this->assertSame('iCloud syncs your Documents folder', $this->health()->problem()['reason']);
    }

    public function test_a_folder_outside_documents_and_desktop_is_fine(): void
    {
        File::makeDirectory($this->home.'/Library/Mobile Documents/com~apple~CloudDocs/Documents', 0755, true);
        config(['proofgen.fullsize_home_dir' => $this->home.'/ProofgenShows']);

        $this->assertNull($this->health()->problem());
    }

    public function test_evicted_files_are_a_problem_wherever_the_folder_is(): void
    {
        config(['proofgen.fullsize_home_dir' => $this->home.'/ProofgenShows']);

        $this->assertStringContainsString('no longer stored on this Mac', $this->health(evicted: true)->problem()['reason']);
    }
}
