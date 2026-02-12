<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            // DashboardController expects this
            if (!Schema::hasColumn('scenes', 'municipal')) {
                $table->string('municipal')->nullable()->after('title');
            }

            // Store original pano for drafts only (NO krpano)
            if (!Schema::hasColumn('scenes', 'draft_panorama_path')) {
                $table->string('draft_panorama_path')->nullable()->after('panorama_path');
            }

            // Optional: pipeline input path used when publishing
            if (!Schema::hasColumn('scenes', 'staging_panorama_path')) {
                $table->string('staging_panorama_path')->nullable()->after('draft_panorama_path');
            }
        });

        // Convert address to TEXT if it exists and is string
        // (MySQL requires doctrine/dbal or raw SQL; here's raw SQL safe enough)
        if (Schema::hasColumn('scenes', 'address')) {
            Schema::table('scenes', function (Blueprint $table) {
                // If you already have long text, better to use text()
                $table->text('address')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            if (Schema::hasColumn('scenes', 'staging_panorama_path')) {
                $table->dropColumn('staging_panorama_path');
            }
            if (Schema::hasColumn('scenes', 'draft_panorama_path')) {
                $table->dropColumn('draft_panorama_path');
            }
            if (Schema::hasColumn('scenes', 'municipal')) {
                $table->dropColumn('municipal');
            }

            // optional: revert address back to string (not always desirable)
            // $table->string('address')->nullable()->change();
        });
    }
};
