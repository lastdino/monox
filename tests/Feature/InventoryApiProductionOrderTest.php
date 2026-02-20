<?php

namespace Lastdino\Monox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\ProductionOrder;
use Tests\TestCase;

class InventoryApiProductionOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // APIキー認証を無効化
        config(['monox.api_key' => null]);
    }

    public function test_it_does_not_create_production_order_when_not_in_type()
    {
        $dept = Department::create(['code' => 'D1', 'name' => 'Dept 1']);
        $item = Item::create(['code' => 'ITEM1', 'name' => 'Item 1', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $dept->id]);
        Process::create(['item_id' => $item->id, 'name' => 'Process 1', 'sort_order' => 1]);

        $response = $this->postJson('/api/monox/v1/inventory/sync', [
            'sku' => 'ITEM1',
            'lot_no' => 'LOT1',
            'qty' => 10,
            'type' => 'out',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, ProductionOrder::count());
    }

    public function test_it_does_not_create_production_order_when_lot_is_not_new()
    {
        $dept = Department::create(['code' => 'D1', 'name' => 'Dept 1']);
        $item = Item::create(['code' => 'ITEM1', 'name' => 'Item 1', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $dept->id]);
        Lot::create(['item_id' => $item->id, 'lot_number' => 'LOT1', 'department_id' => $dept->id]);
        Process::create(['item_id' => $item->id, 'name' => 'Process 1', 'sort_order' => 1]);

        $response = $this->postJson('/api/monox/v1/inventory/sync', [
            'sku' => 'ITEM1',
            'lot_no' => 'LOT1',
            'qty' => 10,
            'type' => 'in',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, ProductionOrder::count());
    }

    public function test_it_does_not_create_production_order_when_no_processes()
    {
        $dept = Department::create(['code' => 'D1', 'name' => 'Dept 1']);
        $item = Item::create(['code' => 'ITEM1', 'name' => 'Item 1', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $dept->id]);
        // No processes created

        $response = $this->postJson('/api/monox/v1/inventory/sync', [
            'sku' => 'ITEM1',
            'lot_no' => 'LOT1',
            'qty' => 10,
            'type' => 'in',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, ProductionOrder::count());
    }

    public function test_it_creates_production_order_when_conditions_met()
    {
        $dept = Department::create(['code' => 'D1', 'name' => 'Dept 1']);
        $item = Item::create(['code' => 'ITEM1', 'name' => 'Item 1', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $dept->id]);
        Process::create(['item_id' => $item->id, 'name' => 'Process 1', 'sort_order' => 1]);

        $response = $this->postJson('/api/monox/v1/inventory/sync', [
            'sku' => 'ITEM1',
            'lot_no' => 'NEWLOT1',
            'qty' => 10,
            'type' => 'in',
        ]);

        $response->assertStatus(200);

        // This assertion is expected to FAIL before implementation
        $this->assertEquals(1, ProductionOrder::count(), 'Production order should be created');

        $order = ProductionOrder::first();
        $this->assertEquals($item->id, $order->item_id);
        $this->assertEquals(10, $order->target_quantity);
        $this->assertEquals('pending', $order->status);
        $this->assertNotNull($order->lot_id);
    }
}
