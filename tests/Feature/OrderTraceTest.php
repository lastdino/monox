<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Partner;
use Lastdino\Monox\Models\SalesOrder;
use Lastdino\Monox\Models\Shipment;
use Livewire\Livewire;
use Tests\TestCase;

class OrderTraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_trace_by_order_number()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $order = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'order_number' => 'SO-TRACE-001',
            'order_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'quantity' => 10,
            'status' => 'pending',
        ]);

        Livewire::test('monox::orders.trace', ['department' => $department])
            ->set('search', 'SO-TRACE-001')
            ->call('trace')
            ->assertSet('order.id', $order->id)
            ->assertSee('SO-TRACE-001')
            ->assertSee('Customer A')
            ->assertSee('Product X');
    }

    public function test_it_can_trace_by_lot_number()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $order1 = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'order_number' => 'SO-LOT-001',
            'quantity' => 10,
            'status' => 'shipped',
        ]);

        $order2 = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'order_number' => 'SO-LOT-002',
            'quantity' => 20,
            'status' => 'shipped',
        ]);

        $lot = Lot::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_number' => 'LOT-TRACE-001',
        ]);

        Shipment::create([
            'department_id' => $department->id,
            'sales_order_id' => $order1->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'shipment_number' => 'SH-001',
            'shipping_date' => now()->toDateString(),
            'quantity' => 10,
            'status' => 'shipped',
        ]);

        Shipment::create([
            'department_id' => $department->id,
            'sales_order_id' => $order2->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'shipment_number' => 'SH-002',
            'shipping_date' => now()->toDateString(),
            'quantity' => 20,
            'status' => 'shipped',
        ]);

        Livewire::test('monox::orders.trace', ['department' => $department])
            ->set('searchType', 'lot')
            ->set('search', 'LOT-TRACE-001')
            ->call('trace')
            ->assertSet('lot.id', $lot->id)
            ->assertSee('LOT-TRACE-001')
            ->assertSee('SO-LOT-001')
            ->assertSee('SO-LOT-002');
    }

    public function test_it_shows_error_when_nothing_found()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);

        Livewire::test('monox::orders.trace', ['department' => $department])
            ->set('search', 'NON-EXISTENT')
            ->call('trace')
            ->assertSet('order', null)
            ->assertSee('「NON-EXISTENT」に該当する受注またはロットは見つかりませんでした。');
    }

    public function test_it_can_trace_component_lot_to_shipments()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $itemA = Item::create(['name' => 'Product A', 'code' => 'PA', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);
        $itemB = Item::create(['name' => 'Component B', 'code' => 'CB', 'type' => 'material', 'unit' => 'pcs', 'department_id' => $department->id]);

        $process = \Lastdino\Monox\Models\Process::create([
            'name' => 'Assembly',
            'department_id' => $department->id,
            'item_id' => $itemA->id,
            'sort_order' => 1,
        ]);

        $field = \Lastdino\Monox\Models\ProductionAnnotationField::create([
            'process_id' => $process->id,
            'label' => 'Component Lot',
            'field_key' => 'comp_lot',
            'type' => 'lot',
            'sort_order' => 1,
            'x_percent' => 0,
            'y_percent' => 0,
            'width_percent' => 0,
            'height_percent' => 0,
        ]);

        // 1. 不具合部品ロットB
        $lotB = Lot::create([
            'department_id' => $department->id,
            'item_id' => $itemB->id,
            'lot_number' => 'LOT-BAD-B',
        ]);

        // 2. 製品AのロットAを製造
        $lotA = Lot::create([
            'department_id' => $department->id,
            'item_id' => $itemA->id,
            'lot_number' => 'LOT-PROD-A',
        ]);

        $prodOrder = \Lastdino\Monox\Models\ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $itemA->id,
            'lot_id' => $lotA->id,
            'target_quantity' => 10,
            'status' => 'completed',
        ]);

        $prodRecord = \Lastdino\Monox\Models\ProductionRecord::create([
            'production_order_id' => $prodOrder->id,
            'process_id' => $process->id,
            'status' => 'finished',
            'good_quantity' => 10,
        ]);

        // ロットBを材料として使用した記録
        \Lastdino\Monox\Models\ProductionAnnotationValue::create([
            'production_record_id' => $prodRecord->id,
            'field_id' => $field->id,
            'lot_id' => $lotB->id,
            'quantity' => 1,
        ]);

        // 3. 製品ロットAを出荷
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);
        $order = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $itemA->id,
            'order_number' => 'SO-TARGET-001',
            'quantity' => 10,
            'status' => 'shipped',
        ]);

        Shipment::create([
            'department_id' => $department->id,
            'sales_order_id' => $order->id,
            'item_id' => $itemA->id,
            'lot_id' => $lotA->id,
            'shipment_number' => 'SH-001',
            'shipping_date' => now()->toDateString(),
            'quantity' => 10,
            'status' => 'shipped',
        ]);

        // ロットBで検索して、出荷先(SO-TARGET-001)が見つかることを確認
        Livewire::test('monox::orders.trace', ['department' => $department])
            ->set('searchType', 'lot')
            ->set('search', 'LOT-BAD-B')
            ->call('trace')
            ->assertSet('lot.id', $lotB->id)
            ->assertSee('LOT-BAD-B')
            ->assertSee('このロットを使用した製品ロット')
            ->assertSee('LOT-PROD-A')
            ->assertSee('SO-TARGET-001');
    }
}
