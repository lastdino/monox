<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monox_sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('monox_departments')->cascadeOnDelete();
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

        Schema::create('monox_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('monox_departments')->cascadeOnDelete();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('monox_shipments');
        Schema::dropIfExists('monox_sales_orders');
    }
};
