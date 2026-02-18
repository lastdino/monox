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
        Schema::table('monox_items', function (Blueprint $table) {
            $table->boolean('sync_to_procurement')->default(false)->after('auto_inventory_update');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_items', function (Blueprint $table) {
            $table->dropColumn('sync_to_procurement');
        });
    }
};
