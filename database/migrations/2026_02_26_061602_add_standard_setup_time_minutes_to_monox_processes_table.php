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
        Schema::table('monox_processes', function (Blueprint $table) {
            $table->decimal('standard_setup_time_minutes', 12, 4)->nullable()->after('standard_time_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_processes', function (Blueprint $table) {
            $table->dropColumn('standard_setup_time_minutes');
        });
    }
};
