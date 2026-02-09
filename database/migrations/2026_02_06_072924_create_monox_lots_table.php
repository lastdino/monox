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
        Schema::create('monox_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->string('lot_number');
            $table->date('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'lot_number']);
        });

        Schema::table('monox_stock_movements', function (Blueprint $table) {
            $table->foreignId('lot_id')->nullable()->after('item_id')->constrained('monox_lots')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lot_id');
        });

        Schema::dropIfExists('monox_lots');
    }
};
