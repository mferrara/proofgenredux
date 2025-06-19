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
            $table->timestamp('highres_image_generated_at')->nullable()->after('web_image_uploaded_at');
            $table->timestamp('highres_image_uploaded_at')->nullable()->after('highres_image_generated_at');

            // Add indexes for performance
            $table->index(['show_class_id', 'highres_image_generated_at'], 'idx_show_class_highres_image_generated_at');
            $table->index(['show_class_id', 'highres_image_uploaded_at'], 'idx_show_class_highres_image_uploaded_at');
            $table->index(['show_class_id', 'highres_image_generated_at', 'highres_image_uploaded_at'], 'idx_show_class_highres_image_staleness');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex('idx_show_class_highres_image_staleness');
            $table->dropIndex('idx_show_class_highres_image_uploaded_at');
            $table->dropIndex('idx_show_class_highres_image_generated_at');

            $table->dropColumn('highres_image_uploaded_at');
            $table->dropColumn('highres_image_generated_at');
        });
    }
};
