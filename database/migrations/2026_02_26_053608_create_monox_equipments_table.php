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
        Schema::create('monox_equipments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // active, maintenance, inactiveなど
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('monox_department_equipment', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('monox_departments')->cascadeOnDelete();
            // 外部テーブル（asset-guardなど）をモデルとして差し替えて使用する場合があるため、
            // equipment_idには特定のテーブルへの外部キー制約を設けない。
            $table->unsignedBigInteger('equipment_id');
            $table->primary(['department_id', 'equipment_id']);
        });

        Schema::table('monox_production_schedules', function (Blueprint $table) {
            // 同様に、モデル差し替えに備えて外部キー制約なしのカラムを追加
            $table->unsignedBigInteger('equipment_id')->nullable()->after('worker_id');
        });

        Schema::table('monox_production_records', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_id')->nullable()->after('worker_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_production_records', function (Blueprint $table) {
            $table->dropColumn('equipment_id');
        });

        Schema::table('monox_production_schedules', function (Blueprint $table) {
            $table->dropColumn('equipment_id');
        });

        Schema::dropIfExists('monox_department_equipment');
        Schema::dropIfExists('monox_equipments');
    }
};
