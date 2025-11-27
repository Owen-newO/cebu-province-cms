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
            $table->string('google_map_link')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('tiktok')->nullable();
        });
    } 

    public function down()
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn([
                'google_map_link',
                'contact_number',
                'email',
                'website',
                'facebook',
                'instagram',
                'tiktok'
            ]);
        });
    }
};


