<?php

use App\Models\MigrationInventory;
use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\Migration\MigrationInventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'testing.skip_file_operations' => true,
        'proofgen.thumbnails.small.suffix' => '_thm',
        'proofgen.thumbnails.large.suffix' => '_std',
        'proofgen.web_images.suffix' => '_web',
        'proofgen.highres_images.suffix' => '_highres',
    ]);

    Storage::fake('remote_proofs');
    Storage::fake('remote_web_images');
    Storage::fake('remote_highres_images');
});

function migration_inventory_seed_show(): Show
{
    Show::withoutEvents(fn () => Show::create([
        'id' => 'SHOW1',
        'name' => 'Show One',
        'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
    ]));
    ShowClass::withoutEvents(fn () => ShowClass::create([
        'id' => 'SHOW1_101',
        'show_id' => 'SHOW1',
        'name' => '101',
    ]));

    Photo::withoutEvents(fn () => Photo::create([
        'id' => 'SHOW1_101_SHOW1_00001',
        'show_class_id' => 'SHOW1_101',
        'proof_number' => 'SHOW1_00001',
        'file_type' => 'jpg',
        'sha1' => sha1('SHOW1_00001'),
        'proofs_uploaded_at' => Carbon::now(),
        'web_image_uploaded_at' => Carbon::now(),
        'highres_image_uploaded_at' => Carbon::now(),
    ]));

    Photo::withoutEvents(fn () => Photo::create([
        'id' => 'SHOW1_101_SHOW1_00002',
        'show_class_id' => 'SHOW1_101',
        'proof_number' => 'SHOW1_00002',
        'file_type' => 'jpg',
        'sha1' => sha1('SHOW1_00002'),
        'proofs_uploaded_at' => Carbon::now(),
    ]));

    return Show::find('SHOW1');
}

it('discovers legacy sources and reports missing database sources plus strays', function () {
    migration_inventory_seed_show();

    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_thm.jpg', 'thm');
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_00001_std.jpg', 'std');
    Storage::disk('remote_web_images')->put('SHOW1/101/SHOW1_00001_web.jpg', 'web');
    Storage::disk('remote_highres_images')->put('SHOW1/101/SHOW1_00001_highres.jpg', 'highres');
    Storage::disk('remote_proofs')->put('SHOW1/101/SHOW1_99999_thm.jpg', 'stray');

    $stats = app(MigrationInventoryService::class)->inventory('SHOW1');

    expect($stats['discovered'])->toBe(5)
        ->and($stats['missing_sources'])->toBe(1)
        ->and($stats['strays'])->toBe(1)
        ->and(MigrationInventory::query()->count())->toBe(5)
        ->and(MigrationInventory::query()->where('content_type', MigrationInventory::CONTENT_HIGH_RES_IMAGE)->first()?->source_path)
        ->toBe('SHOW1/101/SHOW1_00001_highres.jpg');

    $issue = PhotoIssue::query()
        ->where('issue_type', PhotoIssue::TYPE_MIGRATION_SOURCE_MISSING)
        ->first();

    expect($issue)->not->toBeNull()
        ->and($issue->existing_photo_id)->toBe('SHOW1_101_SHOW1_00002')
        ->and($issue->evidence['missing_content_types'])->toBe([
            MigrationInventory::CONTENT_PROOF_THM,
            MigrationInventory::CONTENT_PROOF_STD,
        ]);

    $again = app(MigrationInventoryService::class)->inventory('SHOW1');

    expect($again['discovered'])->toBe(0)
        ->and($again['unchanged'])->toBe(5)
        ->and(PhotoIssue::query()->where('issue_type', PhotoIssue::TYPE_MIGRATION_SOURCE_MISSING)->count())->toBe(1);
});
