<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->text('google_map_link')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('google_map_link', 255)->nullable()->change();
        });
    }
};
