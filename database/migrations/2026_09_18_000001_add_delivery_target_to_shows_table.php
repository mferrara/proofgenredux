<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery owns where a show's files are delivered
 * (`GET /api/v1/delivery-target`). `delivery_target` is the last answer this
 * show accepted - the baseline that detects a destination change after files
 * were already uploaded. `delivery_target_pending` is a differing answer
 * waiting for the operator to accept it. Both NULL means "local SFTP settings".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->json('delivery_target')->nullable();
            $table->json('delivery_target_pending')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn(['delivery_target', 'delivery_target_pending']);
        });
    }
};
