<?php

namespace Tests\Feature;

use App\Livewire\PhotoIssuesComponent;
use App\Livewire\ShowViewComponent;
use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class PhotoIssuesComponentTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        config(['proofgen.rename_files' => true]);
        config(['proofgen.archive_enabled' => true]);

        $this->tempPath = storage_path('app/photo_issues_component_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/archive', 0755, true);

        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.archive_home_dir' => $this->tempPath.'/archive']);
        config(['filesystems.disks.fullsize' => [
            'driver' => 'local', 'root' => $this->tempPath.'/fullsize', 'throw' => true,
        ]]);
        config(['filesystems.disks.archive' => [
            'driver' => 'local', 'root' => $this->tempPath.'/archive', 'throw' => true,
        ]]);
        Storage::forgetDisk('fullsize');
        Storage::forgetDisk('archive');

        Show::withoutEvents(function () {
            Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']);
            Show::create(['id' => 'SHOW2', 'name' => 'SHOW2']);
        });
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']);
            ShowClass::create(['id' => 'SHOW1_102', 'show_id' => 'SHOW1', 'name' => '102']);
            ShowClass::create(['id' => 'SHOW2_201', 'show_id' => 'SHOW2', 'name' => '201']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        Mockery::close();
        parent::tearDown();
    }

    private function createDuplicateContentIssue(string $sourceClass = 'SHOW1_101', string $bytes = 'shared bytes'): PhotoIssue
    {
        // Photo elsewhere with same bytes triggers DUPLICATE_CONTENT.
        Photo::create([
            'id' => 'SHOW1_102_SHOW1_00010',
            'show_class_id' => 'SHOW1_102',
            'proof_number' => 'SHOW1_00010',
            'file_type' => 'jpg',
            'sha1' => sha1($bytes),
        ]);

        $parts = explode('_', $sourceClass, 2);
        $relPath = $parts[0].'/'.$parts[1].'/IMG_0001.jpg';
        Storage::disk('fullsize')->put($relPath, $bytes);

        $result = app(PhotoService::class)->processPhoto($relPath, null, false, false);
        $this->assertNotNull($result['issue']);

        return $result['issue']->fresh();
    }

    public function test_renders_open_issues_with_filter(): void
    {
        $issue = $this->createDuplicateContentIssue();

        // Add a separate issue type to confirm filter narrows results.
        PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_MISSING_ARCHIVE,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'existing_photo_id' => 'SHOW1_101_FAKE',
        ]);

        Livewire::test(PhotoIssuesComponent::class)
            ->assertSuccessful()
            ->assertSee('Photo Issues')
            ->assertSee($issue->show_class_id)
            ->set('issueType', PhotoIssue::TYPE_DUPLICATE_CONTENT)
            ->assertSee('duplicate content')
            ->assertDontSee('missing archive');
    }

    public function test_discard_incoming_buries_quarantined_source_and_resolves_issue(): void
    {
        $issue = $this->createDuplicateContentIssue();

        $this->assertTrue(Storage::disk('fullsize')->exists($issue->quarantine_path));

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('discardIncoming', $issue->id);

        $issue->refresh();
        $this->assertSame(PhotoIssue::STATUS_RESOLVED, $issue->status);
        $this->assertNotNull($issue->resolved_at);
        $this->assertFalse(Storage::disk('fullsize')->exists($issue->quarantine_path));

        // File now lives under graveyard.
        $graveyardFiles = Storage::disk('fullsize')->allFiles('_graveyard');
        $imageFiles = array_values(array_filter($graveyardFiles, fn ($p) => str_ends_with($p, '.jpg')));
        $this->assertNotEmpty($imageFiles);
    }

    public function test_assign_next_proof_number_imports_quarantined_source_and_resolves_issue(): void
    {
        Bus::fake();

        // Stub Redis so getNextProofNumber doesn't need a live server.
        $allocated = 'SHOW1_00500';
        $redisClient = Mockery::mock();
        $redisClient->shouldReceive('exists')->andReturn(1);
        $redisClient->shouldReceive('llen')->andReturn(1);
        $redisClient->shouldReceive('lpop')->andReturn($allocated);
        Redis::shouldReceive('client')->andReturn($redisClient);

        $issue = $this->createDuplicateContentIssue();

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('assignNextProofNumberToIncoming', $issue->id);

        $issue->refresh();
        $this->assertSame(PhotoIssue::STATUS_RESOLVED, $issue->status);

        $newPhoto = Photo::find('SHOW1_101_'.$allocated);
        $this->assertNotNull($newPhoto, 'New photo should exist under allocated proof number');
        $this->assertSame('SHOW1/101/originals/'.$allocated.'.jpg', $newPhoto->relative_path);
        $this->assertTrue(Storage::disk('fullsize')->exists($newPhoto->relative_path));

        // Archive copy was written.
        $this->assertTrue(Storage::disk('archive')->exists('SHOW1/101/'.$allocated.'.jpg'));
        $this->assertNotNull($newPhoto->archive_path);

        // Derivative regen jobs are queued so thumbnails/web/highres show up immediately
        // after resolution rather than waiting for the next class-view reconciliation.
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\Photo\GenerateThumbnails::class);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\Photo\GenerateWebImage::class);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\Photo\GenerateHighresImage::class);
    }

    public function test_mark_ignored_sets_status_and_writes_note(): void
    {
        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_ARCHIVE_CONFLICT,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'existing_photo_id' => 'SHOW1_101_SOMETHING',
        ]);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->set('resolutionNotes', 'Operator reviewed; bytes drift expected.')
            ->call('markIgnored', $issue->id);

        $issue->refresh();
        $this->assertSame(PhotoIssue::STATUS_IGNORED, $issue->status);
        $this->assertNotNull($issue->resolved_at);
        $this->assertStringContainsString('Operator reviewed', $issue->notes);
    }

    public function test_pagination_smoke(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            PhotoIssue::create([
                'status' => PhotoIssue::STATUS_OPEN,
                'issue_type' => PhotoIssue::TYPE_NEEDS_REVIEW,
                'show_id' => 'SHOW1',
                'show_class_id' => 'SHOW1_101',
                'incoming_sha1' => sha1('s'.$i),
                'source_path' => 'SHOW1/101/IMG_'.$i.'.jpg',
            ]);
        }

        $component = Livewire::test(PhotoIssuesComponent::class)->assertSuccessful();
        // Default page size is 25; total should reflect all 30 even when paginated.
        $this->assertSame(30, $component->viewData('issues')->total());
        $this->assertSame(25, $component->viewData('issues')->count());
    }

    public function test_show_view_includes_open_issue_badge_count(): void
    {
        // Create two open issues in different classes of SHOW1, one in SHOW2.
        PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_NEEDS_REVIEW,
            'show_id' => 'SHOW1', 'show_class_id' => 'SHOW1_101',
        ]);
        PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_NEEDS_REVIEW,
            'show_id' => 'SHOW1', 'show_class_id' => 'SHOW1_102',
        ]);
        PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_NEEDS_REVIEW,
            'show_id' => 'SHOW2', 'show_class_id' => 'SHOW2_201',
        ]);

        // Make sure the show folder exists so the discovery step doesn't blow up.
        File::makeDirectory($this->tempPath.'/fullsize/SHOW1', 0755, true);
        File::makeDirectory($this->tempPath.'/fullsize/SHOW1/101', 0755, true);
        File::makeDirectory($this->tempPath.'/fullsize/SHOW1/102', 0755, true);

        $component = Livewire::test(ShowViewComponent::class, ['show_id' => 'SHOW1']);
        $component->assertSuccessful();
        $this->assertSame(2, $component->viewData('show_open_issue_count'));
    }
}
