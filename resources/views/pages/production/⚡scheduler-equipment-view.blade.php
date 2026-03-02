<?php

use Illuminate\Support\Collection;
use Livewire\Component;
use Carbon\Carbon;

new class extends Component
{
    public int $departmentId;

    public Collection $dates;

    public Collection $equipments;

    public Collection $schedules;

    public string $viewMode;

    public int $intervalMinutes;

    public string $startDate;

    public int $daysToShow;

    public function mount($departmentId, $dates, $equipments, $schedules, $viewMode, $intervalMinutes, $startDate, $daysToShow): void
    {
        $this->departmentId = $departmentId;
        $this->dates = $dates;
        $this->equipments = $equipments;
        $this->schedules = $schedules;
        $this->viewMode = $viewMode;
        $this->intervalMinutes = $intervalMinutes;
        $this->startDate = $startDate;
        $this->daysToShow = $daysToShow;
    }
}; ?>

<div class="bg-zinc-50 dark:bg-zinc-900">
    @php
        $startRange = Carbon::parse($startDate);
        $endRange = $startRange->copy()->addDays($daysToShow);
    @endphp

    @forelse($equipments as $equipment)
        @if($viewMode === 'day')
            <div class="grid border-b border-zinc-200 dark:border-zinc-700 min-h-[80px]"
                 style="grid-template-columns: 250px repeat({{ $daysToShow }}, 40px);">

                {{-- 左側：設備ラベル --}}
                <div class="p-3 border-r border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex flex-col justify-center sticky left-0 z-30 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                    <div class="text-sm font-bold truncate">{{ $equipment->name }}</div>
                    <div class="text-[10px] text-zinc-400 truncate">{{ $equipment->code }}</div>
                </div>

                {{-- 右側：日付セル（ドロップ先） --}}
                <div class="contents">
                    @foreach($dates as $date)
                        <div wire:sort="updateScheduleDate"
                             wire:sort:group="calendar"
                             wire:sort:group-id="{{ $date->toDateString() }}|{{ $equipment->id }}"
                             @class([
                                 'p-1 border-r border-zinc-200/50 dark:border-zinc-700/50 min-h-[80px] transition-colors hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50 shrink-0',
                                 'bg-blue-50/30 dark:bg-blue-900/10' => $date->isToday(),
                             ])>

                            {{-- その設備がこの日で描画されるべきか（最初の表示日または開始日のみ） --}}
                            @foreach($schedules->filter(function($s) use ($equipment, $date, $startRange, $endRange) {
                                if ($s->equipment_id !== $equipment->id) {
                                    return false;
                                }

                                // その日が期間内かチェック
                                if (! $date->betweenIncluded($s->scheduled_start_at->startOfDay(), $s->scheduled_end_at->endOfDay())) {
                                    return false;
                                }

                                // 表示範囲内での実際の開始日
                                $actualStart = $s->scheduled_start_at->max($startRange);

                                // 最初の表示スロット（日）でのみ描画
                                return $actualStart->isSameDay($date);
                            }) as $schedule)
                                @php
                                    $isOtherDepartment = $schedule->productionOrder->department_id !== $this->departmentId;
                                    $actualStart = $schedule->scheduled_start_at->max($startRange);
                                    $actualEnd = $schedule->scheduled_end_at->min($endRange);

                                    // 日数を計算
                                    $totalDays = $date->diffInDays($actualEnd->startOfDay()) + 1;
                                    $colSpan = max(1, $totalDays);

                                    // 描画位置の微調整（24時間を100%とする -> 1日40px）
                                    $startOfDay = $date->copy()->startOfDay();
                                    $offsetMinutes = $startOfDay->diffInMinutes($actualStart);
                                    $marginLeftPx = $offsetMinutes * (40 / 1440);

                                    // 幅の微調整
                                    $durationMinutes = $actualStart->diffInMinutes($actualEnd);
                                    $widthPx = $durationMinutes * (40 / 1440);

                                    $isRealStart = $schedule->scheduled_start_at >= $startRange;
                                    $isRealEnd = $schedule->scheduled_end_at <= $endRange;
                                @endphp
                                <flux:tooltip>
                                    <div wire:key="schedule-day-{{ $schedule->id }}-{{ $date->toDateString() }}"
                                         @if(!$isOtherDepartment) wire:sort:item="{{ $schedule->id }}" @else wire:sort:ignore @endif
                                         @class([
                                              'shadow-sm border p-2 text-xs flex flex-col justify-between group transition-all hover:brightness-95 mb-1 h-20 relative z-20',
                                              'cursor-move' => !$isOtherDepartment,
                                              'cursor-default opacity-60' => $isOtherDepartment,
                                              'rounded-l-md' => $isRealStart,
                                              'rounded-r-md' => $isRealEnd,
                                              'rounded-none' => ! $isRealStart && ! $isRealEnd,
                                              'border-l-0' => ! $isRealStart,
                                              'border-r-0' => ! $isRealEnd,
                                              'bg-blue-100 border-blue-200 text-blue-800 dark:bg-blue-900/40 dark:border-blue-800 dark:text-blue-300' => !$isOtherDepartment && $schedule->status === 'confirmed',
                                              'bg-emerald-100 border-emerald-200 text-emerald-800 dark:bg-emerald-900/40 dark:border-emerald-800 dark:text-emerald-300' => !$isOtherDepartment && $schedule->status === 'completed',
                                              'bg-zinc-200 border-zinc-300 text-zinc-600 dark:bg-zinc-800 dark:border-zinc-700 dark:text-zinc-400' => $isOtherDepartment,
                                         ])
                                         style="grid-column: span {{ $colSpan }}; margin-left: {{ $marginLeftPx }}px; width: {{ $widthPx }}px; min-width: {{ $widthPx }}px;">
                                        <div class="flex flex-col h-full justify-between">
                                            <div>
                                                <div class="flex items-center gap-1 font-bold truncate">
                                                    @if($schedule->status === 'completed' && $isRealStart)
                                                        <flux:icon name="check-circle" variant="micro" class="shrink-0" />
                                                    @endif
                                                    <span title="{{ $schedule->productionOrder->item->name }}">
                                                        @if($isOtherDepartment) [他] @endif {{ $schedule->productionOrder->item->name }}
                                                    </span>
                                                </div>
                                                <div class="text-[9px] opacity-70 truncate">
                                                    {{ $schedule->process->name }} | #{{ $schedule->productionOrder->lot->lot_number ?? 'No Lot' }}
                                                </div>
                                            </div>

                                            <div class="flex flex-col gap-0.5 mt-1">
                                                <div class="flex items-center gap-1 text-[9px] opacity-70">
                                                    <flux:icon name="clock" variant="micro" class="shrink-0" />
                                                    <span class="truncate">
                                                        {{ $schedule->scheduled_start_at->format('H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-1 opacity-70">
                                                    <flux:icon name="user" variant="micro" class="shrink-0" />
                                                    <span class="truncate text-[9px]">{{ $schedule->worker->name ?? '未割当' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <flux:tooltip.content class="max-w-[20rem] space-y-2">
                                        <div class="font-bold text-sm">{{ $schedule->productionOrder->item->name }}</div>
                                        <div class="text-xs text-zinc-500">
                                            @if($isOtherDepartment)
                                                <div>部署: {{ $schedule->productionOrder->department->name ?? '不明' }}</div>
                                            @endif
                                            <div>工程: {{ $schedule->process->name }}</div>
                                            <div>ロット: #{{ $schedule->productionOrder->lot->lot_number ?? 'No Lot' }}</div>
                                            <div>予定: {{ $schedule->scheduled_start_at->format('Y/m/d H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                            <div>担当: {{ $schedule->worker->name ?? '未割当' }}</div>
                                            <div>設備: {{ $schedule->equipment->name ?? '未割当' }}</div>
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
                $totalCols = count($timeSlots);
            @endphp
            <div class="grid border-b border-zinc-200 dark:border-zinc-700 min-h-[80px]"
                 style="grid-template-columns: 250px repeat({{ $totalCols }}, 40px);">

                {{-- 左側：設備ラベル --}}
                <div class="p-3 border-r border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex flex-col justify-center sticky left-0 z-30 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                    <div class="text-sm font-bold truncate">{{ $equipment->name }}</div>
                    <div class="text-[10px] text-zinc-400 truncate">{{ $equipment->code }}</div>
                </div>

                {{-- 右側：時間セル --}}
                <div class="contents">
                    @foreach($timeSlots as $slot)
                        <div wire:sort="updateScheduleDate"
                             wire:sort:group="calendar"
                             wire:sort:group-id="{{ $slot->format('Y-m-d H:i') }}|{{ $equipment->id }}"
                             @class([
                                 'border-r border-zinc-200/50 dark:border-zinc-700/50 min-h-[80px] transition-colors hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50 shrink-0',
                                 'border-r-2' => $slot->minute === (60 - $intervalMinutes),
                             ])>

                            {{-- その日が、開始予定日から完了予定日の期間内に含まれる場合に表示 --}}
                            @foreach($schedules->filter(function($s) use ($equipment, $targetDay, $slot) {
                                if ($s->equipment_id !== $equipment->id) {
                                    return false;
                                }

                                // その日が期間内かチェック
                                if (! $targetDay->betweenIncluded($s->scheduled_start_at->startOfDay(), $s->scheduled_end_at->endOfDay())) {
                                    return false;
                                }

                                // その日の表示範囲（0:00-24:00）内での開始・終了スロットを特定
                                $viewStart = $targetDay->copy()->setHour(0)->setMinute(0);
                                $actualStart = $s->scheduled_start_at->max($viewStart);

                                // そのアイテムがこのスロットで描画されるべきか
                                $slotStart = $slot->copy();
                                $slotEnd = $slot->copy()->addMinutes($this->intervalMinutes);

                                return $actualStart->betweenIncluded($slotStart, $slotEnd->subSecond());
                            }) as $schedule)
                                @php
                                    $isOtherDepartment = $schedule->productionOrder->department_id !== $this->departmentId;
                                    $viewStart = $targetDay->copy()->setHour(0)->setMinute(0);
                                    $viewEnd = $targetDay->copy()->setHour(23)->setMinute(59)->setSecond(59);

                                    $actualStart = $schedule->scheduled_start_at->max($viewStart);
                                    $actualEnd = $schedule->scheduled_end_at->min($viewEnd);

                                    // スロット数を計算 (1スロット = $intervalMinutes 分)
                                    $totalMinutes = $slot->diffInMinutes($actualEnd);
                                    $colSpan = max(1, (int) ceil($totalMinutes / $intervalMinutes));

                                    // 描画位置の微調整
                                    $offsetMinutes = $slot->diffInMinutes($actualStart);
                                    $marginLeftPx = ($offsetMinutes > 0) ? $offsetMinutes * (40 / $intervalMinutes) : 0;

                                    // 幅の微調整
                                    $durationMinutes = $actualStart->diffInMinutes($actualEnd);
                                    $widthPx = $durationMinutes * (40 / $intervalMinutes);

                                    $isRealStart = $schedule->scheduled_start_at->isSameDay($targetDay) && $schedule->scheduled_start_at >= $viewStart;
                                    $isRealEnd = $schedule->scheduled_end_at->isSameDay($targetDay) && $schedule->scheduled_end_at <= $viewEnd;
                                @endphp
                                <flux:tooltip>
                                    <div wire:key="schedule-hour-{{ $schedule->id }}-{{ $slot->format('Hi') }}"
                                         @if(!$isOtherDepartment) wire:sort:item="{{ $schedule->id }}" @else wire:sort:ignore @endif
                                         @class([
                                              'shadow-sm border p-2 text-[10px] flex flex-col justify-center group transition-all hover:brightness-95 mb-1 relative z-20 min-h-[60px] m-1',
                                              'cursor-move' => !$isOtherDepartment,
                                              'cursor-default opacity-60' => $isOtherDepartment,
                                              'rounded-l-md' => $isRealStart,
                                              'rounded-r-md' => $isRealEnd,
                                              'rounded-none' => ! $isRealStart && ! $isRealEnd,
                                              'border-l-0' => ! $isRealStart,
                                              'border-r-0' => ! $isRealEnd,
                                              'bg-blue-100/80 border-blue-200 text-blue-800 dark:bg-blue-900/40 dark:border-blue-800 dark:text-blue-300' => !$isOtherDepartment && $schedule->status === 'confirmed',
                                              'bg-emerald-100/80 border-emerald-200 text-emerald-800 dark:bg-emerald-900/40 dark:border-emerald-800 dark:text-emerald-300' => !$isOtherDepartment && $schedule->status === 'completed',
                                              'bg-zinc-200 border-zinc-300 text-zinc-600 dark:bg-zinc-800 dark:border-zinc-700 dark:text-zinc-400' => $isOtherDepartment,
                                         ])
                                         style="grid-column: span {{ $colSpan }}; margin-left: {{ $marginLeftPx }}px; width: {{ $widthPx }}px">
                                        <div class="flex flex-col gap-1">
                                            <div>
                                                <div class="flex items-center gap-1 font-bold truncate">
                                                    @if($schedule->status === 'completed' && $isRealStart)
                                                        <flux:icon name="check-circle" variant="micro" class="shrink-0" />
                                                    @endif
                                                    <span title="{{ $schedule->productionOrder->item->name }}">
                                                        @if($isOtherDepartment) [他] @endif {{ $schedule->productionOrder->item->name }}
                                                    </span>
                                                </div>
                                                <div class="text-[9px] opacity-70 truncate">
                                                    {{ $schedule->process->name }} | #{{ $schedule->productionOrder->lot->lot_number ?? 'No Lot' }}
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 text-[9px] opacity-80">
                                                <div class="flex items-center gap-1 shrink-0">
                                                    <flux:icon name="clock" variant="micro" class="shrink-0" />
                                                    <span>{{ $schedule->scheduled_start_at->format('H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</span>
                                                </div>
                                                @if($schedule->worker)
                                                    <div class="flex items-center gap-1 truncate">
                                                        <flux:icon name="user" variant="micro" class="shrink-0" />
                                                        <span>{{ $schedule->worker->name }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <flux:tooltip.content class="max-w-[20rem] space-y-2">
                                        <div class="font-bold text-sm">{{ $schedule->productionOrder->item->name }}</div>
                                        <div class="text-xs text-zinc-500">
                                            @if($isOtherDepartment)
                                                <div>部署: {{ $schedule->productionOrder->department->name ?? '不明' }}</div>
                                            @endif
                                            <div>工程: {{ $schedule->process->name }}</div>
                                            <div>ロット: #{{ $schedule->productionOrder->lot->lot_number ?? 'No Lot' }}</div>
                                            <div>予定: {{ $schedule->scheduled_start_at->format('Y/m/d H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                            <div>担当: {{ $schedule->worker->name ?? '未割当' }}</div>
                                            <div>設備: {{ $schedule->equipment->name ?? '未割当' }}</div>
                                        </div>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @empty
        <div class="py-20 text-center text-zinc-400">
            設備が登録されていません
        </div>
    @endforelse

    {{-- 設備が未割当のスケジュールを表示する行 --}}
    @php
        $unassignedSchedules = $schedules->filter(fn($s) => is_null($s->equipment_id));
        $startRange = Carbon::parse($startDate);
        $endRange = $startRange->copy()->addDays($daysToShow);
    @endphp

    @if($unassignedSchedules->isNotEmpty())
        @if($viewMode === 'day')
             <div class="grid border-b border-zinc-200 dark:border-zinc-700 min-h-[80px]"
                 style="grid-template-columns: 250px repeat({{ $daysToShow }}, 40px);">
                <div class="p-3 border-r border-zinc-200 dark:border-zinc-700 bg-orange-50/50 dark:bg-orange-900/10 flex flex-col justify-center sticky left-0 z-30 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                    <div class="text-sm font-bold text-orange-600 dark:text-orange-400">未割当</div>
                </div>
                <div class="contents">
                    @foreach($dates as $date)
                        <div wire:sort="updateScheduleDate"
                             wire:sort:group="calendar"
                             wire:sort:group-id="{{ $date->toDateString() }}|unassigned"
                             class="p-1 border-r border-zinc-200/50 dark:border-zinc-700/50 min-h-[80px] bg-orange-50/20 dark:bg-orange-900/5 shrink-0">
                            @foreach($unassignedSchedules->filter(function($s) use ($date, $startRange) {
                               return $s->scheduled_start_at->max($startRange)->isSameDay($date);
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
                                @endphp
                                <flux:tooltip>
                                    <div wire:key="schedule-unassigned-day-{{ $schedule->id }}-{{ $date->toDateString() }}"
                                         @if(!$isOtherDepartment) wire:sort:item="{{ $schedule->id }}" @else wire:sort:ignore @endif
                                         @class([
                                              'shadow-sm border p-2 text-xs flex flex-col justify-between group transition-all hover:brightness-95 mb-1 h-20 relative z-20 rounded-md',
                                              'cursor-move' => !$isOtherDepartment,
                                              'cursor-default opacity-60' => $isOtherDepartment,
                                              'border-orange-200 bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:border-orange-800 dark:text-orange-300' => !$isOtherDepartment,
                                              'bg-zinc-200 border-zinc-300 text-zinc-600 dark:bg-zinc-800 dark:border-zinc-700 dark:text-zinc-400' => $isOtherDepartment,
                                         ])
                                         style="grid-column: span {{ $colSpan }}; margin-left: {{ $marginLeftPx }}px; width: {{ $widthPx }}px; min-width: {{ $widthPx }}px;">
                                        <div class="flex flex-col h-full justify-between">
                                            <div>
                                                <div class="font-bold truncate">@if($isOtherDepartment) [他] @endif {{ $schedule->productionOrder->item->name }}</div>
                                                <div class="text-[9px] opacity-70 truncate">{{ $schedule->process->name }}</div>
                                            </div>
                                            <div class="text-[9px] opacity-70">{{ $schedule->scheduled_start_at->format('H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                        </div>
                                    </div>

                                    <flux:tooltip.content class="max-w-[20rem] space-y-2">
                                        <div class="font-bold text-sm">{{ $schedule->productionOrder->item->name }}</div>
                                        <div class="text-xs text-zinc-500">
                                            @if($isOtherDepartment)
                                                <div>部署: {{ $schedule->productionOrder->department->name ?? '不明' }}</div>
                                            @endif
                                            <div>工程: {{ $schedule->process->name }}</div>
                                            <div>ロット: #{{ $schedule->productionOrder->lot->lot_number ?? 'No Lot' }}</div>
                                            <div>予定: {{ $schedule->scheduled_start_at->format('Y/m/d H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                            <div>担当: {{ $schedule->worker->name ?? '未割当' }}</div>
                                            <div>設備: 未割当</div>
                                        </div>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            @endforeach
                        </div>
                    @endforeach
                </div>
             </div>
        @else
           {{-- 時間表示モードの未割当 --}}
           @php $targetDay = Carbon::parse($startDate)->startOfDay(); @endphp
           <div class="grid border-b border-zinc-200 dark:border-zinc-700 min-h-[80px]"
                style="grid-template-columns: 250px repeat({{ count($timeSlots) }}, 40px);">
                <div class="p-3 border-r border-zinc-200 dark:border-zinc-700 bg-orange-50/50 dark:bg-orange-900/10 flex flex-col justify-center sticky left-0 z-30 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                    <div class="text-sm font-bold text-orange-600 dark:text-orange-400">未割当</div>
                </div>
                <div class="contents">
                    @foreach($timeSlots as $slot)
                        <div wire:sort="updateScheduleDate"
                             wire:sort:group="calendar"
                             wire:sort:group-id="{{ $slot->format('Y-m-d H:i') }}|unassigned"
                             @class([
                                 'p-1 border-r border-zinc-200/50 dark:border-zinc-700/50 min-h-[80px] bg-orange-50/20 dark:bg-orange-900/5 shrink-0',
                                 'border-r-2' => $slot->minute === (60 - $intervalMinutes),
                             ])>
                            @foreach($unassignedSchedules->filter(function($s) use ($targetDay, $slot) {
                               $viewStart = $targetDay->copy()->setHour(0)->setMinute(0);
                               $actualStart = $s->scheduled_start_at->max($viewStart);
                               $slotStart = $slot->copy();
                               $slotEnd = $slot->copy()->addMinutes($this->intervalMinutes);
                               return $actualStart->betweenIncluded($slotStart, $slotEnd->subSecond());
                            }) as $schedule)
                                @php
                                    $isOtherDepartment = $schedule->productionOrder->department_id !== $this->departmentId;
                                    $viewStart = $targetDay->copy()->setHour(0)->setMinute(0);
                                    $viewEnd = $targetDay->copy()->setHour(23)->setMinute(59)->setSecond(59);
                                    $actualStart = $schedule->scheduled_start_at->max($viewStart);
                                    $actualEnd = $schedule->scheduled_end_at->min($viewEnd);
                                    $totalMinutes = $slot->diffInMinutes($actualEnd);
                                    $colSpan = max(1, (int) ceil($totalMinutes / $intervalMinutes));
                                    $offsetMinutes = $slot->diffInMinutes($actualStart);
                                    $marginLeftPx = ($offsetMinutes > 0) ? $offsetMinutes * (40 / $intervalMinutes) : 0;
                                    $durationMinutes = $actualStart->diffInMinutes($actualEnd);
                                    $widthPx = $durationMinutes * (40 / $intervalMinutes);
                                @endphp
                                <flux:tooltip>
                                    <div wire:key="schedule-unassigned-hour-{{ $schedule->id }}-{{ $slot->format('Hi') }}"
                                         @if(!$isOtherDepartment) wire:sort:item="{{ $schedule->id }}" @else wire:sort:ignore @endif
                                         @class([
                                              'shadow-sm border p-2 text-[10px] flex flex-col justify-center group transition-all hover:brightness-95 mb-1 relative z-20 min-h-[60px] rounded-md',
                                              'cursor-move' => !$isOtherDepartment,
                                              'cursor-default opacity-60' => $isOtherDepartment,
                                              'border-orange-200 bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:border-orange-800 dark:text-orange-300' => !$isOtherDepartment,
                                              'bg-zinc-200 border-zinc-300 text-zinc-600 dark:bg-zinc-800 dark:border-zinc-700 dark:text-zinc-400' => $isOtherDepartment,
                                         ])
                                         style="grid-column: span {{ $colSpan }}; margin-left: {{ $marginLeftPx }}px; width: {{ $widthPx }}px">
                                        <div class="flex flex-col gap-1">
                                            <div>
                                                <div class="font-bold truncate">@if($isOtherDepartment) [他] @endif {{ $schedule->productionOrder->item->name }}</div>
                                                <div class="text-[9px] opacity-70 truncate">{{ $schedule->process->name }}</div>
                                            </div>
                                            <div class="text-[9px] opacity-70">{{ $schedule->scheduled_start_at->format('H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                        </div>
                                    </div>

                                    <flux:tooltip.content class="max-w-[20rem] space-y-2">
                                        <div class="font-bold text-sm">{{ $schedule->productionOrder->item->name }}</div>
                                        <div class="text-xs text-zinc-500">
                                            @if($isOtherDepartment)
                                                <div>部署: {{ $schedule->productionOrder->department->name ?? '不明' }}</div>
                                            @endif
                                            <div>工程: {{ $schedule->process->name }}</div>
                                            <div>ロット: #{{ $schedule->productionOrder->lot->lot_number ?? 'No Lot' }}</div>
                                            <div>予定: {{ $schedule->scheduled_start_at->format('Y/m/d H:i') }} - {{ $schedule->scheduled_end_at->format('H:i') }}</div>
                                            <div>担当: {{ $schedule->worker->name ?? '未割当' }}</div>
                                            <div>設備: 未割当</div>
                                        </div>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
