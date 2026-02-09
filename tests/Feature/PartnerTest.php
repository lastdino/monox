<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Partner;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_create_a_partner()
    {
        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_PARTNER', 'name' => 'Dept Partner']);
        $partner = Partner::create([
            'code' => 'P-001',
            'name' => 'Supplier A',
            'type' => 'supplier',
            'department_id' => $dept->id,
        ]);

        expect($partner->code)->toBe('P-001')
            ->and($partner->name)->toBe('Supplier A')
            ->and($partner->type)->toBe('supplier');
    }

    public function test_it_can_list_partners_and_search()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_PARTNER2', 'name' => 'Dept Partner 2']);

        Partner::create(['name' => 'Alpha Corp', 'code' => 'A001', 'type' => 'supplier', 'department_id' => $dept->id]);
        Partner::create(['name' => 'Beta Inc', 'code' => 'B001', 'type' => 'customer', 'department_id' => $dept->id]);

        Livewire::test('monox::partners.index', ['department' => $dept])
            ->assertSee('Alpha Corp')
            ->assertSee('Beta Inc')
            ->set('search', 'Alpha')
            ->assertSee('Alpha Corp')
            ->assertDontSee('Beta Inc');
    }

    public function test_it_can_create_a_partner_via_the_create_component()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_PARTNER3', 'name' => 'Dept Partner 3']);

        Livewire::test('monox::partners.create', ['department' => $dept])
            ->set('code', 'NEW-P')
            ->set('name', 'New Partner')
            ->set('type', 'supplier')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('partner-created');

        $this->assertDatabaseHas('monox_partners', [
            'code' => 'NEW-P',
            'name' => 'New Partner',
        ]);
    }

    public function test_it_validates_partner_creation()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $dept = \Lastdino\Monox\Models\Department::create(['code' => 'D_PARTNER4', 'name' => 'Dept Partner 4']);

        Livewire::test('monox::partners.create', ['department' => $dept])
            ->set('code', '')
            ->call('save')
            ->assertHasErrors(['code' => 'required']);
    }
}
