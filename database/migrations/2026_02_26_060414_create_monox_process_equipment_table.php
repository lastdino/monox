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
        Schema::create('monox_process_equipment', function (Blueprint $table) {
            $table->foreignId('process_id')->constrained('monox_processes')->cascadeOnDelete();
            // Equipmentは外部テーブルに差し替えられる可能性があるため、foreignIdではなくunsignedBigIntegerを使用
            $table->unsignedBigInteger('equipment_id');
            $table->timestamps();
            $table->primary(['process_id', 'equipment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monox_process_equipment');
    }
};
