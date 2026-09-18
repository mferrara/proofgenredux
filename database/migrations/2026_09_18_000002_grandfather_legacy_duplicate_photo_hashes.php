<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keep source-hash uniqueness without rewriting history.
 *
 * Photos that already share a hash when this runs are flagged
 * `sha1_grandfathered` and left exactly as they are. The unique index then
 * covers every other row, so two new imports of the same bytes still cannot
 * both be inserted. A new photo that repeats a grandfathered hash is caught
 * earlier, by PhotoImportIdentityResolver, which looks at all rows.
 *
 * An install with no duplicates ends up with the same schema: the column
 * (all false) and the same partial index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('photos', 'sha1_grandfathered')) {
            Schema::table('photos', function (Blueprint $table) {
                $table->boolean('sha1_grandfathered')->default(false);
            });
        }

        $grandfathered = DB::update(
            'UPDATE photos SET sha1_grandfathered = 1 WHERE sha1 IN ('
            .'SELECT sha1 FROM photos WHERE sha1 IS NOT NULL GROUP BY sha1 HAVING COUNT(*) > 1)'
        );

        if ($grandfathered > 0) {
            Log::warning('Grandfathered photos that already shared a source hash; they are exempt from the unique index.', [
                'photos' => $grandfathered,
            ]);
        }

        if (Schema::hasIndex('photos', 'photos_sha1_unique')) {
            Schema::table('photos', function (Blueprint $table) {
                $table->dropUnique('photos_sha1_unique');
            });
        }

        // Partial indexes are not expressible through the schema builder.
        // SQLite (every Proofgen install) and PostgreSQL support them.
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX photos_sha1_unique ON photos (sha1) WHERE sha1_grandfathered = 0');

            return;
        }

        if ($grandfathered === 0) {
            Schema::table('photos', function (Blueprint $table) {
                $table->unique('sha1', 'photos_sha1_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('photos', 'photos_sha1_unique')) {
            Schema::table('photos', function (Blueprint $table) {
                $table->dropUnique('photos_sha1_unique');
            });
        }

        $hasGrandfathered = DB::table('photos')->where('sha1_grandfathered', true)->exists();

        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn('sha1_grandfathered');
        });

        // The full index can only come back when nothing needed the exemption.
        if (! $hasGrandfathered) {
            Schema::table('photos', function (Blueprint $table) {
                $table->unique('sha1', 'photos_sha1_unique');
            });
        }
    }
};
