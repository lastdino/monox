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
        Schema::table('monox_stock_movements', function (Blueprint $table) {
            $table->boolean('is_external_sync')->default(false)->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_stock_movements', function (Blueprint $table) {
            $table->dropColumn('is_external_sync');
        });
    }
};
