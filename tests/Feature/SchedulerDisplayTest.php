<?php

namespace Lastdino\Monox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lastdino\Monox\Models\Department;
use Livewire\Livewire;
use Tests\TestCase;

class SchedulerDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_one_month_from_start_of_month()
    {
        $department = Department::query()->create(['code' => 'D1', 'name' => 'Dept 1']);

        $expectedStartDate = now()->startOfMonth()->toDateString();
        $expectedDays = now()->daysInMonth;

        Livewire::test('monox::production.⚡scheduler', ['department' => $department])
            ->assertSet('startDate', $expectedStartDate)
            ->assertSet('daysToShow', $expectedDays);
    }
    public function test_it_moves_by_month_in_day_view()
    {
        $department = Department::query()->create(['code' => 'D1', 'name' => 'Dept 1']);

        $startOfMonth = now()->startOfMonth();
        $nextMonth = $startOfMonth->copy()->addMonth()->startOfMonth();
        $prevMonth = $startOfMonth->copy()->subMonth()->startOfMonth();

        $livewire = Livewire::test('monox::production.⚡scheduler', ['department' => $department])
            ->set('viewMode', 'day');

        // 次月へ
        $livewire->call('moveNext')
            ->assertSet('startDate', $nextMonth->toDateString())
            ->assertSet('daysToShow', $nextMonth->daysInMonth);

        // 前月へ（元に戻る）
        $livewire->call('movePrev')
            ->assertSet('startDate', $startOfMonth->toDateString())
            ->assertSet('daysToShow', $startOfMonth->daysInMonth);

        // さらに前月へ
        $livewire->call('movePrev')
            ->assertSet('startDate', $prevMonth->toDateString())
            ->assertSet('daysToShow', $prevMonth->daysInMonth);
    }
}
