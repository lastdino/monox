<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;

uses(RefreshDatabase::class);

it('filters items by department_id', function () {
    $sales = Department::query()->create(['code' => 'SALES', 'name' => '営業']);
    $mfg = Department::query()->create(['code' => 'MFG', 'name' => '製造']);

    Item::query()->create([
        'code' => 'I-001',
        'name' => 'A品',
        'type' => 'raw',
        'unit' => 'pc',
        'department_id' => $sales->id,
    ]);

    Item::query()->create([
        'code' => 'I-002',
        'name' => 'B品',
        'type' => 'raw',
        'unit' => 'pc',
        'department_id' => $mfg->id,
    ]);

    expect(Item::where('department_id', $sales->id)->pluck('code'))->toContain('I-001')->not->toContain('I-002');
    expect(Item::where('department_id', $mfg->id)->pluck('code'))->toContain('I-002')->not->toContain('I-001');
});
