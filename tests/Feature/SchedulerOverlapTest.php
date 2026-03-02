<?php

namespace Lastdino\Monox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Equipment;
use Lastdino\Monox\Models\ProductionOrder;
use Lastdino\Monox\Models\ProductionSchedule;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Livewire\Livewire;
use Carbon\Carbon;
use Tests\TestCase;

class SchedulerOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prevents_overlapping_schedules_on_the_same_equipment()
    {
    $department = Department::query()->create(['code' => 'D1', 'name' => 'Dept 1']);
    $equipment = Equipment::query()->create(['code' => 'E1', 'name' => 'Equip 1']);
    $equipment->departments()->attach($department->id);

    $item = Item::query()->create(['code' => 'I1', 'name' => 'Item 1', 'type' => 'raw', 'unit' => 'pc', 'department_id' => $department->id]);
    $process = Process::query()->create(['item_id' => $item->id, 'code' => 'P1', 'name' => 'Process 1']);
    $process->equipments()->attach($equipment->id);

    $lot = Lot::query()->create(['lot_number' => 'LOT001', 'item_id' => $item->id]);

    $order = ProductionOrder::query()->create([
        'department_id' => $department->id,
        'item_id' => $item->id,
        'lot_id' => $lot->id,
        'target_quantity' => 100,
        'status' => 'confirmed',
    ]);

    // 既存のスケジュール: 2026-03-01 10:00 - 12:00
    $existingSchedule = ProductionSchedule::query()->create([
        'production_order_id' => $order->id,
        'process_id' => $process->id,
        'equipment_id' => $equipment->id,
        'scheduled_start_at' => '2026-03-01 10:00:00',
        'scheduled_end_at' => '2026-03-01 12:00:00',
        'status' => 'confirmed',
    ]);

    // 新しいスケジュール (最初は別の時間): 2026-03-01 13:00 - 15:00
    $newSchedule = ProductionSchedule::query()->create([
        'production_order_id' => $order->id,
        'process_id' => $process->id,
        'equipment_id' => null, // 未割当
        'scheduled_start_at' => '2026-03-01 13:00:00',
        'scheduled_end_at' => '2026-03-01 15:00:00',
        'status' => 'confirmed',
    ]);

    // 重複する時間に移動を試みる: 2026-03-01 11:00 (既存の10:00-12:00と重なる)
    // viewMode = 'hour' の場合を想定
    Livewire::test('monox::production.⚡scheduler', ['department' => $department])
        ->set('viewMode', 'hour')
        ->call('updateScheduleDate', $newSchedule->id, 0, '2026-03-01 11:00|' . $equipment->id);

        // 更新されていないことを確認
        $newSchedule->refresh();
        $this->assertNull($newSchedule->equipment_id);
    }

    public function test_it_automatically_positions_after_existing_schedule_in_day_view()
    {
        $department = Department::query()->create(['code' => 'D2', 'name' => 'Dept 2']);
        $equipment = Equipment::query()->create(['code' => 'E2', 'name' => 'Equip 2']);
        $equipment->departments()->attach($department->id);

        $item = Item::query()->create(['code' => 'I2', 'name' => 'Item 2', 'type' => 'raw', 'unit' => 'pc', 'department_id' => $department->id]);
        $process = Process::query()->create(['item_id' => $item->id, 'code' => 'P2', 'name' => 'Process 2']);
        $process->equipments()->attach($equipment->id);

        $lot = Lot::query()->create(['lot_number' => 'LOT002', 'item_id' => $item->id]);

        $order = ProductionOrder::query()->create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 100,
            'status' => 'confirmed',
        ]);

        // 既存のスケジュール: 2026-03-01 10:00 - 12:00
        ProductionSchedule::query()->create([
            'production_order_id' => $order->id,
            'process_id' => $process->id,
            'equipment_id' => $equipment->id,
            'scheduled_start_at' => '2026-03-01 10:00:00',
            'scheduled_end_at' => '2026-03-01 12:00:00',
            'status' => 'confirmed',
        ]);

        // 移動させるスケジュール ( duration = 1 hour )
        $newSchedule = ProductionSchedule::query()->create([
            'production_order_id' => $order->id,
            'process_id' => $process->id,
            'equipment_id' => null,
            'scheduled_start_at' => '2026-03-02 08:00:00',
            'scheduled_end_at' => '2026-03-02 09:00:00',
            'status' => 'confirmed',
        ]);

        // 日表示で 2026-03-01 にドロップ
        Livewire::test('monox::production.⚡scheduler', ['department' => $department])
            ->set('viewMode', 'day')
            ->call('updateScheduleDate', $newSchedule->id, 0, '2026-03-01|' . $equipment->id);

        $newSchedule->refresh();

        // 既存のスケジュールの後ろ（12:00）に配置されているはず
        expect($newSchedule->equipment_id)->toBe($equipment->id);
        expect($newSchedule->scheduled_start_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 12:00:00');
        expect($newSchedule->scheduled_end_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 13:00:00');
    }
}
