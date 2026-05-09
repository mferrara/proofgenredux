<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decouple the proofgen Show identity from the ferraraphoto-side slug.
 *
 * Today proofgen and ferraraphoto match by hand-shared convention: the show
 * directory name on proofgen (= Show.id) must equal the Show.slug on
 * ferraraphoto. This works as long as nobody renames either side.
 *
 * The new nullable column lets the operator explicitly set the matching
 * ferraraphoto slug when it diverges. NULL means "fall back to Show.id"
 * (current behavior). Path-building helpers consult the effective slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->string('ferraraphoto_show_slug')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('ferraraphoto_show_slug');
        });
    }
};
