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
        // スキーマ拡張: 各トランザクション系に department_id を追加
        if (Schema::hasTable('monox_boms') && ! Schema::hasColumn('monox_boms', 'department_id')) {
            Schema::table('monox_boms', function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->after('id')->constrained('monox_departments')->nullOnDelete();
            });
        }
        if (Schema::hasTable('monox_stock_movements') && ! Schema::hasColumn('monox_stock_movements', 'department_id')) {
            Schema::table('monox_stock_movements', function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->after('id')->constrained('monox_departments')->nullOnDelete();
            });
        }
        if (Schema::hasTable('monox_lots') && ! Schema::hasColumn('monox_lots', 'department_id')) {
            Schema::table('monox_lots', function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->after('id')->constrained('monox_departments')->nullOnDelete();
            });
        }

        // 既定部門「製造」を作成（存在しない場合）し、既存データを割当
        $deptId = null;
        if (! \DB::table('monox_departments')->where('name', '製造')->exists()) {
            $deptId = (int) \DB::table('monox_departments')->insertGetId([
                'code' => 'mfg',
                'name' => '製造',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $deptId = (int) \DB::table('monox_departments')->where('name', '製造')->value('id');
        }

        foreach (['monox_items', 'monox_partners', 'monox_boms', 'monox_stock_movements', 'monox_lots'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'department_id')) {
                \DB::table($table)->whereNull('department_id')->update(['department_id' => $deptId]);
            }
        }

        // BOM は親品目の部門に合わせる
        if (Schema::hasTable('monox_boms')) {
            $boms = \DB::table('monox_boms')->get(['id', 'parent_item_id']);
            foreach ($boms as $bom) {
                $pid = \DB::table('monox_items')->where('id', $bom->parent_item_id)->value('department_id');
                if ($pid) {
                    \DB::table('monox_boms')->where('id', $bom->id)->update(['department_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['monox_boms', 'monox_stock_movements', 'monox_lots'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'department_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('department_id');
                });
            }
        }

        // 部門データ自体は残します（他参照のため）
    }
};
