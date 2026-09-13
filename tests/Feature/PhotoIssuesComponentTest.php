<?php

namespace Tests\Feature;

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Livewire\PhotoIssuesComponent;
use App\Livewire\ShowViewComponent;
use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PhotoImportIdentityResolver;
use App\Services\PhotoImportPlan;
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

    private function createProofCollisionIssue(): PhotoIssue
    {
        // Existing photo owns the proof number but with different bytes, so an
        // incoming numbered file with the same number is a genuine proof
        // collision (different content) that may be assigned a new number.
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00010',
            'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('original collision bytes'),
        ]);

        Storage::disk('fullsize')->put('SHOW1/101/SHOW1_00010.jpg', 'incoming collision bytes');

        $result = app(PhotoService::class)->processPhoto('SHOW1/101/SHOW1_00010.jpg', null, false, false);
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

        $issue = $this->createProofCollisionIssue();

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
        Bus::assertDispatched(GenerateThumbnails::class);
        Bus::assertDispatched(GenerateWebImage::class);
        Bus::assertDispatched(GenerateHighresImage::class);
    }

    public function test_assign_next_proof_number_is_refused_for_duplicate_content_without_allocating(): void
    {
        // Guard must return before Show::getNextProofNumber() touches Redis.
        Redis::shouldReceive('client')->never();

        $issue = $this->createDuplicateContentIssue();
        $beforePhotoCount = Photo::count();

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('assignNextProofNumberToIncoming', $issue->id);

        $issue->refresh();
        $this->assertSame(PhotoIssue::STATUS_OPEN, $issue->status);
        $this->assertSame($beforePhotoCount, Photo::count());
        $this->assertTrue(Storage::disk('fullsize')->exists($issue->quarantine_path));
    }

    public function test_assign_next_proof_number_is_refused_when_incoming_content_is_now_owned(): void
    {
        // The incoming content was unowned when the issue was recorded but a
        // different photo owns those exact bytes now. Allocation must not happen.
        Photo::create([
            'id' => 'SHOW1_102_SHOW1_00050',
            'show_class_id' => 'SHOW1_102',
            'proof_number' => 'SHOW1_00050',
            'file_type' => 'jpg',
            'sha1' => sha1('late owned bytes'),
        ]);
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00010',
            'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('collision bytes'),
        ]);

        $quarantine = 'SHOW1/101/_import_conflicts/SHOW1_00010_20260913-140000_cafebabe.jpg';
        Storage::disk('fullsize')->put($quarantine, 'late owned bytes');

        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_PROOF_COLLISION,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'quarantine_path' => $quarantine,
            'intended_proof_number' => 'SHOW1_00010',
            'incoming_sha1' => sha1('late owned bytes'),
            'incoming_size' => strlen('late owned bytes'),
            'existing_photo_id' => 'SHOW1_101_SHOW1_00010',
            'existing_proof_number' => 'SHOW1_00010',
            'existing_sha1' => sha1('collision bytes'),
        ]);

        Redis::shouldReceive('client')->never();

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('assignNextProofNumberToIncoming', $issue->id);

        $issue->refresh();
        $this->assertSame(PhotoIssue::STATUS_OPEN, $issue->status);
        $this->assertNull(Photo::find('SHOW1_101_SHOW1_00500'));
        $this->assertTrue(Storage::disk('fullsize')->exists($quarantine));
    }

    public function test_replace_existing_refuses_when_another_photo_owns_incoming_content(): void
    {
        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00010',
            'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('existing original bytes'),
        ]);
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00010.jpg', 'existing original bytes');

        // A different photo already owns the incoming content.
        Photo::create([
            'id' => 'SHOW1_102_SHOW1_00050',
            'show_class_id' => 'SHOW1_102',
            'proof_number' => 'SHOW1_00050',
            'file_type' => 'jpg',
            'sha1' => sha1('incoming bytes'),
        ]);

        $quarantine = 'SHOW1/101/_import_conflicts/SHOW1_00010_20260913-120000_deadbeef.jpg';
        Storage::disk('fullsize')->put($quarantine, 'incoming bytes');

        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_PROOF_COLLISION,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'quarantine_path' => $quarantine,
            'intended_proof_number' => 'SHOW1_00010',
            'incoming_sha1' => sha1('incoming bytes'),
            'incoming_size' => strlen('incoming bytes'),
            'existing_photo_id' => 'SHOW1_101_SHOW1_00010',
            'existing_proof_number' => 'SHOW1_00010',
            'existing_sha1' => sha1('existing original bytes'),
        ]);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('confirmDestructive', $issue->id, 'replace_existing')
            ->call('performConfirmedDestructive');

        // Nothing destructive happened: row, original, and quarantine all intact.
        $existing = Photo::find('SHOW1_101_SHOW1_00010');
        $this->assertNotNull($existing);
        $this->assertSame(sha1('existing original bytes'), $existing->sha1);
        $this->assertTrue(Storage::disk('fullsize')->exists('SHOW1/101/originals/SHOW1_00010.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists($quarantine));
        $this->assertSame(PhotoIssue::STATUS_OPEN, $issue->fresh()->status);
    }

    public function test_replace_existing_with_incoming_still_works_for_genuinely_different_content(): void
    {
        Bus::fake();

        Photo::create([
            'id' => 'SHOW1_101_SHOW1_00010',
            'show_class_id' => 'SHOW1_101',
            'proof_number' => 'SHOW1_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('existing original bytes'),
        ]);
        Storage::disk('fullsize')->put('SHOW1/101/originals/SHOW1_00010.jpg', 'existing original bytes');

        $quarantine = 'SHOW1/101/_import_conflicts/SHOW1_00010_20260913-130000_feedface.jpg';
        Storage::disk('fullsize')->put($quarantine, 'replacement bytes');

        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_PROOF_COLLISION,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'quarantine_path' => $quarantine,
            'intended_proof_number' => 'SHOW1_00010',
            'incoming_sha1' => sha1('replacement bytes'),
            'incoming_size' => strlen('replacement bytes'),
            'existing_photo_id' => 'SHOW1_101_SHOW1_00010',
            'existing_proof_number' => 'SHOW1_00010',
            'existing_sha1' => sha1('existing original bytes'),
        ]);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('confirmDestructive', $issue->id, 'replace_existing')
            ->call('performConfirmedDestructive');

        $issue->refresh();
        $this->assertSame(PhotoIssue::STATUS_RESOLVED, $issue->status);

        $photo = Photo::find('SHOW1_101_SHOW1_00010');
        $this->assertNotNull($photo);
        $this->assertSame(sha1('replacement bytes'), $photo->sha1);
        $this->assertTrue(Storage::disk('fullsize')->exists('SHOW1/101/originals/SHOW1_00010.jpg'));
        $this->assertSame('replacement bytes', Storage::disk('fullsize')->get('SHOW1/101/originals/SHOW1_00010.jpg'));
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

    public function test_replace_existing_relocates_quarantine_into_existing_class_actual_location(): void
    {
        Bus::fake();

        // Show and class names both contain underscores. The existing photo lives in
        // 2023_R41_opening_ceremony; the source was quarantined under the sibling class
        // 2023_R41_closing_ceremony.
        Show::withoutEvents(fn () => Show::create(['id' => '2023_R41', 'name' => '2023_R41']));
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => '2023_R41_opening_ceremony', 'show_id' => '2023_R41', 'name' => 'opening_ceremony']);
            ShowClass::create(['id' => '2023_R41_closing_ceremony', 'show_id' => '2023_R41', 'name' => 'closing_ceremony']);
        });

        Photo::create([
            'id' => '2023_R41_opening_ceremony_2023_R41_00010',
            'show_class_id' => '2023_R41_opening_ceremony',
            'proof_number' => '2023_R41_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('existing original bytes'),
        ]);
        Storage::disk('fullsize')->put('2023_R41/opening_ceremony/originals/2023_R41_00010.jpg', 'existing original bytes');

        $quarantine = '2023_R41/closing_ceremony/_import_conflicts/2023_R41_00010_20260913-150000_aaaabbbb.jpg';
        Storage::disk('fullsize')->put($quarantine, 'replacement bytes');

        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_PROOF_COLLISION,
            'show_id' => '2023_R41',
            'show_class_id' => '2023_R41_closing_ceremony',
            'quarantine_path' => $quarantine,
            'intended_proof_number' => '2023_R41_00010',
            'incoming_sha1' => sha1('replacement bytes'),
            'incoming_size' => strlen('replacement bytes'),
            'existing_photo_id' => '2023_R41_opening_ceremony_2023_R41_00010',
            'existing_proof_number' => '2023_R41_00010',
            'existing_sha1' => sha1('existing original bytes'),
        ]);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('confirmDestructive', $issue->id, 'replace_existing')
            ->call('performConfirmedDestructive');

        $issue->refresh();
        $this->assertSame(PhotoIssue::STATUS_RESOLVED, $issue->status);

        // Replacement landed back in the existing photo's actual class, not in the
        // class where the source happened to be quarantined and not in a directory
        // guessed by splitting the composite id at the first underscore.
        $photo = Photo::find('2023_R41_opening_ceremony_2023_R41_00010');
        $this->assertNotNull($photo);
        $this->assertSame('2023_R41_opening_ceremony', $photo->show_class_id);
        $this->assertSame(sha1('replacement bytes'), $photo->sha1);
        $this->assertSame('replacement bytes', Storage::disk('fullsize')->get('2023_R41/opening_ceremony/originals/2023_R41_00010.jpg'));

        // Nothing was written into the quarantine class or the wrong-guess directory.
        $this->assertFalse(Storage::disk('fullsize')->exists('2023_R41/closing_ceremony/originals/2023_R41_00010.jpg'));
        $this->assertFalse(Storage::disk('fullsize')->exists('2023/R41_opening_ceremony/originals/2023_R41_00010.jpg'));

        // Quarantined bytes were relocated into the existing class, then buried by
        // the import; the issue records the actual resulting location.
        $this->assertFalse(Storage::disk('fullsize')->exists($quarantine));
        $this->assertSame('2023_R41/opening_ceremony/'.basename($quarantine), $issue->quarantine_path);
        $graveyard = Storage::disk('fullsize')->allFiles('_graveyard');
        $matching = array_filter(
            $graveyard,
            fn ($path) => str_contains($path, pathinfo(basename($quarantine), PATHINFO_FILENAME))
        );
        $this->assertNotEmpty($matching, 'quarantine bytes should end up in the graveyard');
    }

    public function test_replace_existing_refuses_when_existing_class_relation_is_missing(): void
    {
        // No ShowClass row for SHOW1_999: identity cannot be resolved, so the
        // replacement must refuse before any destructive change.
        Photo::create([
            'id' => 'SHOW1_999_SHOW1_00010',
            'show_class_id' => 'SHOW1_999',
            'proof_number' => 'SHOW1_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('existing original bytes'),
        ]);
        Storage::disk('fullsize')->put('SHOW1/999/originals/SHOW1_00010.jpg', 'existing original bytes');

        $quarantine = 'SHOW1/101/_import_conflicts/SHOW1_00010_20260913-170000_deadbeef.jpg';
        Storage::disk('fullsize')->put($quarantine, 'replacement bytes');

        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_PROOF_COLLISION,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_101',
            'quarantine_path' => $quarantine,
            'incoming_sha1' => sha1('replacement bytes'),
            'incoming_size' => strlen('replacement bytes'),
            'existing_photo_id' => 'SHOW1_999_SHOW1_00010',
            'existing_proof_number' => 'SHOW1_00010',
            'existing_sha1' => sha1('existing original bytes'),
        ]);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->call('confirmDestructive', $issue->id, 'replace_existing')
            ->call('performConfirmedDestructive');

        // Nothing destructive happened: row, original and quarantine all intact.
        $this->assertNotNull(Photo::find('SHOW1_999_SHOW1_00010'));
        $this->assertTrue(Storage::disk('fullsize')->exists('SHOW1/999/originals/SHOW1_00010.jpg'));
        $this->assertTrue(Storage::disk('fullsize')->exists($quarantine));
        $this->assertSame(PhotoIssue::STATUS_OPEN, $issue->fresh()->status);
    }

    public function test_hint_resolution_uses_actual_class_relation_for_underscore_ids(): void
    {
        // Show and class both contain underscores; splitting the composite id would
        // resolve the wrong identity.
        Show::withoutEvents(fn () => Show::create(['id' => '2023_R41', 'name' => '2023_R41']));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => '2023_R41_opening_ceremony',
            'show_id' => '2023_R41',
            'name' => 'opening_ceremony',
        ]));

        $quarantine = '2023_R41/opening_ceremony/_import_conflicts/2023_R41_00001_20260913-160000_cafebabe.jpg';
        Storage::disk('fullsize')->put($quarantine, 'incoming bytes');

        $existing = Photo::create([
            'id' => '2023_R41_opening_ceremony_2023_R41_00010',
            'show_class_id' => '2023_R41_opening_ceremony',
            'proof_number' => '2023_R41_00010',
            'file_type' => 'jpg',
            'sha1' => sha1('incoming bytes'),
        ]);

        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_DUPLICATE_CONTENT,
            'show_id' => '2023_R41',
            'show_class_id' => '2023_R41_opening_ceremony',
            'quarantine_path' => $quarantine,
            'incoming_sha1' => sha1('incoming bytes'),
            'incoming_size' => strlen('incoming bytes'),
            'existing_photo_id' => $existing->id,
            'existing_proof_number' => '2023_R41_00010',
            'existing_sha1' => sha1('incoming bytes'),
        ]);

        $captured = [];
        $mock = Mockery::mock(PhotoImportIdentityResolver::class);
        $mock->shouldReceive('resolve')
            ->once()
            ->andReturnUsing(function (string $path, string $show, string $class) use (&$captured, $quarantine, $existing) {
                $captured = [$path, $show, $class];

                return new PhotoImportPlan(
                    decision: PhotoImportIdentityResolver::DUPLICATE_CONTENT,
                    sourcePath: $quarantine,
                    originalFilename: '2023_R41_00001.jpg',
                    extension: 'jpg',
                    sha1: sha1('does not match the quarantine bytes'),
                    size: 1,
                    sourceMtime: null,
                    filenameIsNumberedForShow: false,
                    intendedProofNumber: null,
                    allocatesNewProofNumber: false,
                    existingByContent: $existing,
                    existingByProofNumber: null,
                );
            });
        $this->app->instance(PhotoImportIdentityResolver::class, $mock);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->assertSuccessful();

        $this->assertSame([$quarantine, '2023_R41', 'opening_ceremony'], $captured);
    }

    public function test_hint_resolution_is_skipped_when_issue_class_is_missing(): void
    {
        $quarantine = 'SHOW1/999/_import_conflicts/SHOW1_00001_20260913-180000_feedface.jpg';
        Storage::disk('fullsize')->put($quarantine, 'incoming bytes');

        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_DUPLICATE_CONTENT,
            'show_id' => 'SHOW1',
            'show_class_id' => 'SHOW1_999',
            'quarantine_path' => $quarantine,
            'incoming_sha1' => sha1('incoming bytes'),
            'incoming_size' => strlen('incoming bytes'),
        ]);

        $mock = Mockery::mock(PhotoImportIdentityResolver::class);
        $mock->shouldReceive('resolve')->never();
        $this->app->instance(PhotoImportIdentityResolver::class, $mock);

        Livewire::test(PhotoIssuesComponent::class)
            ->call('openIssue', $issue->id)
            ->assertSuccessful()
            ->assertSet('selectedIssueId', $issue->id);
    }

    public function test_replace_existing_checks_quarantine_identity_before_removing_the_original(): void
    {
        $photo = Photo::create([
            'show_class_id' => 'SHOW1_101', 'proof_number' => 'SHOW1_00099',
            'file_type' => 'jpg', 'sha1' => sha1('original'),
        ]);
        $original = 'SHOW1/101/originals/SHOW1_00099.jpg';
        $quarantine = 'SHOW1/102/_import_conflicts/incoming.jpg';
        Storage::disk('fullsize')->put($original, 'original');
        Storage::disk('fullsize')->put($quarantine, 'incoming');
        $issue = PhotoIssue::create([
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_PROOF_COLLISION,
            'show_id' => 'SHOW1', 'show_class_id' => 'SHOW1_101',
            'quarantine_path' => $quarantine, 'incoming_sha1' => sha1('incoming'),
            'incoming_size' => strlen('incoming'), 'existing_photo_id' => $photo->id,
            'existing_proof_number' => $photo->proof_number, 'existing_sha1' => $photo->sha1,
        ]);
        Livewire::test(PhotoIssuesComponent::class)
            ->call('confirmDestructive', $issue->id, 'replace_existing')
            ->call('performConfirmedDestructive');
        $this->assertNotNull($photo->fresh());
        $this->assertSame('original', Storage::disk('fullsize')->get($original));
        $this->assertSame('incoming', Storage::disk('fullsize')->get($quarantine));
        $this->assertSame(PhotoIssue::STATUS_OPEN, $issue->fresh()->status);
    }
}
