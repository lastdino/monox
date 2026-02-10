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
            $table->decimal('inventory_alert_quantity', 15, 4)->default(0)->after('unit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_items', function (Blueprint $table) {
            $table->dropColumn('inventory_alert_quantity');
        });
    }
};
