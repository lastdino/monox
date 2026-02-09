<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monox_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('unit');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('monox_boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->foreignId('child_item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['parent_item_id', 'child_item_id']);
        });

        Schema::create('monox_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('monox_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->string('type');
            $table->string('reason')->nullable();
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('monox_partners', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monox_partners');
        Schema::dropIfExists('monox_stock_movements');
        Schema::dropIfExists('monox_boms');
        Schema::dropIfExists('monox_items');
    }
};
