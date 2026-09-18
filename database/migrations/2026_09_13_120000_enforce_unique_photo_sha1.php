<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce uniqueness of the exact source-byte hash (photos.sha1).
 *
 * An install that predates the import resolver can legitimately hold the same
 * source bytes more than once (the same frame sold under a portraits session
 * and the main show, for example). Those rows are published history: this
 * migration never deletes, merges, or rewrites them, and it no longer refuses
 * to run because of them. When duplicates exist it leaves the index to the
 * follow-up migration `2026_09_18_000002_grandfather_legacy_duplicate_photo_hashes`,
 * which exempts exactly those rows and enforces uniqueness for everything else.
 * Null hashes (incomplete legacy rows) are left alone; unique indexes treat
 * NULLs as distinct.
 *
 * The original non-unique photos_sha1_index is intentionally retained; the
 * unique index is additive and rollback removes only the unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicates = DB::table('photos')
            ->select('sha1')
            ->whereNotNull('sha1')
            ->groupBy('sha1')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            // A full unique index cannot be built over existing duplicates.
            // The follow-up migration builds the index that exempts them.
            return;
        }

        Schema::table('photos', function (Blueprint $table) {
            $table->unique('sha1', 'photos_sha1_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('photos', 'photos_sha1_unique')) {
            return;
        }

        Schema::table('photos', function (Blueprint $table) {
            $table->dropUnique('photos_sha1_unique');
        });
    }
};
