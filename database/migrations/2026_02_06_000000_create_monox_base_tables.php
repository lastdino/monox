<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Departments
        Schema::create('monox_departments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('item_types')->nullable();
            $table->timestamps();
        });

        // 2. Item Types
        Schema::create('monox_item_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id');
            $table->string('value');
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 3. Items
        Schema::create('monox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('unit');
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->text('description')->nullable();
            $table->boolean('auto_inventory_update')->default(false);
            $table->timestamps();
        });

        // 4. Partners
        Schema::create('monox_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        // 5. BOMs
        Schema::create('monox_boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('parent_item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->foreignId('child_item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['parent_item_id', 'child_item_id']);
        });

        // 6. Lots
        Schema::create('monox_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->string('lot_number');
            $table->date('manufactured_at')->nullable();
            $table->date('expired_at')->nullable();
            $table->timestamps();
        });

        // 7. Stock Movements
        Schema::create('monox_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('monox_lots')->nullOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->string('type');
            $table->string('reason')->nullable();
            $table->foreignId('production_annotation_value_id')->nullable(); // 後で制約を追加するか、このままでも可
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();
        });

        // 8. Processes
        Schema::create('monox_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->decimal('standard_time_minutes', 12, 4)->nullable();
            $table->decimal('work_in_process_unit_price', 12, 4)->nullable();
            $table->string('template_image_path')->nullable();
            $table->boolean('share_template_with_previous')->default(false);
            $table->timestamps();
        });

        // 9. Production Orders
        Schema::create('monox_production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id');
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('monox_lots')->nullOnDelete();
            $table->decimal('target_quantity', 12, 4);
            $table->string('status')->default('pending'); // pending, in_progress, completed, cancelled
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 10. Production Records
        Schema::create('monox_production_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('monox_production_orders')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('monox_processes')->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, in_progress, completed, paused, stopped
            $table->timestamp('setup_started_at')->nullable();
            $table->timestamp('setup_finished_at')->nullable();
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->integer('total_paused_seconds')->default(0);
            $table->decimal('input_quantity', 12, 4)->default(0);
            $table->decimal('good_quantity', 12, 4)->default(0);
            $table->decimal('defective_quantity', 12, 4)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 11. Production Annotation Fields
        Schema::create('monox_production_annotation_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained('monox_processes')->cascadeOnDelete();
            $table->string('field_key');
            $table->string('label');
            $table->string('type'); // number, text, boolean, signature, material
            $table->decimal('x_percent', 5, 2);
            $table->decimal('y_percent', 5, 2);
            $table->decimal('width_percent', 5, 2)->default(5.00);
            $table->decimal('height_percent', 5, 2)->default(5.00);
            $table->decimal('target_value', 12, 4)->nullable();
            $table->decimal('min_value', 12, 4)->nullable();
            $table->decimal('max_value', 12, 4)->nullable();
            $table->foreignId('linked_item_id')->nullable()->constrained('monox_items')->nullOnDelete();
            $table->foreignId('related_field_id')->nullable()->constrained('monox_production_annotation_fields')->nullOnDelete();
            $table->timestamps();
        });

        // 12. Production Annotation Values
        Schema::create('monox_production_annotation_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_record_id')->constrained('monox_production_records')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('monox_production_annotation_fields')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('monox_lots')->nullOnDelete();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->text('value')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_within_tolerance')->default(true);
            $table->timestamps();
        });

        // Add foreign key constraint to monox_stock_movements for production_annotation_value_id
        Schema::table('monox_stock_movements', function (Blueprint $table) {
            $table->foreign('production_annotation_value_id', 'msm_pav_id_foreign')
                ->references('id')->on('monox_production_annotation_values')
                ->nullOnDelete();
        });

        // 13. Sales Orders
        Schema::create('monox_sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id');
            $table->foreignId('partner_id')->constrained('monox_partners')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->string('order_number')->index();
            $table->date('order_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->decimal('quantity', 16, 4)->default(0);
            $table->string('status')->default('open')->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // 14. Shipments
        Schema::create('monox_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id');
            $table->foreignId('sales_order_id')->nullable()->constrained('monox_sales_orders')->nullOnDelete();
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('monox_lots')->nullOnDelete();
            $table->string('shipment_number')->nullable()->index();
            $table->date('shipping_date')->nullable()->index();
            $table->decimal('quantity', 16, 4)->default(0);
            $table->string('status')->default('scheduled')->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // Seed default department if it doesn't exist
        if (\DB::table('monox_departments')->where('name', '製造')->doesntExist()) {
            \DB::table('monox_departments')->insert([
                'code' => 'mfg',
                'name' => '製造',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monox_shipments');
        Schema::dropIfExists('monox_sales_orders');
        Schema::dropIfExists('monox_stock_movements');
        Schema::dropIfExists('monox_production_annotation_values');
        Schema::dropIfExists('monox_production_annotation_fields');
        Schema::dropIfExists('monox_production_records');
        Schema::dropIfExists('monox_production_orders');
        Schema::dropIfExists('monox_processes');
        Schema::dropIfExists('monox_lots');
        Schema::dropIfExists('monox_boms');
        Schema::dropIfExists('monox_partners');
        Schema::dropIfExists('monox_items');
        Schema::dropIfExists('monox_item_types');
        Schema::dropIfExists('monox_departments');
    }
};
