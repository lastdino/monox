<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\ProductionAnnotationField;
use Lastdino\Monox\Models\ProductionOrder;
use Lastdino\Monox\Models\ProductionRecord;
use Livewire\Livewire;
use Tests\TestCase;

class WorksheetExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_worksheet_excel_export()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'DEP', 'name' => 'Dep']);
        $item = Item::create([
            'code' => 'ITEM-001',
            'name' => 'Item 001',
            'type' => 'part',
            'unit' => 'pcs',
            'department_id' => $department->id,
        ]);

        $proc1 = Process::create([
            'item_id' => $item->id,
            'name' => 'Process 1',
            'sort_order' => 10,
        ]);

        $field1 = ProductionAnnotationField::create([
            'process_id' => $proc1->id,
            'field_key' => 'field_1',
            'label' => 'Label 1',
            'type' => 'text',
            'x_percent' => 10,
            'y_percent' => 10,
            'width_percent' => 5,
            'height_percent' => 5,
        ]);

        $lot = Lot::create([
            'item_id' => $item->id,
            'lot_number' => 'LOT-001',
            'department_id' => $department->id,
        ]);

        $order = ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 100,
            'status' => 'in_progress',
        ]);

        $record1 = ProductionRecord::create([
            'production_order_id' => $order->id,
            'process_id' => $proc1->id,
            'worker_id' => $user->id,
            'status' => 'completed',
            'input_quantity' => 100,
            'good_quantity' => 98,
            'defective_quantity' => 2,
        ]);

        $record1->annotationValues()->create([
            'field_id' => $field1->id,
            'value' => 'Test Result',
        ]);

        Livewire::test('monox::production.worksheet', ['order' => $order])
            ->call('exportExcel')
            ->assertStatus(200);
    }
}
