<?php

namespace Lastdino\Monox\Tests\Feature;

use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\ProductionOrder;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Exports\WorksheetExport;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Livewire\Livewire;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductionOrderNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::create(['name' => 'Test Dept', 'code' => 'TEST']);
        $this->user = User::factory()->create();
        Permission::create(['name' => 'production.manage.'.$this->department->id, 'guard_name' => 'web']);
        $this->user->givePermissionTo('production.manage.'.$this->department->id);

        $this->item = Item::create([
            'name' => 'Test Item',
            'code' => 'TEST-ITEM',
            'unit' => 'pcs',
            'type' => 'product',
            'department_id' => $this->department->id,
        ]);
    }

    public function test_it_displays_note_in_production_orders_list()
    {
        $order = ProductionOrder::create([
            'department_id' => $this->department->id,
            'item_id' => $this->item->id,
            'lot_id' => Lot::create(['lot_number' => 'LOT-1', 'item_id' => $this->item->id, 'department_id' => $this->department->id])->id,
            'target_quantity' => 10,
            'status' => 'pending',
            'note' => 'Original Note',
        ]);

        Livewire::actingAs($this->user)
            ->test('monox::production.index', ['department' => $this->department])
            ->assertSee('Original Note');
    }

    public function test_it_can_edit_production_order_note()
    {
        $order = ProductionOrder::create([
            'department_id' => $this->department->id,
            'item_id' => $this->item->id,
            'lot_id' => Lot::create(['lot_number' => 'LOT-1', 'item_id' => $this->item->id, 'department_id' => $this->department->id])->id,
            'target_quantity' => 10,
            'status' => 'pending',
            'note' => 'Original Note',
        ]);

        Livewire::actingAs($this->user)
            ->test('monox::production.index', ['department' => $this->department])
            ->call('editOrder', $order->id)
            ->set('editingNote', 'Updated Note')
            ->call('updateOrder')
            ->assertHasNoErrors();

        $this->assertEquals('Updated Note', $order->fresh()->note);
    }

    public function test_it_includes_note_in_excel_export()
    {
        Process::create([
            'item_id' => $this->item->id,
            'name' => 'Process 1',
            'sort_order' => 10,
        ]);

        $order = ProductionOrder::create([
            'department_id' => $this->department->id,
            'item_id' => $this->item->id,
            'lot_id' => Lot::create(['lot_number' => 'LOT-1', 'item_id' => $this->item->id, 'department_id' => $this->department->id])->id,
            'target_quantity' => 10,
            'status' => 'pending',
            'note' => 'Excel Note',
        ]);

        $export = new WorksheetExport();
        $result = $export->export($order);

        $writer = $result['writer'];
        $spreadsheet = $writer->getSpreadsheet();
        $sheet = $spreadsheet->getSheet(0);

        $this->assertEquals('指図備考', $sheet->getCell('A4')->getValue());
        $this->assertEquals('Excel Note', $sheet->getCell('B4')->getValue());
    }
}
