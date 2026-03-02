<?php

namespace Lastdino\Monox\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Partner;
use Tests\TestCase;

class MonoxUniqueCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_different_departments_can_have_same_item_code()
    {
        $dept1 = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $dept2 = Department::create(['code' => 'D002', 'name' => 'Dept 2']);

        // Dept 1 に ITEM-001 を作成
        Item::create([
            'department_id' => $dept1->id,
            'code' => 'ITEM-001',
            'name' => 'Item 1 from Dept 1',
            'type' => 'part',
            'unit' => 'pcs',
        ]);

        // Dept 2 にも ITEM-001 を作成 (以前はユニーク制約でエラーになっていたはず)
        $item2 = Item::create([
            'department_id' => $dept2->id,
            'code' => 'ITEM-001',
            'name' => 'Item 1 from Dept 2',
            'type' => 'part',
            'unit' => 'pcs',
        ]);

        $this->assertDatabaseHas('monox_items', [
            'department_id' => $dept1->id,
            'code' => 'ITEM-001',
        ]);

        $this->assertDatabaseHas('monox_items', [
            'department_id' => $dept2->id,
            'code' => 'ITEM-001',
        ]);

        $this->assertEquals(2, Item::where('code', 'ITEM-001')->count());
    }

    public function test_same_department_cannot_have_same_item_code()
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $dept1 = Department::create(['code' => 'D001', 'name' => 'Dept 1']);

        Item::create([
            'department_id' => $dept1->id,
            'code' => 'ITEM-001',
            'name' => 'First Item',
            'type' => 'part',
            'unit' => 'pcs',
        ]);

        Item::create([
            'department_id' => $dept1->id,
            'code' => 'ITEM-001',
            'name' => 'Duplicate Item',
            'type' => 'part',
            'unit' => 'pcs',
        ]);
    }

    public function test_different_departments_can_have_same_partner_code()
    {
        $dept1 = Department::create(['code' => 'D001', 'name' => 'Dept 1']);
        $dept2 = Department::create(['code' => 'D002', 'name' => 'Dept 2']);

        Partner::create([
            'department_id' => $dept1->id,
            'code' => 'P-001',
            'name' => 'Partner 1 from Dept 1',
            'type' => 'supplier',
        ]);

        Partner::create([
            'department_id' => $dept2->id,
            'code' => 'P-001',
            'name' => 'Partner 1 from Dept 2',
            'type' => 'supplier',
        ]);

        $this->assertDatabaseHas('monox_partners', [
            'department_id' => $dept1->id,
            'code' => 'P-001',
        ]);

        $this->assertDatabaseHas('monox_partners', [
            'department_id' => $dept2->id,
            'code' => 'P-001',
        ]);
    }
}
