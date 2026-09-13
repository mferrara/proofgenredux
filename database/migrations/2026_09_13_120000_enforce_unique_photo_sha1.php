<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce global uniqueness of the exact source-byte hash (photos.sha1).
 *
 * The preflight deliberately refuses to run when existing non-null duplicate
 * hashes are present. It never deletes, merges, or rewrites photo rows — the
 * operator must resolve duplicates deliberately. Null hashes (incomplete
 * legacy rows) are left alone; unique indexes treat NULLs as distinct.
 *
 * The original non-unique photos_sha1_index is intentionally retained; the
 * unique index is additive and rollback removes only the unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('photos')
            ->select('sha1', DB::raw('COUNT(*) as photo_count'))
            ->whereNotNull('sha1')
            ->groupBy('sha1')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('sha1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $summary = $duplicates
                ->map(fn ($row) => $row->sha1.' ('.$row->photo_count.' photos)')
                ->implode(', ');

            throw new RuntimeException(
                'Cannot enforce unique photos.sha1: existing duplicate hashes found: '.$summary.
                '. Resolve these duplicates manually before running this migration; '.
                'no photos were deleted or modified.'
            );
        }

        Schema::table('photos', function (Blueprint $table) {
            $table->unique('sha1', 'photos_sha1_unique');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropUnique('photos_sha1_unique');
        });
    }
};
