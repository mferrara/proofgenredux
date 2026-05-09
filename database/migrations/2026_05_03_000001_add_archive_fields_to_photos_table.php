<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->string('archive_path')->nullable()->after('file_type');
            $table->string('archive_sha1', 40)->nullable()->after('archive_path');
            $table->unsignedBigInteger('archive_size')->nullable()->after('archive_sha1');
            $table->timestamp('archived_at')->nullable()->after('archive_size');

            $table->index(['show_class_id', 'archived_at'], 'idx_show_class_archived_at');
            $table->index('archive_sha1', 'idx_photos_archive_sha1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex('idx_show_class_archived_at');
            $table->dropIndex('idx_photos_archive_sha1');
            $table->dropColumn([
                'archive_path',
                'archive_sha1',
                'archive_size',
                'archived_at',
            ]);
        });
    }
};
