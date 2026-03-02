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
        if (! Schema::hasColumn('monox_departments', 'api_token')) {
            Schema::table('monox_departments', function (Blueprint $table) {
                $table->string('api_token', 64)->nullable()->unique()->after('description');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_departments', function (Blueprint $table) {
            $table->dropColumn('api_token');
        });
    }
};
