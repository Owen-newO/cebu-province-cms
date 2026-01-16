<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
public function down(): void
{
    Schema::table('scenes', function (Blueprint $table) {
        if (Schema::hasColumn('scenes', 'scene_id')) {
            $table->dropColumn('scene_id');
        }

        if (Schema::hasColumn('scenes', 'status')) {
            $table->dropColumn('status');
        }
    });
}
};
