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
    Schema::table('scenes', function (Blueprint $table) {
        if (!Schema::hasColumn('scenes', 'scene_id')) {
            $table->string('scene_id')->nullable()->index();
        }

        if (!Schema::hasColumn('scenes', 'status')) {
            $table->string('status')->default('queued')->index();
        }
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            //
        });
    }
};
