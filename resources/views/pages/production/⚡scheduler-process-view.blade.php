<?php

use Illuminate\Support\Collection;
use Livewire\Component;
use Carbon\Carbon;

new class extends Component
{
    public int $departmentId;

    public Collection $dates;

    public Collection $schedules;

    public string $viewMode;

    public int $intervalMinutes;

    public string $startDate;

    public int $daysToShow;

    public function mount($departmentId, $dates, $schedules, $viewMode, $intervalMinutes, $startDate, $daysToShow): void
    {
        $this->departmentId = $departmentId;
        $this->dates = $dates;
        $this->schedules = $schedules;
        $this->viewMode = $viewMode;
        $this->intervalMinutes = $intervalMinutes;
        $this->startDate = $startDate;
        $this->daysToShow = $daysToShow;
    }

    public function processes(): Collection
    {
        // スケジュールに含まれる工程名でグループ化
        return $this->schedules
            ->pluck('process.name')
            ->unique()
            ->filter()
            ->values();
    }
}; ?>

<div class="bg-zinc-50 dark:bg-zinc-900">
    @php
        $startRange = Carbon::parse($startDate);
        $endRange = $startRange->copy()->addDays($daysToShow);
        $processes = $this->processes();
    @endphp

    @forelse($processes as $processName)
        @if($viewMode === 'day')
            <div class="grid border-b border-zinc-200 dark:border-zinc-700 min-h-[80px]"
                 style="grid-template-columns: 250px repeat({{ $daysToShow }}, 40px);">

                {{-- 左側：工程ラベル --}}
                <div class="p-3 border-r border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex flex-col justify-center sticky left-0 z-30 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                    <div class="text-sm font-bold truncate">{{ $processName }}</div>
                </div>

                {{-- 右側：日付セル --}}
                <div class="contents">
                    @foreach($dates as $date)
                        <div class="p-1 border-r border-zinc-200/50 dark:border-zinc-700/50 min-h-[80px] transition-colors hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50 shrink-0">
                            @foreach($schedules->filter(function($s) use ($processName, $date, $startRange) {
                                return $s->process->name === $processName && $s->scheduled_start_at->max($startRange)->isSameDay($date);
                            }) as $schedule)
                                @php
                                    $isOtherDepartment = $schedule->productionOrder->department_id !== $this->departmentId;
                                    $actualStart = $schedule->scheduled_start_at->max($startRange);
                                    $actualEnd = $schedule->scheduled_end_at->min($endRange);
                                    $totalDays = $date->diffInDays($actualEnd->startOfDay()) + 1;
                                    $colSpan = max(1, $totalDays);
                                    $offsetMinutes = $date->copy()->startOfDay()->diffInMinutes($actualStart);
                                    $marginLeftPx = $offsetMinutes * (40 / 1440);
                                    $durationMinutes = $actualStart->diffInMinutes($actualEnd);
                                    $widthPx = $durationMinutes * (40 / 1440);
                                    $isRealStart = $schedule->scheduled_start_at >= $startRange;
                                    $isRealEnd = $schedule->scheduled_end_at <= $endRange;
                                @endphp
                                <flux:tooltip>
                                    <div wire:key="schedule-process-day-{{ $schedule->id }}-{{ $date->toDateString() }}"
                                         @class([
                                              'shadow-sm border p-2 text-xs flex flex-col justify-between group transition-all hover:brightness-95 mb-1 h-20 relative z-20',
                                              'cursor-default',
                                              'rounded-l-md' => $isRealStart,
                                              'rounded-r-md' => $isRealEnd,
                                              'bg-blue-100 border-blue-200 text-blue-800 dark:bg-blue-900/40 dark:border-blue-800 dark:text-blue-300' => !$isOtherDepartment && $schedule->status === 'confirmed',
                                              'bg-emerald-100 border-emerald-200 text-emerald-800 dark:bg-emerald-900/40 dark:border-emerald-800 dark:text-emerald-300' => !$isOtherDepartment && $schedule->status === 'completed',
                                              'bg-zinc-200 border-zinc-300 text-zinc-600 dark:bg-zinc-800 dark:border-zinc-700 dark:text-zinc-400' => $isOtherDepartment,
                                         ])
                                         style="grid-column: span {{ $colSpan }}; margin-left: {{ $marginLeftPx }}px; width: {{ $widthPx }}px; min-width: {{ $widthPx }}px;">
                                        <div class="flex flex-col h-full justify-between">
                                            <div>
                                                <div class="font-bold truncate">{{ $schedule->productionOrder->item->name }}</div>
                                                <div class="text-[9px] opacity-70 truncate">{{ $schedule->equipment->name ?? '設備未割当' }}</div>
                                            </div>
                                            <div class="text-[9px] opacity-70">{{ $schedule->scheduled_start_at->format('H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                        </div>
                                    </div>
                                    <flux:tooltip.content>
                                        <div class="font-bold text-sm">{{ $schedule->productionOrder->item->name }}</div>
                                        <div class="text-xs text-zinc-500">
                                            <div>設備: {{ $schedule->equipment->name ?? '未割当' }}</div>
                                            <div>ロット: #{{ $schedule->productionOrder->lot->lot_number ?? 'No Lot' }}</div>
                                        </div>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            {{-- 時間表示モード --}}
            @php
                $targetDay = Carbon::parse($startDate)->startOfDay();
                $timeSlots = [];
                for ($h = 0; $h < 24; $h++) {
                    for ($m = 0; $m < 60; $m += $intervalMinutes) {
                        $timeSlots[] = $targetDay->copy()->setHour($h)->setMinute($m);
                    }
                }
            @endphp
            <div class="grid border-b border-zinc-200 dark:border-zinc-700 min-h-[80px]"
                 style="grid-template-columns: 250px repeat({{ count($timeSlots) }}, 40px);">

                <div class="p-3 border-r border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex flex-col justify-center sticky left-0 z-30 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                    <div class="text-sm font-bold truncate">{{ $processName }}</div>
                </div>

                <div class="contents">
                    @foreach($timeSlots as $slot)
                        <div class="border-r border-zinc-200/50 dark:border-zinc-700/50 min-h-[80px] transition-colors hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50 shrink-0">
                            @foreach($schedules->filter(function($s) use ($processName, $targetDay, $slot) {
                                return $s->process->name === $processName &&
                                       $s->scheduled_start_at->max($targetDay->copy()->startOfDay())->betweenIncluded($slot, $slot->copy()->addMinutes($this->intervalMinutes)->subSecond());
                            }) as $schedule)
                                @php
                                    $actualStart = $schedule->scheduled_start_at->max($targetDay->copy()->startOfDay());
                                    $actualEnd = $schedule->scheduled_end_at->min($targetDay->copy()->endOfDay());
                                    $totalMinutes = $slot->diffInMinutes($actualEnd);
                                    $colSpan = max(1, (int) ceil($totalMinutes / $intervalMinutes));
                                    $offsetMinutes = $slot->diffInMinutes($actualStart);
                                    $marginLeftPx = ($offsetMinutes > 0) ? $offsetMinutes * (40 / $intervalMinutes) : 0;
                                    $widthPx = $actualStart->diffInMinutes($actualEnd) * (40 / $intervalMinutes);
                                @endphp
                                <div wire:key="schedule-process-hour-{{ $schedule->id }}-{{ $slot->format('Hi') }}"
                                     class="shadow-sm border p-2 text-[10px] flex flex-col justify-center bg-blue-100 border-blue-200 text-blue-800 rounded-md m-1 h-[60px]"
                                     style="grid-column: span {{ $colSpan }}; margin-left: {{ $marginLeftPx }}px; width: {{ $widthPx }}px">
                                    <div class="font-bold truncate">{{ $schedule->productionOrder->item->name }}</div>
                                    <div class="text-[9px] opacity-70 truncate">{{ $schedule->equipment->name ?? '未割当' }}</div>
                                    <div class="text-[9px] opacity-70">{{ $schedule->scheduled_start_at->format('H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @empty
        <div class="py-20 text-center text-zinc-400">
            スケジュールがありません
        </div>
    @endforelse
</div>
