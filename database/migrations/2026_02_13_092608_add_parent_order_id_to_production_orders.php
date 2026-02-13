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
        Schema::table('monox_production_orders', function (Blueprint $table) {
            $table->foreignId('parent_order_id')->nullable()->after('lot_id')->constrained('monox_production_orders')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_order_id');
        });
    }
};
