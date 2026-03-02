<?php

use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lastdino\Monox\Models\ProductionSchedule;
use Lastdino\Monox\Traits\EnsuresPermissionsConfigured;
use Livewire\Component;

new class extends Component
{
    use EnsuresPermissionsConfigured;

    public int $departmentId;

    public string $startDate;

    public int $daysToShow = 31;

    public string $viewMode = 'day'; // day, hour

    public string $viewType = 'equipment'; // equipment, process

    public $itemId = null;

    public int $intervalMinutes = 5; // hourビューの時幅

    public function updatedItemId($value): void
    {
        if ($value === '' || $value === 'null' || $value === null) {
            $this->itemId = null;
        } else {
            $this->itemId = (int) $value;
        }
    }

    public function mount($department): void
    {
        if ($department instanceof \Illuminate\Database\Eloquent\Model) {
            $this->departmentId = $department->getKey();
        } else {
            $this->departmentId = (int) $department;
        }

        $this->startDate = now()->startOfMonth()->toDateString();
        $this->daysToShow = now()->daysInMonth;
    }

    public function schedules()
    {
        $start = Carbon::parse($this->startDate);
        $end = $start->copy()->addDays($this->daysToShow)->endOfDay();

        $equipmentModel = config('monox.models.equipment', \Lastdino\Monox\Models\Equipment::class);
        $equipmentIds = $equipmentModel::whereHas('departments', function ($q) {
            $q->where($q->getModel()->getQualifiedKeyName(), $this->departmentId);
        })->pluck('id')->toArray();

        return ProductionSchedule::with(['productionOrder.item', 'productionOrder.lot', 'productionOrder.department', 'process', 'worker', 'equipment'])
            ->where(function ($q) use ($equipmentIds) {
                $q->whereHas('productionOrder', function ($sq) {
                    $sq->where('department_id', $this->departmentId);
                })->orWhereIn('equipment_id', $equipmentIds);
            })
            ->when($this->itemId, function ($q) {
                $q->whereHas('productionOrder', function ($sq) {
                    $sq->where('item_id', $this->itemId);
                });
            })
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_start_at', [$start, $end])
                    ->orWhereBetween('scheduled_end_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('scheduled_start_at', '<=', $start)
                            ->where('scheduled_end_at', '>=', $end);
                    });
            })
            ->orderBy('scheduled_start_at')
            ->orderBy('sort_order')
            ->get();
    }

    public function updateScheduleDate($id, $position, $targetGroupId = null): void
    {
        // $targetGroupId contains "YYYY-MM-DD|equipment_id" or "YYYY-MM-DD HH:mm|equipment_id"
        if (! $targetGroupId || $targetGroupId === 'source') {
            return;
        }

        [$targetValue, $equipmentId] = explode('|', $targetGroupId);
        $equipmentId = ($equipmentId === 'unassigned') ? null : (int) $equipmentId;

        $schedule = ProductionSchedule::find($id);

        if (! $schedule) {
            return;
        }

        if ($schedule->productionOrder->department_id !== $this->departmentId) {
            Flux::toast('他部門のスケジュールは変更できません。', variant: 'danger');

            return;
        }

        // 設備が指定されている場合、その設備が工程に対応しているかチェック
        if ($equipmentId !== null) {
            $isCapable = DB::table('monox_process_equipment')
                ->where('process_id', $schedule->process_id)
                ->where('equipment_id', $equipmentId)
                ->exists();

            if (! $isCapable) {
                Flux::toast('指定された設備はこの工程に対応していません。', variant: 'danger');

                return;
            }
        }

        if ($this->viewMode === 'day') {
            $currentDate = $schedule->scheduled_start_at->copy()->startOfDay();
            $targetDate = Carbon::parse($targetValue)->startOfDay();

            $dateDiffDays = $currentDate->diffInDays($targetDate, false);
            $minutesDiff = 0;

            // 日表示の場合、同じ設備に既に予定があるなら、その一番最後の工程の後に配置する
            if ($equipmentId !== null) {
                $lastScheduleOnDay = ProductionSchedule::where('equipment_id', $equipmentId)
                    ->where('id', '!=', $id)
                    ->whereDate('scheduled_start_at', $targetDate)
                    ->orderByDesc('scheduled_end_at')
                    ->first();

                if ($lastScheduleOnDay) {
                    $newStart = $lastScheduleOnDay->scheduled_end_at->copy();
                    $durationMinutes = $schedule->scheduled_start_at->diffInMinutes($schedule->scheduled_end_at);
                    $newEnd = $newStart->copy()->addMinutes($durationMinutes);

                    $dateDiffDays = $currentDate->diffInDays($newStart->copy()->startOfDay(), false);
                    $minutesDiff = $schedule->scheduled_start_at->copy()->addDays($dateDiffDays)->diffInMinutes($newStart, false);
                }
            }
        } else {
            // hour mode: targetValue is "YYYY-MM-DD HH:mm"
            $currentTargetTime = $schedule->scheduled_start_at->copy()->floorMinute($this->intervalMinutes);
            $newTargetTime = Carbon::parse($targetValue);

            $dateDiffDays = 0;
            $minutesDiff = $currentTargetTime->diffInMinutes($newTargetTime, false);
        }

        // 変更がない場合はスキップ
        if ($dateDiffDays === 0 && $minutesDiff === 0 && $schedule->equipment_id === $equipmentId) {
            return;
        }

        // 重複チェック
        if ($equipmentId !== null) {
            $newStart = $schedule->scheduled_start_at->copy();
            $newEnd = $schedule->scheduled_end_at->copy();

            if ($this->viewMode === 'day') {
                // 自動配置ロジックで既に計算済みの可能性を考慮し、再計算するか、フラグで制御するか
                // ここでは単純に適用後の時間を計算
                $newStart = $newStart->addDays($dateDiffDays)->addMinutes($minutesDiff);
                $newEnd = $newEnd->addDays($dateDiffDays)->addMinutes($minutesDiff);
            } else {
                $newStart = $newStart->addMinutes($minutesDiff);
                $newEnd = $newEnd->addMinutes($minutesDiff);
            }

            $overlapExists = ProductionSchedule::query()
                ->where('id', '!=', $id)
                ->where('equipment_id', $equipmentId)
                ->where(function ($query) use ($newStart, $newEnd) {
                    $query->where(function ($q) use ($newStart, $newEnd) {
                        $q->where('scheduled_start_at', '<', $newEnd)
                            ->where('scheduled_end_at', '>', $newStart);
                    });
                })
                ->exists();

            if ($overlapExists) {
                Flux::toast('指定された時間帯には既に他の予定が入っています。', variant: 'danger');

                return;
            }
        }

        DB::transaction(function () use ($schedule, $dateDiffDays, $minutesDiff, $equipmentId) {
            // 移動したスケジュールの更新
            $schedule->scheduled_start_at = $schedule->scheduled_start_at->addDays($dateDiffDays)->addMinutes($minutesDiff);
            $schedule->scheduled_end_at = $schedule->scheduled_end_at->addDays($dateDiffDays)->addMinutes($minutesDiff);

            $schedule->equipment_id = $equipmentId;
            $schedule->save();

            // 同じ指図内の後続工程も、日付/時間の移動のみ同期させる（設備は移動させない）
            if ($dateDiffDays !== 0 || $minutesDiff !== 0) {
                $subsequentSchedules = ProductionSchedule::where('production_order_id', $schedule->production_order_id)
                    ->where('id', '!=', $schedule->id)
                    ->whereHas('process', function ($query) use ($schedule) {
                        $query->where('sort_order', '>', $schedule->process->sort_order);
                    })
                    ->get();

                foreach ($subsequentSchedules as $subsequent) {
                    $subsequent->scheduled_start_at = $subsequent->scheduled_start_at->addDays($dateDiffDays)->addMinutes($minutesDiff);
                    $subsequent->scheduled_end_at = $subsequent->scheduled_end_at->addDays($dateDiffDays)->addMinutes($minutesDiff);
                    $subsequent->save();
                }
            }
        });

        Flux::toast('スケジュールを更新しました。');
    }

    public function updatedStartDate(): void
    {
        if ($this->viewMode === 'day') {
            $this->daysToShow = Carbon::parse($this->startDate)->daysInMonth;
        }
    }

    public function moveNext(): void
    {
        if ($this->viewMode === 'day') {
            $this->startDate = Carbon::parse($this->startDate)->addMonth()->startOfMonth()->toDateString();
            $this->daysToShow = Carbon::parse($this->startDate)->daysInMonth;
        } else {
            $this->startDate = Carbon::parse($this->startDate)->addDay()->toDateString();
        }
    }

    public function movePrev(): void
    {
        if ($this->viewMode === 'day') {
            $this->startDate = Carbon::parse($this->startDate)->subMonth()->startOfMonth()->toDateString();
            $this->daysToShow = Carbon::parse($this->startDate)->daysInMonth;
        } else {
            $this->startDate = Carbon::parse($this->startDate)->subDay()->toDateString();
        }
    }

    public function moveToday(): void
    {
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->daysToShow = now()->daysInMonth;
    }

    public function render()
    {
        $dates = [];
        $start = Carbon::parse($this->startDate);
        for ($i = 0; $i < $this->daysToShow; $i++) {
            $dates[] = $start->copy()->addDays($i);
        }

        $equipmentModel = config('monox.models.equipment', \Lastdino\Monox\Models\Equipment::class);
        $equipments = $equipmentModel::whereHas('departments', function ($q) {
            $q->where($q->getModel()->getQualifiedKeyName(), $this->departmentId);
        })
            ->when(Schema::hasColumn((new $equipmentModel)->getTable(), 'sort_order'), function ($q) {
                $q->orderBy('sort_order');
            })
            ->get();

        return view('monox::pages.production.⚡scheduler', [
            'dates' => $dates,
            'equipments' => $equipments,
            'schedules' => $this->schedules(),
            'items' => \Lastdino\Monox\Models\Item::where('department_id', $this->departmentId)->get(),
        ]);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">工程スケジューラー</flux:heading>
            <x-monox::nav-menu :department="$departmentId" />
        </div>

        <div class="flex items-center gap-4">
            <flux:select wire:model.live="itemId" placeholder="すべての製品" size="sm" class="w-64">
                <flux:select.option :value="null">すべての製品</flux:select.option>
                @foreach($items as $item)
                    <flux:select.option :value="$item->id">{{ $item->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="viewType" variant="segmented" size="sm">
                <flux:radio value="equipment" label="設備別" />
                <flux:radio value="process" label="工程別" />
            </flux:radio.group>

            <flux:radio.group wire:model.live="viewMode" variant="segmented" size="sm">
                <flux:radio value="day" label="日" />
                <flux:radio value="hour" label="時間" />
            </flux:radio.group>

            @if($viewMode === 'hour')
                <flux:select wire:model.live="intervalMinutes" size="sm" class="w-32">
                    <flux:select.option value="5">5分</flux:select.option>
                    <flux:select.option value="10">10分</flux:select.option>
                    <flux:select.option value="15">15分</flux:select.option>
                    <flux:select.option value="30">30分</flux:select.option>
                    <flux:select.option value="60">1時間</flux:select.option>
                </flux:select>
            @endif

            <flux:button.group>
                <flux:button variant="outline" icon="chevron-left" wire:click="movePrev" />
                <flux:button variant="outline" wire:click="moveToday">今日</flux:button>
                <flux:button variant="outline" icon="chevron-right" wire:click="moveNext" />
            </flux:button.group>

            <flux:input type="date" wire:model.live="startDate" size="sm" />
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
            <div class="inline-grid min-w-full">
                {{-- カレンダーヘッダー --}}
                @if($viewMode === 'day')
                    <div class="grid sticky top-0 z-50" style="grid-template-columns: 250px repeat({{ $daysToShow }}, 40px);">
                        <div class="p-3 border-b border-r border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 font-bold text-sm sticky left-0 top-0 z-50 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                            {{ $viewType === 'equipment' ? '設備' : '工程' }}
                        </div>
                        <div class="contents">
                            @foreach($dates as $date)
                                <div @class([
                                    'p-2 text-center border-b border-zinc-200 dark:border-zinc-700 text-xs font-medium sticky top-0 z-40 shrink-0',
                                    'bg-zinc-50 dark:bg-zinc-800' => ! $date->isToday(),
                                    'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' => $date->isToday(),
                                    'text-red-500' => $date->isSunday(),
                                    'text-blue-500' => $date->isSaturday(),
                                ])>
                                    <div class="uppercase">{{ $date->translatedFormat('D') }}</div>
                                    <div class="text-lg">{{ $date->format('d') }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    @php
                        $startHour = 0;
                        $endHour = 24;
                        $targetDay = Carbon::parse($startDate)->startOfDay();

                        $timeSlots = [];
                        for ($h = $startHour; $h < $endHour; $h++) {
                            for ($m = 0; $m < 60; $m += $this->intervalMinutes) {
                                $timeSlots[] = $targetDay->copy()->setHour($h)->setMinute($m);
                            }
                        }
                        $totalCols = count($timeSlots);
                    @endphp
                    <div class="grid sticky top-0 z-50" style="grid-template-columns: 250px repeat({{ $totalCols }}, 40px);">
                        <div class="p-3 border-b border-r border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 font-bold text-sm sticky left-0 top-0 z-50 shadow-[1px_0_0_0_rgba(0,0,0,0.1)] w-[250px] shrink-0">
                            {{ $targetDay->format('m/d') }} ({{ $targetDay->translatedFormat('D') }})
                        </div>
                        <div class="contents">
                            @foreach($timeSlots as $slot)
                                <div @class([
                                    'p-2 text-center border-b border-zinc-200 dark:border-zinc-700 text-[10px] font-medium shrink-0 sticky top-0 z-40 w-[40px]',
                                    'bg-zinc-50 dark:bg-zinc-800',
                                    'border-r-2' => $slot->minute === (60 - $this->intervalMinutes),
                                ])>
                                    {{ $slot->format('H:i') }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- カレンダーボディ --}}
                @if($viewType === 'equipment')
                    <livewire:monox::production.scheduler-equipment-view
                        wire:key="equipment-view-{{ (string) $itemId }}-{{ $startDate }}-{{ $viewMode }}-{{ $intervalMinutes }}"
                        :department-id="$departmentId"
                        :dates="collect($dates)"
                        :equipments="$equipments"
                        :schedules="$schedules"
                        :view-mode="$viewMode"
                        :interval-minutes="$intervalMinutes"
                        :start-date="$startDate"
                        :days-to-show="$daysToShow"
                    />
                @else
                    <livewire:monox::production.scheduler-process-view
                        wire:key="process-view-{{ (string) $itemId }}-{{ $startDate }}-{{ $viewMode }}-{{ $intervalMinutes }}"
                        :department-id="$departmentId"
                        :dates="collect($dates)"
                        :schedules="$schedules"
                        :view-mode="$viewMode"
                        :interval-minutes="$intervalMinutes"
                        :start-date="$startDate"
                        :days-to-show="$daysToShow"
                    />
                @endif
            </div>
        </div>
    </div>
</div>
