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

class ProcessTemplateSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_set_share_template_flag_in_process_manager()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $item = Item::create([
            'code' => 'TEST-001',
            'name' => 'Test Item',
            'type' => 'part',
            'unit' => 'pcs',
        ]);

        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->set('name', 'Process A')
            ->set('share_template_with_previous', true)
            ->call('addProcess')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monox_processes', [
            'item_id' => $item->id,
            'name' => 'Process A',
            'share_template_with_previous' => true,
        ]);
    }

    public function test_worksheet_shows_previous_process_annotations_when_sharing_is_enabled()
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

        $proc2 = Process::create([
            'item_id' => $item->id,
            'name' => 'Process 2',
            'sort_order' => 20,
            'share_template_with_previous' => true,
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
        ]);

        $order = ProductionOrder::create([
            'department_id' => $department->id,
            'item_id' => $item->id,
            'lot_id' => $lot->id,
            'target_quantity' => 100,
            'status' => 'pending',
        ]);

        $record1 = ProductionRecord::create([
            'production_order_id' => $order->id,
            'process_id' => $proc1->id,
            'worker_id' => $user->id,
            'status' => 'completed',
        ]);

        $record1->annotationValues()->create([
            'field_id' => $field1->id,
            'value' => 'Result 1',
        ]);

        $proc1->addMediaFromString('dummy image content')
            ->toMediaCollection('template', 'local');

        $test = Livewire::test('monox::production.worksheet', [
            'order' => $order,
            'process' => $proc2->id,
        ]);

        $test->assertSee('Result 1'); // Should see the result from Process 1
    }

    public function test_worksheet_can_share_template_across_three_processes()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'DEP2', 'name' => 'Dep2']);
        $item = Item::create([
            'code' => 'ITEM-002',
            'name' => 'Item 002',
            'type' => 'part',
            'unit' => 'pcs',
            'department_id' => $department->id,
        ]);

        // 3 processes sharing the same template from Process 1
        $proc1 = Process::create(['item_id' => $item->id, 'name' => 'Process 1', 'sort_order' => 10]);
        $proc2 = Process::create(['item_id' => $item->id, 'name' => 'Process 2', 'sort_order' => 20, 'share_template_with_previous' => true]);
        $proc3 = Process::create(['item_id' => $item->id, 'name' => 'Process 3', 'sort_order' => 30, 'share_template_with_previous' => true]);

        $field1 = ProductionAnnotationField::create(['process_id' => $proc1->id, 'field_key' => 'f1', 'label' => 'L1', 'type' => 'text', 'x_percent' => 10, 'y_percent' => 10, 'width_percent' => 5, 'height_percent' => 5]);
        $field2 = ProductionAnnotationField::create(['process_id' => $proc2->id, 'field_key' => 'f2', 'label' => 'L2', 'type' => 'text', 'x_percent' => 20, 'y_percent' => 20, 'width_percent' => 5, 'height_percent' => 5]);

        $lot = Lot::create(['item_id' => $item->id, 'lot_number' => 'LOT-002']);
        $order = ProductionOrder::create(['department_id' => $department->id, 'item_id' => $item->id, 'lot_id' => $lot->id, 'target_quantity' => 100, 'status' => 'pending']);

        // Results in P1 and P2
        $record1 = ProductionRecord::create(['production_order_id' => $order->id, 'process_id' => $proc1->id, 'worker_id' => $user->id, 'status' => 'completed']);
        $record1->annotationValues()->create(['field_id' => $field1->id, 'value' => 'Val 1']);

        $record2 = ProductionRecord::create(['production_order_id' => $order->id, 'process_id' => $proc2->id, 'worker_id' => $user->id, 'status' => 'completed']);
        $record2->annotationValues()->create(['field_id' => $field2->id, 'value' => 'Val 2']);

        $proc1->addMediaFromString('dummy image content')->toMediaCollection('template', 'local');

        // Test Process 3 worksheet
        Livewire::test('monox::production.worksheet', ['order' => $order, 'process' => $proc3->id])
            ->assertSee('Val 1')
            ->assertSee('Val 2');
    }

    public function test_annotations_page_works_for_shared_process_without_own_image()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = Department::create(['code' => 'DEP3', 'name' => 'Dep3']);
        $item = Item::create([
            'code' => 'ITEM-003',
            'name' => 'Item 003',
            'type' => 'part',
            'unit' => 'pcs',
            'department_id' => $department->id,
        ]);

        $proc1 = Process::create(['item_id' => $item->id, 'name' => 'P1', 'sort_order' => 10]);
        $proc2 = Process::create(['item_id' => $item->id, 'name' => 'P2', 'sort_order' => 20, 'share_template_with_previous' => true]);

        // Only P1 has a template image
        $proc1->addMediaFromString('dummy image content')->toMediaCollection('template', 'local');

        // Annotations page should render for P2 and allow saving a field
        $component = Livewire::test('monox::processes.annotations', ['process' => $proc2]);
        $component
            ->set('label', 'Check A')
            ->set('field_key', 'check_a')
            ->set('type', 'number')
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monox_production_annotation_fields', [
            'process_id' => $proc2->id,
            'label' => 'Check A',
            'field_key' => 'check_a',
        ]);
    }
}
