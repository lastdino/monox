<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\Equipment;
use Livewire\Livewire;
use Tests\TestCase;

class ProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_manage_processes_for_an_item()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_PROC', 'name' => 'Dept Proc']);
        $item = Item::create([
            'code' => 'TEST-001',
            'name' => 'Test Item',
            'type' => 'part',
            'unit' => 'pcs',
            'department_id' => $dept->id,
        ]);

        // Add a process
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->set('name', 'Cutting')
            ->set('standard_setup_time_minutes', 10.0)
            ->set('standard_time_minutes', 15.5)
            ->set('description', 'Cut the material')
            ->call('addProcess')
            ->assertHasNoErrors()
            ->assertSee('Cutting');

        $this->assertDatabaseHas('monox_processes', [
            'item_id' => $item->id,
            'name' => 'Cutting',
            'standard_setup_time_minutes' => 10.0,
            'standard_time_minutes' => 15.5,
        ]);

        $process = Process::first();

        // Edit the process
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->call('editProcess', $process->id)
            ->assertSet('name', 'Cutting')
            ->assertSet('standard_setup_time_minutes', 10.0)
            ->set('name', 'Final Cutting')
            ->set('standard_setup_time_minutes', 5.0)
            ->call('updateProcess')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monox_processes', [
            'id' => $process->id,
            'name' => 'Final Cutting',
            'standard_setup_time_minutes' => 5.0,
        ]);

        // Add another process to test sorting
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->set('name', 'Welding')
            ->call('addProcess');

        $welding = Process::where('name', 'Welding')->first();

        // Final Cutting (order 10), Welding (order 20)
        // Move Welding up
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->call('moveUp', $welding->id);

        expect($welding->fresh()->sort_order)->toBeLessThan($process->fresh()->sort_order);

        // Remove a process
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->call('removeProcess', $process->id);

        $this->assertDatabaseMissing('monox_processes', ['id' => $process->id]);
    }

    public function test_it_can_associate_equipment_with_processes()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_PROC', 'name' => 'Dept Proc']);
        $item = Item::create([
            'code' => 'TEST-EQ-001',
            'name' => 'Test Item',
            'type' => 'part',
            'unit' => 'pcs',
            'department_id' => $dept->id,
        ]);

        $equipment1 = Equipment::create(['code' => 'EQ1', 'name' => 'Equipment 1']);
        $equipment2 = Equipment::create(['code' => 'EQ2', 'name' => 'Equipment 2']);
        $equipmentOther = Equipment::create(['code' => 'EQ-OTHER', 'name' => 'Other Equipment']);

        // 部門に設備を紐付け（monox_department_equipment）
        $dept->equipments()->attach([$equipment1->id, $equipment2->id]);

        // 1. 設備を紐付けて工程を追加
        config(['monox.models.equipment' => Equipment::class]);
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->set('name', 'Pressing')
            ->set('selectedEquipments', [(string)$equipment1->id, (string)$equipment2->id])
            ->call('addProcess')
            ->assertHasNoErrors();

        $process = Process::where('name', 'Pressing')->first();
        $this->assertCount(2, $process->equipments);
        $this->assertTrue($process->equipments->contains($equipment1));

        // 3. 編集時に紐付けが読み込まれることを確認
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->call('editProcess', $process->id)
            ->assertSet('selectedEquipments', [(string)$equipment1->id, (string)$equipment2->id]);

        // 4. 紐付けを更新
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->call('editProcess', $process->id)
            ->set('selectedEquipments', [(string)$equipment1->id])
            ->call('updateProcess')
            ->assertHasNoErrors();

        $process = $process->fresh();
        $this->assertCount(1, $process->equipments);
        $this->assertTrue($process->equipments->contains($equipment1));
        $this->assertFalse($process->equipments->contains($equipment2));
    }
}
