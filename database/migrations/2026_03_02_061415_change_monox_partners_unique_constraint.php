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
        Schema::table('monox_partners', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['department_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monox_partners', function (Blueprint $table) {
            $table->dropUnique(['department_id', 'code']);
            $table->unique(['code']);
        });
    }
};
