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
        Schema::create('monox_production_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('monox_production_orders')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('monox_processes')->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_start_at')->comment('着手予定日時');
            $table->timestamp('scheduled_end_at')->comment('完了予定日時');
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('confirmed');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['scheduled_start_at', 'scheduled_end_at']);
        });

        // 既存のProductionRecordにschedule_idを追加
        Schema::table('monox_production_records', function (Blueprint $table) {
            $table->foreignId('production_schedule_id')->nullable()->after('process_id')->constrained('monox_production_schedules')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_production_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_schedule_id');
        });
        Schema::dropIfExists('monox_production_schedules');
    }
};
