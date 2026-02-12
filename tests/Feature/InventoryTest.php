<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Item;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_track_stock_movements_and_calculate_current_stock()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_INV', 'name' => 'Dept Inv']);
        $item = Item::create(['name' => 'Test', 'code' => 'T001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);

        $item->stockMovements()->create(['quantity' => 10, 'type' => 'in', 'moved_at' => now(), 'department_id' => $dept->id]);
        $item->stockMovements()->create(['quantity' => -3, 'type' => 'out', 'moved_at' => now(), 'department_id' => $dept->id]);
        $item->stockMovements()->create(['quantity' => 0.5, 'type' => 'adjustment', 'moved_at' => now(), 'department_id' => $dept->id]);

        expect($item->fresh()->current_stock)->toBe(7.5);
    }

    public function test_it_can_track_lot_stock()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_INV2', 'name' => 'Dept Inv 2']);
        $item = Item::create(['name' => 'Lot Item', 'code' => 'L001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);
        $lotA = $item->lots()->create(['lot_number' => 'LOT-A', 'department_id' => $dept->id]);
        $lotB = $item->lots()->create(['lot_number' => 'LOT-B', 'department_id' => $dept->id]);

        $item->stockMovements()->create(['lot_id' => $lotA->id, 'quantity' => 10, 'type' => 'in', 'moved_at' => now(), 'department_id' => $dept->id]);
        $item->stockMovements()->create(['lot_id' => $lotB->id, 'quantity' => 20, 'type' => 'in', 'moved_at' => now(), 'department_id' => $dept->id]);
        $item->stockMovements()->create(['lot_id' => $lotA->id, 'quantity' => -3, 'type' => 'out', 'moved_at' => now(), 'department_id' => $dept->id]);

        expect($item->fresh()->current_stock)->toBe(27.0);
        expect($lotA->fresh()->current_stock)->toBe(7.0);
        expect($lotB->fresh()->current_stock)->toBe(20.0);
    }

    public function test_it_prevents_withdrawing_more_than_available_stock()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_INV3', 'name' => 'Dept Inv 3']);
        $item = Item::create(['name' => 'Stock Test', 'code' => 'S001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);
        $item->stockMovements()->create(['quantity' => 10, 'type' => 'in', 'moved_at' => now(), 'department_id' => $dept->id]);

        Livewire::test('monox::items.inventory-manager', ['item' => $item])
            ->set('type', 'out')
            ->set('adjustmentQuantity', 15)
            ->call('adjustStock')
            ->assertHasErrors(['adjustmentQuantity']);

        expect($item->fresh()->current_stock)->toBe(10.0);
    }

    public function test_it_prevents_withdrawing_more_than_lot_stock()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_INV4', 'name' => 'Dept Inv 4']);
        $item = Item::create(['name' => 'Lot Stock Test', 'code' => 'L002', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);
        $lotA = $item->lots()->create(['lot_number' => 'LOT-A', 'department_id' => $dept->id]);
        $item->stockMovements()->create(['lot_id' => $lotA->id, 'quantity' => 10, 'type' => 'in', 'moved_at' => now(), 'department_id' => $dept->id]);

        Livewire::test('monox::items.inventory-manager', ['item' => $item])
            ->set('type', 'out')
            ->set('selectedLotId', $lotA->id)
            ->set('adjustmentQuantity', 15)
            ->call('adjustStock')
            ->assertHasErrors(['adjustmentQuantity']);

        expect($lotA->fresh()->current_stock)->toBe(10.0);
    }

    public function test_inventory_manager_dispatches_event()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_INV5', 'name' => 'Dept Inv 5']);
        $item = Item::create(['name' => 'Event Test', 'code' => 'E001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);

        Livewire::test('monox::items.inventory-manager', ['item' => $item])
            ->set('type', 'in')
            ->set('adjustmentQuantity', 10)
            ->call('adjustStock')
            ->assertDispatched('stock-updated');
    }
}
