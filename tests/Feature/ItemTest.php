<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Item;
use Livewire\Livewire;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_an_item()
    {
        $item = Item::create([
            'code' => 'TEST-001',
            'name' => 'Test Item',
            'type' => 'part',
            'unit' => 'pcs',
        ]);

        expect($item->code)->toBe('TEST-001')
            ->and($item->name)->toBe('Test Item')
            ->and($item->type)->toBe('part');
    }

    public function test_it_can_list_items_and_search()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = \Lastdino\Monox\Models\Department::create(['code' => 'D001', 'name' => 'Dept 1']);

        Item::create(['name' => 'Apple', 'code' => 'A001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $department->id]);
        Item::create(['name' => 'Banana', 'code' => 'B001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $department->id]);

        Livewire::test('monox::items.index', ['department' => $department])
            ->assertSee('Apple')
            ->assertSee('Banana')
            ->set('search', 'Apple')
            ->assertSee('Apple')
            ->assertDontSee('Banana');
    }

    public function test_it_can_filter_items_by_type()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = \Lastdino\Monox\Models\Department::create(['code' => 'D001', 'name' => 'Dept 1']);

        Item::create(['name' => 'Part Item', 'code' => 'P001', 'type' => 'part', 'unit' => 'pcs', 'department_id' => $department->id]);
        Item::create(['name' => 'Product Item', 'code' => 'PR001', 'type' => 'product', 'unit' => 'pcs', 'department_id' => $department->id]);

        Livewire::test('monox::items.index', ['department' => $department])
            ->assertSee('Part Item')
            ->assertSee('Product Item')
            ->set('typeFilter', 'part')
            ->assertSee('Part Item')
            ->assertDontSee('Product Item')
            ->set('typeFilter', 'product')
            ->assertSee('Product Item')
            ->assertDontSee('Part Item')
            ->set('typeFilter', '')
            ->assertSee('Part Item')
            ->assertSee('Product Item');
    }

    public function test_it_can_configure_department_item_types()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = \Lastdino\Monox\Models\Department::create(['code' => 'D001', 'name' => 'Dept 1']);

        Livewire::test('monox::departments.type-manager', ['department' => $department])
            ->set('types', [
                ['value' => 'raw', 'label' => '原材料'],
                ['value' => 'finished', 'label' => '完成品'],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('item-types-updated');

        $department->refresh();
        expect($department->itemTypes)->toHaveCount(2)
            ->and($department->itemTypes[0]->value)->toBe('raw')
            ->and($department->itemTypes[1]->value)->toBe('finished');
    }

    public function test_item_type_label_accessor_returns_department_label()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = \Lastdino\Monox\Models\Department::create(['code' => 'D002', 'name' => 'Dept 2']);
        // 部門の種類をカスタム登録
        $department->itemTypes()->createMany([
            ['value' => 'raw', 'label' => '原材料', 'sort_order' => 0],
            ['value' => 'finished', 'label' => '完成品', 'sort_order' => 1],
        ]);

        $item = Item::create([
            'code' => 'R-001',
            'name' => 'Raw A',
            'type' => 'raw',
            'unit' => 'kg',
            'department_id' => $department->id,
        ]);

        expect($item->type_label)->toBe('原材料');
    }

    public function test_it_can_create_an_item_via_the_create_component()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = \Lastdino\Monox\Models\Department::create(['code' => 'D001', 'name' => 'Dept 1']);

        Livewire::test('monox::items.create', ['department' => $department])
            ->set('code', 'NEW-001')
            ->set('name', 'New Item')
            ->set('type', 'part')
            ->set('unit', 'pcs')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('item-created');

        $this->assertDatabaseHas('monox_items', [
            'code' => 'NEW-001',
            'name' => 'New Item',
        ]);
    }

    public function test_it_validates_item_creation()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $department = \Lastdino\Monox\Models\Department::create(['code' => 'D001', 'name' => 'Dept 1']);

        Livewire::test('monox::items.create', ['department' => $department])
            ->set('code', '')
            ->call('save')
            ->assertHasErrors(['code' => 'required']);
    }
}
