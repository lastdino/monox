<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Process;
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
            ->set('standard_time_minutes', 15.5)
            ->set('description', 'Cut the material')
            ->call('addProcess')
            ->assertHasNoErrors()
            ->assertSee('Cutting');

        $this->assertDatabaseHas('monox_processes', [
            'item_id' => $item->id,
            'name' => 'Cutting',
            'standard_time_minutes' => 15.5,
        ]);

        $process = Process::first();

        // Edit the process
        Livewire::test('monox::items.process-manager', ['item' => $item])
            ->call('editProcess', $process->id)
            ->assertSet('name', 'Cutting')
            ->set('name', 'Final Cutting')
            ->call('updateProcess')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('monox_processes', [
            'id' => $process->id,
            'name' => 'Final Cutting',
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
}
