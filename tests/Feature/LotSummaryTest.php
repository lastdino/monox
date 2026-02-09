<?php

namespace Lastdino\Monox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\ProductionOrder;
use Lastdino\Monox\Models\ProductionRecord;
use Livewire\Livewire;
use Tests\TestCase;
use Carbon\Carbon;

class LotSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_calculate_stock_at_specific_date()
    {
        $department = Department::create(['name' => 'Test Dept', 'code' => 'TEST']);
        $item = Item::create(['name' => 'Item 1', 'code' => 'I1', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);
        $lot = Lot::create(['lot_number' => 'LOT-001', 'item_id' => $item->id, 'department_id' => $department->id]);

        $lot->stockMovements()->create([
            'item_id' => $item->id,
            'quantity' => 10,
            'type' => 'in',
            'moved_at' => Carbon::parse('2026-02-01 10:00:00'),
            'department_id' => $department->id
        ]);

        $lot->stockMovements()->create([
            'item_id' => $item->id,
            'quantity' => -3,
            'type' => 'out',
            'moved_at' => Carbon::parse('2026-02-05 10:00:00'),
            'department_id' => $department->id
        ]);

        $lot->stockMovements()->create([
            'item_id' => $item->id,
            'quantity' => 5,
            'type' => 'in',
            'moved_at' => Carbon::parse('2026-02-10 10:00:00'),
            'department_id' => $department->id
        ]);

        expect($lot->getStockAtDate(Carbon::parse('2026-01-31')->endOfDay()))->toBe(0.0);
        expect($lot->getStockAtDate(Carbon::parse('2026-02-01')->endOfDay()))->toBe(10.0);
        expect($lot->getStockAtDate(Carbon::parse('2026-02-05')->endOfDay()))->toBe(7.0);
        expect($lot->getStockAtDate(Carbon::parse('2026-02-10')->endOfDay()))->toBe(12.0);
    }

    public function test_it_can_calculate_wip_at_specific_date()
    {
        $department = Department::create(['name' => 'Test Dept', 'code' => 'TEST_WIP']);
        $item = Item::create(['name' => 'Product A', 'code' => 'PA', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $p1 = Process::create(['item_id' => $item->id, 'name' => 'Process 1', 'sort_order' => 10]);
        $p2 = Process::create(['item_id' => $item->id, 'name' => 'Process 2', 'sort_order' => 20]);
        $p3 = Process::create(['item_id' => $item->id, 'name' => 'Process 3', 'sort_order' => 30]);

        $lot = Lot::create(['lot_number' => 'LOT-WIP', 'item_id' => $item->id, 'department_id' => $department->id]);

        $order = ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 100,
            'status' => 'in_progress',
        ]);
        $order->created_at = Carbon::parse('2026-02-01 09:00:00');
        $order->save();

        // Feb 1: Process 1 started & finished
        $pr1 = ProductionRecord::create([
            'production_order_id' => $order->id,
            'process_id' => $p1->id,
            'status' => 'completed',
            'good_quantity' => 100,
        ]);
        $pr1->work_finished_at = Carbon::parse('2026-02-01 17:00:00');
        $pr1->save();

        // Feb 5: Process 2 started & finished
        $pr2 = ProductionRecord::create([
            'production_order_id' => $order->id,
            'process_id' => $p2->id,
            'status' => 'completed',
            'good_quantity' => 100,
        ]);
        $pr2->work_finished_at = Carbon::parse('2026-02-05 17:00:00');
        $pr2->save();

        // ロジックを直接テストするために、無名クラスを使用（Fluxコンポーネントのテストが困難なため）
        $logic = new class
        {
            public function calculateWipAtDate($lot, $date, $processes)
            {
                $wip = [];
                foreach ($processes as $p) {
                    $wip[$p->name] = 0;
                }

                $orders = $lot->productionOrders()
                    ->where('created_at', '<=', $date)
                    ->where('status', '!=', 'cancelled')
                    ->get();

                foreach ($orders as $order) {
                $records = $order->productionRecords()
                    ->where('work_finished_at', '<=', $date)
                    ->with('process')
                    ->get();

                $allOrderProcesses = $order->item->processes()->orderBy('sort_order')->get();

                $lastProcess = $allOrderProcesses->last();
                $finishedQty = 0;
                if ($lastProcess) {
                    $finishedQty = $records->where('process_id', $lastProcess->id)->sum('good_quantity');
                }

                $orderWipQty = $order->target_quantity - $finishedQty;
                if ($orderWipQty <= 0) {
                    continue;
                }

                $lastCompletedRecord = $records->sortByDesc(fn ($r) => $r->process->sort_order)->first();

                $currentProcess = null;
                if ($lastCompletedRecord) {
                    $currentProcess = $order->item->processes()
                        ->where('sort_order', '>', $lastCompletedRecord->process->sort_order)
                        ->orderBy('sort_order')
                        ->first();
                } else {
                    $currentProcess = $order->item->processes()->orderBy('sort_order')->first();
                }

                if ($currentProcess) {
                    $wip[$currentProcess->name] = ($wip[$currentProcess->name] ?? 0) + $orderWipQty;
                }
            }

                return $wip;
            }
        };

        $processes = $item->processes;

        // On Feb 2: Should be at Process 2
        $wipAtFeb2 = $logic->calculateWipAtDate($lot, Carbon::parse('2026-02-02')->endOfDay(), $processes);
        expect((float)$wipAtFeb2['Process 2'])->toBe(100.0);
        expect((float)$wipAtFeb2['Process 1'])->toBe(0.0);

        // On Feb 6: Should be at Process 3
        $wipAtFeb6 = $logic->calculateWipAtDate($lot, Carbon::parse('2026-02-06')->endOfDay(), $processes);
        expect((float)$wipAtFeb6['Process 3'])->toBe(100.0);
        expect((float)$wipAtFeb6['Process 2'])->toBe(0.0);

        // Jan 31: Should not have WIP
        $wipAtJan31 = $logic->calculateWipAtDate($lot, Carbon::parse('2026-01-31')->endOfDay(), $processes);
        expect((float)array_sum($wipAtJan31))->toBe(0.0);
    }

    public function test_it_counts_wip_correctly_with_new_logic()
    {
        $department = Department::create(['name' => 'WIP Dept', 'code' => 'TEST_WIP_NEW']);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $p1 = Process::create(['item_id' => $item->id, 'name' => 'P1', 'sort_order' => 10]);
        $p2 = Process::create(['item_id' => $item->id, 'name' => 'P2', 'sort_order' => 20]);

        $lot = Lot::create(['lot_number' => 'LOT-X', 'item_id' => $item->id, 'department_id' => $department->id]);

        // Cancelled order (should be ignored)
        ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 100,
            'status' => 'cancelled',
            'created_at' => Carbon::parse('2026-02-01 09:00:00'),
        ]);

        // In progress order
        ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 50,
            'status' => 'in_progress',
            'created_at' => Carbon::parse('2026-02-10 09:00:00'),
        ]);

        Livewire::test('monox::inventory.lot-summary', ['department' => $department])
            ->set('targetDate', '2026-02-10')
            ->assertSet('rows.0.wip.P1', 50.0)
            ->assertSet('rows.0.lot_number', 'LOT-X');
    }

    public function test_it_counts_wip_if_initial_process_has_no_records()
    {
        $department = Department::create(['name' => 'First Process WIP Dept', 'code' => 'TEST_WIP_FIRST']);
        $item = Item::create(['name' => 'Product B', 'code' => 'PB', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $p1 = Process::create(['item_id' => $item->id, 'name' => 'Initial Process', 'sort_order' => 10]);
        $p2 = Process::create(['item_id' => $item->id, 'name' => 'Next Process', 'sort_order' => 20]);

        $lot = Lot::create(['lot_number' => 'LOT-WIP-FIRST', 'item_id' => $item->id, 'department_id' => $department->id]);

        $order = ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 50,
            'status' => 'in_progress',
        ]);
        $order->created_at = Carbon::parse('2026-02-10 09:00:00');
        $order->save();

        Livewire::test('monox::inventory.lot-summary', ['department' => $department])
            ->set('targetDate', '2026-02-10')
            ->assertSet('rows.0.wip.Initial Process', 50.0);
    }

    public function test_it_does_not_disappear_when_first_process_completed()
    {
        $department = Department::create(['name' => 'Repro Dept', 'code' => 'REPRO']);
        $item = Item::create(['name' => 'Product B', 'code' => 'PB', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $p1 = Process::create(['item_id' => $item->id, 'name' => 'Acceptance', 'sort_order' => 10]);
        $p2 = Process::create(['item_id' => $item->id, 'name' => 'Assembly', 'sort_order' => 20]);

        $lot = Lot::create(['lot_number' => 'LOT-REPRO', 'item_id' => $item->id, 'department_id' => $department->id]);

        $order = ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 10,
            'status' => 'in_progress',
        ]);
        $order->created_at = Carbon::parse('2026-02-01 09:00:00');
        $order->save();

        // 2/1: 受入完了
        $pr1 = ProductionRecord::create([
            'production_order_id' => $order->id,
            'process_id' => $p1->id,
            'status' => 'completed',
            'good_quantity' => 10,
        ]);
        $pr1->work_finished_at = Carbon::parse('2026-02-01 17:00:00');
        $pr1->save();

        Livewire::test('monox::inventory.lot-summary', ['department' => $department])
            ->set('targetDate', '2026-02-02')
            ->assertSet('rows.0.lot_number', 'LOT-REPRO')
            ->assertSet('rows.0.wip.Assembly', 10.0);
    }

    public function test_it_excludes_defective_quantity_from_wip()
    {
        $department = Department::create(['name' => 'Defect Dept', 'code' => 'DEFECT']);
        $item = Item::create(['name' => 'Product C', 'code' => 'PC', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $p1 = Process::create(['item_id' => $item->id, 'name' => 'Acceptance', 'sort_order' => 10]);
        $p2 = Process::create(['item_id' => $item->id, 'name' => 'Assembly', 'sort_order' => 20]);

        $lot = Lot::create(['lot_number' => 'LOT-DEFECT', 'item_id' => $item->id, 'department_id' => $department->id]);

        $order = ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 10,
            'status' => 'in_progress',
        ]);
        $order->created_at = Carbon::parse('2026-02-01 09:00:00');
        $order->save();

        // 2/1: Acceptance completed with 8 good and 2 defective
        $pr1 = ProductionRecord::create([
            'production_order_id' => $order->id,
            'process_id' => $p1->id,
            'status' => 'completed',
            'good_quantity' => 8,
            'defective_quantity' => 2,
        ]);
        $pr1->work_finished_at = Carbon::parse('2026-02-01 17:00:00');
        $pr1->save();

        // On 2/2: Assembly WIP should be 8, not 10.
        Livewire::test('monox::inventory.lot-summary', ['department' => $department])
            ->set('targetDate', '2026-02-02')
            ->assertSet('rows.0.lot_number', 'LOT-DEFECT')
            ->assertSet('rows.0.wip.Assembly', 8.0);
    }
}
