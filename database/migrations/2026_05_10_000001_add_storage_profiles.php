<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_profiles', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('label');
            $table->string('driver', 16);
            $table->string('bucket')->nullable();
            $table->string('region')->nullable();
            $table->string('endpoint')->nullable();
            $table->boolean('use_path_style')->default(false);
            $table->string('root')->nullable();
            $table->string('fingerprint', 64)->unique();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_writable')->default(true);
            $table->timestamps();
        });

        DB::table('storage_profiles')->insert([
            'id' => 'legacy-local',
            'label' => 'Legacy (production server filesystem via SFTP)',
            'driver' => 'local',
            'bucket' => null,
            'region' => null,
            'endpoint' => null,
            'use_path_style' => false,
            'root' => null,
            'fingerprint' => 'legacy-local-v1',
            'is_active' => true,
            'is_writable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('shows', function (Blueprint $table) {
            $table->string('storage_profile_id', 64)->nullable()->after('ferraraphoto_show_slug');
            $table->index('storage_profile_id');
            $table->foreign('storage_profile_id')->references('id')->on('storage_profiles')->nullOnDelete();
        });

        DB::table('shows')->whereNull('storage_profile_id')->update([
            'storage_profile_id' => 'legacy-local',
        ]);

        Schema::table('photos', function (Blueprint $table) {
            $table->string('proof_thm_key')->nullable()->after('proofs_uploaded_at');
            $table->string('proof_std_key')->nullable()->after('proof_thm_key');
            $table->string('web_image_key')->nullable()->after('web_image_uploaded_at');
            $table->string('high_res_image_key')->nullable()->after('highres_image_uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn([
                'proof_thm_key',
                'proof_std_key',
                'web_image_key',
                'high_res_image_key',
            ]);
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->dropForeign(['storage_profile_id']);
            $table->dropIndex(['storage_profile_id']);
            $table->dropColumn('storage_profile_id');
        });

        Schema::dropIfExists('storage_profiles');
    }
};
