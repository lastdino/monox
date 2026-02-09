<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Item;
use Livewire\Livewire;
use Tests\TestCase;

class BomTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_define_a_bom_relationship()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_BOM', 'name' => 'Dept BOM']);
        $parent = Item::create(['name' => 'Assembly', 'code' => 'ASM-001', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $dept->id]);
        $child = Item::create(['name' => 'Part', 'code' => 'PRT-001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);

        $parent->components()->attach($child->id, ['quantity' => 2]);

        expect($parent->fresh()->components)->toHaveCount(1);
        expect((float) $parent->fresh()->components->first()->pivot->quantity)->toBe(2.0);
    }

    public function test_it_can_access_parent_items_from_child()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_BOM2', 'name' => 'Dept BOM 2']);
        $parent = Item::create(['name' => 'Assembly', 'code' => 'ASM-001', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $dept->id]);
        $child = Item::create(['name' => 'Part', 'code' => 'PRT-001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);

        $parent->components()->attach($child->id, ['quantity' => 5]);

        expect($child->fresh()->parentItems)->toHaveCount(1)
            ->and($child->fresh()->parentItems->first()->id)->toBe($parent->id);
    }

    public function test_it_can_manage_bom_via_component()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_BOM3', 'name' => 'Dept BOM 3']);
        $parent = Item::create(['name' => 'Parent', 'code' => 'P001', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $dept->id]);
        $child = Item::create(['name' => 'Child', 'code' => 'C001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $dept->id]);

        Livewire::test('monox::items.bom-manager', ['item' => $parent])
            ->set('selectedChildId', $child->id)
            ->set('quantity', 3)
            ->call('addComponent')
            ->assertHasNoErrors();

        expect($parent->fresh()->components)->toHaveCount(1);
        expect((float) $parent->fresh()->components->first()->pivot->quantity)->toBe(3.0);

        Livewire::test('monox::items.bom-manager', ['item' => $parent])
            ->call('removeComponent', $child->id);

        expect($parent->fresh()->components)->toHaveCount(0);
    }
}
