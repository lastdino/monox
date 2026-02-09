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
use Lastdino\Monox\Models\StockMovement;
use Livewire\Livewire;
use Tests\TestCase;

class OrderShippingDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_list_and_calendar_toggle()
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
            'order_number' => 'SO-001',
            'order_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'quantity' => 10,
            'status' => 'pending',
        ]);

        Shipment::create([
            'department_id' => $department->id,
            'sales_order_id' => $order->id,
            'item_id' => $item->id,
            'shipment_number' => 'SH-001',
            'shipping_date' => now()->addDays(4)->toDateString(),
            'quantity' => 5,
            'status' => 'shipped',
        ]);

        // 一覧表示
        Livewire::test('monox::orders.dashboard', ['department' => $department])
            ->assertSee('注文・出荷管理ダッシュボード')
            ->assertSee('SO-001')
            ->assertSee('SH-001')
            // カレンダーへ切り替え
            ->set('viewMode', 'calendar')
            ->assertSeeHtml('id="orderShipCalendar"');
    }

    public function test_it_can_create_a_sales_order()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        Livewire::test('monox::orders.dashboard', ['department' => $department])
            ->set('partner_id', $partner->id)
            ->set('item_id', $item->id)
            ->set('order_number', 'SO-TEST-001')
            ->set('quantity', 50)
            ->call('createOrder')
            ->assertHasNoErrors()
            ->assertStatus(200);

        $this->assertDatabaseHas('monox_sales_orders', [
            'order_number' => 'SO-TEST-001',
            'quantity' => 50,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_it_can_update_status_and_create_shipment_with_lot()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);
        $lot = Lot::create(['lot_number' => 'LOT-001', 'item_id' => $item->id, 'department_id' => $department->id]);

        $order = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'order_number' => 'SO-001',
            'order_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'quantity' => 10,
            'status' => 'pending',
        ]);

        Livewire::test('monox::orders.dashboard', ['department' => $department])
            ->call('openStatusModal', $order->id, 'order', 'pending', $order->quantity)
            ->set('editingStatus', 'shipped')
            ->set('selectedLots', [
                ['lot_id' => $lot->id, 'quantity' => 10],
            ])
            ->set('shipmentNumber', 'SH-TEST-001')
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monox_sales_orders', [
            'id' => $order->id,
            'status' => 'shipped',
        ]);

        $this->assertDatabaseHas('monox_shipments', [
            'sales_order_id' => $order->id,
            'lot_id' => $lot->id,
            'shipment_number' => 'SH-TEST-001',
            'quantity' => 10,
        ]);
    }

    public function test_it_can_create_shipment_with_multiple_lots()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $lot1 = Lot::create(['lot_number' => 'LOT-001', 'item_id' => $item->id, 'department_id' => $department->id]);
        $lot2 = Lot::create(['lot_number' => 'LOT-002', 'item_id' => $item->id, 'department_id' => $department->id]);

        $order = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'order_number' => 'SO-001',
            'order_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'quantity' => 10,
            'status' => 'pending',
        ]);

        Livewire::test('monox::orders.dashboard', ['department' => $department])
            ->call('openStatusModal', $order->id, 'order', 'pending', $order->quantity)
            ->set('editingStatus', 'shipped')
            ->set('selectedLots', [
                ['lot_id' => $lot1->id, 'quantity' => 4],
                ['lot_id' => $lot2->id, 'quantity' => 6],
            ])
            ->set('shipmentNumber', 'SH-MULTI-001')
            ->call('updateStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monox_sales_orders', [
            'id' => $order->id,
            'status' => 'shipped',
        ]);

        $this->assertDatabaseHas('monox_shipments', [
            'sales_order_id' => $order->id,
            'lot_id' => $lot1->id,
            'shipment_number' => 'SH-MULTI-001',
            'quantity' => 4,
        ]);

        $this->assertDatabaseHas('monox_shipments', [
            'sales_order_id' => $order->id,
            'lot_id' => $lot2->id,
            'shipment_number' => 'SH-MULTI-001-2',
            'quantity' => 6,
        ]);
    }

    public function test_it_cannot_create_shipment_if_quantity_mismatch()
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
            'order_number' => 'SO-001',
            'order_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'quantity' => 10,
            'status' => 'pending',
        ]);

        $lot = Lot::create(['lot_number' => 'LOT-1', 'item_id' => $item->id, 'department_id' => $department->id]);

        Livewire::test('monox::orders.dashboard', ['department' => $department])
            ->call('openStatusModal', $order->id, 'order', 'pending', 10)
            ->set('editingStatus', 'shipped')
            ->set('selectedLots', [['lot_id' => $lot->id, 'quantity' => 5]]) // Total 5, expected 10
            ->set('shipmentNumber', 'SH-ERR-001')
            ->set('shippingDate', now()->toDateString())
            ->call('updateStatus')
            ->assertHasErrors(['selectedLots'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('monox_shipments', [
            'shipment_number' => 'SH-ERR-001',
        ]);

        $order->refresh();
        expect($order->status)->toBe('pending');
    }

    public function test_it_only_shows_lots_with_stock_in_available_lots()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        $lotWithStock = Lot::create(['lot_number' => 'LOT-STOCK', 'item_id' => $item->id, 'department_id' => $department->id]);
        $lotWithoutStock = Lot::create(['lot_number' => 'LOT-EMPTY', 'item_id' => $item->id, 'department_id' => $department->id]);

        // Add stock to lotWithStock
        StockMovement::create([
            'item_id' => $item->id,
            'lot_id' => $lotWithStock->id,
            'quantity' => 10,
            'type' => 'in',
            'department_id' => $department->id,
        ]);

        $order = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'order_number' => 'SO-001',
            'order_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'quantity' => 5,
            'status' => 'pending',
        ]);

        $component = Livewire::test('monox::orders.dashboard', ['department' => $department])
            ->call('openStatusModal', $order->id, 'order', 'pending', $order->quantity);

        $lots = $component->instance()->availableLots();

        $this->assertCount(1, $lots);
        $this->assertEquals('LOT-STOCK', $lots->first()->lot_number);
    }

    public function test_it_reduces_stock_when_shipment_is_created()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $item = Item::create(['name' => 'Product X', 'code' => 'PX', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);
        $partner = Partner::create(['code' => 'C001', 'name' => 'Customer A', 'type' => 'customer', 'department_id' => $department->id]);

        $lot = Lot::create(['lot_number' => 'LOT-001', 'item_id' => $item->id, 'department_id' => $department->id]);

        // 初期在庫 10
        StockMovement::create([
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'quantity' => 10,
            'department_id' => $department->id,
            'type' => 'in',
            'moved_at' => now(),
        ]);

        $order = SalesOrder::create([
            'department_id' => $department->id,
            'partner_id' => $partner->id,
            'item_id' => $item->id,
            'order_number' => 'SO-100',
            'quantity' => 5,
            'status' => 'pending',
            'order_date' => now(),
        ]);

        Livewire::test('monox::orders.dashboard', ['department' => $department])
            ->call('openStatusModal', $order->id, 'order', 'pending', 5)
            ->set('editingStatus', 'shipped')
            ->set('selectedLots', [['lot_id' => $lot->id, 'quantity' => 5]])
            ->set('shipmentNumber', 'SH-100')
            ->set('shippingDate', now()->toDateString())
            ->call('updateStatus');

        $this->assertDatabaseHas('monox_stock_movements', [
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'quantity' => -5,
            'type' => 'shipment',
        ]);

        expect($lot->refresh()->current_stock)->toBe(5.0);
    }
}
