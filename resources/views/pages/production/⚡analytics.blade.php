<?php

use Illuminate\Support\Facades\DB;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Process;
use Lastdino\Monox\Models\ProductionAnnotationField;
use Lastdino\Monox\Models\ProductionAnnotationValue;
use Lastdino\Monox\Models\ProductionOrder;
use Lastdino\Monox\Models\ProductionRecord;
use Lastdino\Monox\Models\SalesOrder;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public int $departmentId;

    // Filter properties for Production Results
    public ?int $selectedItemId = null;

    public ?int $selectedProcessId = null;

    // Filter properties for Trend Chart
    public ?int $chartItemId = null;

    public ?int $chartProcessId = null;

    public array $chartFieldIds = [];

    public string $calcMode = 'avg';

    public bool $showSpcLimits = true;

    public int $chartLimit = 50;

    public function mount($department): void
    {
        if ($department instanceof \Illuminate\Database\Eloquent\Model) {
            $this->departmentId = $department->getKey();
        } else {
            $this->departmentId = (int) $department;
        }
    }

    /**
     * 1. 納期間近の受注 (今日から7日以内)
     */
    public function nearDueOrders()
    {
        return SalesOrder::where('department_id', $this->departmentId)
            ->whereNotIn('status', ['shipped', 'cancelled'])
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->with(['partner', 'item'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * 1. 納期遅延の受注 (今日より前)
     */
    public function overdueOrders()
    {
        return SalesOrder::where('department_id', $this->departmentId)
            ->whereNotIn('status', ['shipped', 'cancelled'])
            ->where('due_date', '<', now()->toDateString())
            ->with(['partner', 'item'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * 2. 在庫不足一覧 (BOM含む)
     */
    public function stockShortages(): array
    {
        $shortages = [];
        $processedItems = [];

        // 1. 在庫アラート設定があり、かつ在庫がアラート数を下回っている品目をまず抽出
        $alertItems = Item::where('department_id', $this->departmentId)
            ->where('inventory_alert_quantity', '>', 0)
            ->get()
            ->filter(fn ($item) => $item->current_stock < $item->inventory_alert_quantity);

        foreach ($alertItems as $item) {
            $this->calculateShortage($item->id, 0, $shortages, $processedItems);
        }

        // 2. 未出荷の受注を品目ごとに集計し、不足を計算
        $demand = SalesOrder::where('department_id', $this->departmentId)
            ->whereNotIn('status', ['shipped', 'cancelled'])
            ->select('item_id', DB::raw('SUM(quantity) as total_demand'))
            ->groupBy('item_id')
            ->get();

        foreach ($demand as $order) {
            $this->calculateShortage($order->item_id, $order->total_demand, $shortages, $processedItems);
        }

        return array_values($shortages);
    }

    private function calculateShortage(int $itemId, float $requiredQty, array &$shortages, array &$processedItems, int $level = 0, ?string $parentName = null): void
    {
        $item = Item::find($itemId);
        if (! $item) {
            return;
        }

        $currentStock = $item->current_stock;

        // すでに不足計算に含めているか（再帰の無限ループ防止と集計のため）
        // ただし、BOM階層を表示したい場合はキーを工夫する必要がある
        $key = $itemId;

        if (! isset($shortages[$key])) {
            $shortages[$key] = [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->code,
                'required' => 0,
                'stock' => $currentStock,
                'alert_quantity' => $item->inventory_alert_quantity ?? 0,
                'level' => $level,
                'parent' => $parentName,
            ];
        }

        $shortages[$key]['required'] += $requiredQty;

        // 不足分がある場合、BOMを展開
        $shortageQty = max(0, $shortages[$key]['required'] - $currentStock);

        if ($shortageQty > 0) {
            $components = $item->components; // BelongsToMany
            foreach ($components as $component) {
                $childRequired = $shortageQty * $component->pivot->quantity;
                $this->calculateShortage($component->id, $childRequired, $shortages, $processedItems, $level + 1, $item->name);
            }
        }
    }

    /**
     * 3. 直近の製造実績
     */
    public function recentProductionResults()
    {
        return ProductionRecord::query()
            ->whereHas('productionOrder', function ($q) {
                $q->where('department_id', $this->departmentId);
            })
            ->when($this->selectedItemId, function ($q) {
                $q->whereHas('productionOrder', fn ($qo) => $qo->where('item_id', $this->selectedItemId));
            })
            ->when($this->selectedProcessId, function ($q) {
                $q->where('process_id', $this->selectedProcessId);
            })
            ->with(['productionOrder.item', 'process'])
            ->whereNotNull('work_finished_at')
            ->orderBy('work_finished_at', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * 4. トレンドチャートデータ
     */
    public function trendChartData(): array
    {
        return \Lastdino\Monox\Services\TrendService::buildTrendData(
            $this->chartFieldIds,
            $this->chartProcessId,
            $this->calcMode,
            $this->chartLimit
        );
    }

    public function getItemsProperty()
    {
        return Item::where('department_id', $this->departmentId)->get();
    }

    public function getProcessesProperty()
    {
        if ($this->selectedItemId) {
            return Item::find($this->selectedItemId)->processes;
        }

        return Process::whereHas('item', fn ($q) => $q->where('department_id', $this->departmentId))->get()->unique('name');
    }

    public function getChartProcessesProperty()
    {
        if ($this->chartItemId) {
            return Item::find($this->chartItemId)->processes;
        }

        return Process::whereHas('item', fn ($q) => $q->where('department_id', $this->departmentId))->get()->unique('name');
    }

    public function getChartFieldsProperty()
    {
        if ($this->chartProcessId) {
            return ProductionAnnotationField::where('process_id', $this->chartProcessId)
                ->whereIn('type', ['number', 'material', 'material_quantity'])
                ->get();
        }

        return collect();
    }

    public function updatedChartItemId()
    {
        $this->chartProcessId = null;
        $this->chartFieldIds = [];
    }

    public function updatedChartProcessId()
    {
        $this->chartFieldIds = [];
    }

    public function updatedChartFieldIds()
    {
        $this->dispatch('chart-data-updated', data: $this->trendChartData());
    }

    public function updatedCalcMode()
    {
        $this->dispatch('chart-data-updated', data: $this->trendChartData());
    }

    public function updatedShowSpcLimits()
    {
        $this->dispatch('chart-data-updated', data: $this->trendChartData());
    }

    public function updatedChartLimit()
    {
        if (!empty($this->chartFieldIds)) {
            $this->dispatch('chart-data-updated', data: $this->trendChartData());
        }
    }

    /**
     * 資産評価: 在庫資産
     */
    public function getInventoryValuationProperty(): float
    {
        return Item::where('department_id', $this->departmentId)
            ->get()
            ->sum(fn ($item) => $item->current_stock * ($item->unit_price ?? 0));
    }

    /**
     * 資産評価: 仕掛品資産
     */
    public function getWipValuationProperty(): float
    {
        $total = 0;
        $orders = ProductionOrder::where('department_id', $this->departmentId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->with(['productionRecords.process', 'item.processes'])
            ->get();

        foreach ($orders as $order) {
            $currentProcess = $order->currentProcess();
            if ($currentProcess && $currentProcess->work_in_process_unit_price) {
                $total += $order->target_quantity * $currentProcess->work_in_process_unit_price;
            }
        }

        return $total;
    }
};
?>

<div>
    <div class="mb-6 flex justify-between items-end">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">製造分析ダッシュボード</flux:heading>
            <x-monox::nav-menu :department="$departmentId" />
        </div>
        <div class="flex gap-4">
            <div class="text-right">
                <div class="text-xs text-zinc-500 font-medium">在庫評価額</div>
                <div class="text-lg font-bold text-zinc-800 dark:text-white">¥{{ number_format($this->inventoryValuation) }}</div>
            </div>
            <div class="text-right border-l pl-4">
                <div class="text-xs text-zinc-500 font-medium">仕掛品評価額</div>
                <div class="text-lg font-bold text-zinc-800 dark:text-white">¥{{ number_format($this->wipValuation) }}</div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 1. 納期間近・遅延の受注 -->
        <flux:card>
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">納期間近・遅延の受注</flux:heading>
                <flux:badge color="red" variant="solid">{{ count($this->overdueOrders()) }} 件遅延</flux:badge>
            </div>

            <div class="space-y-4">
                @if(count($this->overdueOrders()) > 0)
                    <div class="border-b pb-2">
                        <div class="text-sm font-bold text-red-600 mb-2">納期遅延</div>
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>納期</flux:table.column>
                                <flux:table.column>受注番号</flux:table.column>
                                <flux:table.column>品目</flux:table.column>
                                <flux:table.column>数量</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($this->overdueOrders() as $order)
                                    <flux:table.row class="bg-red-50">
                                        <flux:table.cell class="text-red-600 font-bold">{{ $order->due_date->format(config('monox.datetime.formats.date', 'Y-m-d')) }}</flux:table.cell>
                                        <flux:table.cell>{{ $order->order_number }}</flux:table.cell>
                                        <flux:table.cell>{{ $order->item->name }}</flux:table.cell>
                                        <flux:table.cell>{{ $order->quantity }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                <div>
                    <div class="text-sm font-bold text-zinc-600 mb-2">納期間近 (7日以内)</div>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>納期</flux:table.column>
                            <flux:table.column>受注番号</flux:table.column>
                            <flux:table.column>品目</flux:table.column>
                            <flux:table.column>数量</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($this->nearDueOrders() as $order)
                                <flux:table.row>
                                    <flux:table.cell>{{ $order->due_date->format(config('monox.datetime.formats.date', 'Y-m-d')) }}</flux:table.cell>
                                    <flux:table.cell>{{ $order->order_number }}</flux:table.cell>
                                    <flux:table.cell>{{ $order->item->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $order->quantity }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </flux:card>

        <!-- 2. 在庫不足一覧 (BOM含む) -->
        <flux:card>
            <flux:heading size="lg" class="mb-4">在庫不足・欠品リスク一覧</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>品目</flux:table.column>
                    <flux:table.column>必要数</flux:table.column>
                    <flux:table.column>現在庫</flux:table.column>
                    <flux:table.column>アラート数</flux:table.column>
                    <flux:table.column>不足 / 警告</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($this->stockShortages() as $item)
                        @php
                            $isShortage = $item['required'] > $item['stock'];
                            $isAlert = $item['stock'] < $item['alert_quantity'];
                        @endphp
                        @if($isShortage || $isAlert)
                            <flux:table.row>
                                <flux:table.cell>
                                    <div class="flex items-center">
                                        @if($item['level'] > 0)
                                            <span class="text-zinc-400 mr-2">
                                                @for($i=0; $i<$item['level']; $i++) ↳ @endfor
                                            </span>
                                        @endif
                                        <div>
                                            <div class="font-medium">{{ $item['name'] }}</div>
                                            <div class="text-xs text-zinc-500">{{ $item['code'] }}</div>
                                            @if($item['parent'])
                                                <div class="text-[10px] text-zinc-400">for: {{ $item['parent'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>{{ number_format($item['required'], 2) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item['stock'], 2) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item['alert_quantity'], 2) }}</flux:table.cell>
                                <flux:table.cell>
                                    @if($isShortage)
                                        <flux:badge color="orange" size="sm" title="受注に対する不足数">不足: {{ number_format($item['required'] - $item['stock'], 2) }}</flux:badge>
                                    @endif
                                    @if($isAlert)
                                        <flux:badge color="red" size="sm" title="在庫アラート数を下回っています">警告: 在庫少</flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endif
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <!-- 3. 直近の製造実績 -->
        <flux:card class="lg:col-span-2">
            <div class="flex flex-col md:flex-row md:items-center justify-between mb-4 gap-4">
                <flux:heading size="lg">直近の製造実績</flux:heading>
                <div class="flex gap-2">
                    <flux:select wire:model.live="selectedItemId" placeholder="品目で絞り込み" class="w-48">
                        <flux:select.option value="">すべての品目</flux:select.option>
                        @foreach($this->items as $item)
                            <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="selectedProcessId" placeholder="工程で絞り込み" class="w-48">
                        <flux:select.option value="">すべての工程</flux:select.option>
                        @foreach($this->processes as $process)
                            <flux:select.option value="{{ $process->id }}">{{ $process->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>完了日時</flux:table.column>
                    <flux:table.column>品目 / 工程</flux:table.column>
                    <flux:table.column>良品数</flux:table.column>
                    <flux:table.column>不良数</flux:table.column>
                    <flux:table.column>良品率</flux:table.column>
                    <flux:table.column>作業時間</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($this->recentProductionResults() as $record)
                        @php
                            $total = $record->good_quantity + $record->defective_quantity;
                            $yield = $total > 0 ? ($record->good_quantity / $total) * 100 : 0;

                            // 作業時間（分）の計算：(終了 - 開始) - 中断時間
                            $durationSeconds = $record->work_started_at && $record->work_finished_at
                                ? $record->work_started_at->diffInSeconds($record->work_finished_at, false)
                                : 0;
                            $actualSeconds = max(0, $durationSeconds - ($record->total_paused_seconds ?? 0));
                            $duration = round($actualSeconds / 60, 1);
                        @endphp
                        <flux:table.row>
                            <flux:table.cell>{{ $record->work_finished_at->format(config('monox.datetime.formats.short_datetime', 'm/d H:i')) }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium">{{ $record->productionOrder->item->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $record->process->name }}</div>
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($record->good_quantity) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($record->defective_quantity) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $yield >= 95 ? 'green' : ($yield >= 80 ? 'orange' : 'red') }}">
                                    {{ number_format($yield, 1) }}%
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $duration }} 分</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <!-- 4. 工程ごと入力値のトレンドチャート -->
        <flux:card class="lg:col-span-2">
            <div class="flex flex-col mb-4 gap-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <flux:heading size="lg">工程入力値トレンド</flux:heading>
                        <div id="chartStats" class="flex flex-wrap gap-2 text-sm">
                            <span title="平均値" class="bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                Avg: <span id="statAvg">-</span>
                            </span>
                            <span title="工程能力指数" class="bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                Cp: <span id="statCp">-</span>
                            </span>
                            <span title="偏り考慮の工程能力指数" class="bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                Cpk: <span id="statCpk">-</span>
                            </span>
                            <span title="標準偏差" class="bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                σ: <span id="statStdDev">-</span>
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:radio.group wire:model.live="calcMode" variant="segmented" size="sm" class="flex items-center">
                            <flux:radio value="avg" label="平均" />
                            <flux:radio value="sum" label="合計" />
                        </flux:radio.group>
                        <div class="flex items-center gap-2 ml-2">
                            <flux:switch wire:model.live="showSpcLimits" size="sm" label="SPC" />
                        </div>
                        <flux:select wire:model.live="chartLimit" size="sm" class="w-24">
                            <flux:select.option value="50">50件</flux:select.option>
                            <flux:select.option value="100">100件</flux:select.option>
                        </flux:select>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:select wire:model.live="chartItemId" placeholder="品目" class="w-full md:w-40">
                        <flux:select.option value="">品目を選択</flux:select.option>
                        @foreach($this->items as $item)
                            <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="chartProcessId" placeholder="工程" class="w-full md:w-40">
                        <flux:select.option value="">工程を選択</flux:select.option>
                        @foreach($this->chartProcesses as $process)
                            <flux:select.option value="{{ $process->id }}">{{ $process->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="chartFieldIds" multiple placeholder="入力項目" class="flex-1 min-w-[200px]">
                        @foreach($this->chartFields as $field)
                            <flux:select.option value="{{ $field->id }}">{{ $field->label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div wire:ignore class="h-64">
                <canvas id="trendChart"></canvas>
            </div>
        </flux:card>
    </div>

    @assets
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
    @endassets

   <script>
       let trendChart = null;

       $wire.$on('chart-data-updated', (event) => {
           const chartData = event.data;
           const ctx = document.getElementById('trendChart').getContext('2d');

           // 統計情報の更新
           document.getElementById('statAvg').textContent = chartData.stats?.avg ?? '-';
           document.getElementById('statCp').textContent = chartData.stats?.cp ?? '-';
           document.getElementById('statCpk').textContent = chartData.stats?.cpk ?? '-';
           document.getElementById('statStdDev').textContent = chartData.stats?.stdDev ?? '-';

           if (trendChart) {
               trendChart.destroy();
           }

           trendChart = new Chart(ctx, {
               type: 'line',
               data: {
                   labels: chartData.labels,
                   datasets: chartData.datasets
               },
               options: {
                   responsive: true,
                   maintainAspectRatio: false,
                   plugins: {
                       annotation: {
                           annotations: {
                               ...(chartData.thresholds.max ? {
                                   maxLine: {
                                       type: 'line',
                                       yMin: chartData.thresholds.max,
                                       yMax: chartData.thresholds.max,
                                       borderColor: 'rgb(239, 68, 68)',
                                       borderWidth: 2,
                                       borderDash: [5, 5],
                                       label: {
                                           display: true,
                                           content: '上限: ' + chartData.thresholds.max,
                                           position: 'end'
                                       }
                                   }
                               } : {}),
                               ...(chartData.thresholds.min ? {
                                   minLine: {
                                       type: 'line',
                                       yMin: chartData.thresholds.min,
                                       yMax: chartData.thresholds.min,
                                       borderColor: 'rgb(239, 68, 68)',
                                       borderWidth: 2,
                                       borderDash: [5, 5],
                                       label: {
                                           display: true,
                                           content: '下限: ' + chartData.thresholds.min,
                                           position: 'end'
                                       }
                                   }
                               } : {}),
                               ...(chartData.thresholds.ucl ? {
                                   uclLine: {
                                       type: 'line',
                                       yMin: chartData.thresholds.ucl,
                                       yMax: chartData.thresholds.ucl,
                                       borderColor: 'rgb(168, 85, 247)',
                                       borderWidth: 1.5,
                                       borderDash: [2, 2],
                                       label: {
                                           display: true,
                                           content: 'UCL: ' + chartData.thresholds.ucl,
                                           position: 'start',
                                           backgroundColor: 'rgba(168, 85, 247, 0.8)'
                                       }
                                   }
                               } : {}),
                               ...(chartData.thresholds.lcl ? {
                                   lclLine: {
                                       type: 'line',
                                       yMin: chartData.thresholds.lcl,
                                       yMax: chartData.thresholds.lcl,
                                       borderColor: 'rgb(168, 85, 247)',
                                       borderWidth: 1.5,
                                       borderDash: [2, 2],
                                       label: {
                                           display: true,
                                           content: 'LCL: ' + chartData.thresholds.lcl,
                                           position: 'start',
                                           backgroundColor: 'rgba(168, 85, 247, 0.8)'
                                       }
                                   }
                               } : {}),
                               ...(chartData.thresholds.target ? {
                                   targetLine: {
                                       type: 'line',
                                       yMin: chartData.thresholds.target,
                                       yMax: chartData.thresholds.target,
                                       borderColor: 'rgb(34, 197, 94)',
                                       borderWidth: 1,
                                       label: {
                                           display: true,
                                           content: '目標: ' + chartData.thresholds.target,
                                           position: 'start'
                                       }
                                   }
                               } : {})
                           }
                       }
                   }
               }
           });
       })
   </script>
</div>
