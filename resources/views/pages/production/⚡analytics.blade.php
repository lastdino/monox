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

    public ?int $selectedRecordId = null;

    public ?int $selectedFieldId = null;

    public ?int $bins = null;

    public ?float $minRange = null;

    public ?float $maxRange = null;

    public array $distributionData = [];

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

    public function showDistribution(int $recordId, int $fieldId): void
    {
        $this->selectedRecordId = $recordId;
        $this->selectedFieldId = $fieldId;

        $this->distributionData = \Lastdino\Monox\Services\DistributionService::buildDistributionData($recordId, $fieldId);

        $this->bins = $this->distributionData['range']['bins'];
        $this->minRange = $this->distributionData['range']['min'];
        $this->maxRange = $this->distributionData['range']['max'];

        $this->dispatch('distribution-data-updated', data: $this->distributionData);
        Flux::modal('distribution-modal')->show();
    }

    public function refreshDistribution(): void
    {
        if (!$this->selectedRecordId || !$this->selectedFieldId) {
            return;
        }

        $this->distributionData = \Lastdino\Monox\Services\DistributionService::buildDistributionData(
            $this->selectedRecordId,
            $this->selectedFieldId,
            $this->bins,
            $this->minRange,
            $this->maxRange
        );

        $this->dispatch('distribution-data-updated', data: $this->distributionData);
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

    <flux:modal name="distribution-modal" class="md:w-[800px]">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">ヒストグラム: {{ $distributionData['field']['label'] ?? '' }}</flux:heading>
                <div class="flex gap-2 text-sm">
                    <span class="bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded">N: {{ $distributionData['stats']['count'] ?? 0 }}</span>
                    <span class="bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded">Avg: {{ $distributionData['stats']['avg'] ?? '-' }}</span>
                    <span class="bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded">σ: {{ $distributionData['stats']['stdDev'] ?? '-' }}</span>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                <div class="p-2 border rounded">
                    <div class="text-zinc-500">Min / Max (データ)</div>
                    <div class="font-bold">{{ $distributionData['stats']['min'] ?? '-' }} / {{ $distributionData['stats']['max'] ?? '-' }}</div>
                </div>
                <div class="p-2 border rounded">
                    <div class="text-zinc-500">規格 (Min/Max)</div>
                    <div class="font-bold">{{ $distributionData['field']['min'] ?? '-' }} / {{ $distributionData['field']['max'] ?? '-' }}</div>
                </div>
                <div class="p-2 border rounded">
                    <div class="text-zinc-500">Cp</div>
                    <div class="font-bold {{ ($distributionData['stats']['cp'] ?? 0) < 1.33 ? 'text-orange-500' : 'text-green-500' }}">
                        {{ $distributionData['stats']['cp'] ?? '-' }}
                    </div>
                </div>
                <div class="p-2 border rounded">
                    <div class="text-zinc-500">Cpk</div>
                    <div class="font-bold {{ ($distributionData['stats']['cpk'] ?? 0) < 1.33 ? 'text-orange-500' : 'text-green-500' }}">
                        {{ $distributionData['stats']['cpk'] ?? '-' }}
                    </div>
                </div>
            </div>

            <div class="bg-zinc-50 dark:bg-zinc-900 p-3 rounded-lg border border-zinc-200 dark:border-zinc-800">
                <div class="grid grid-cols-3 gap-4">
                    <flux:input wire:model.lazy="bins" wire:change="refreshDistribution" type="number" label="階級数" size="sm" />
                    <flux:input wire:model.lazy="minRange" wire:change="refreshDistribution" type="number" step="any" label="最小値" size="sm" />
                    <flux:input wire:model.lazy="maxRange" wire:change="refreshDistribution" type="number" step="any" label="最大値" size="sm" />
                </div>
            </div>

            <div wire:ignore class="h-80">
                <canvas id="distributionChart"></canvas>
            </div>
        </div>
    </flux:modal>

    @assets
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
    @endassets

   <script>
       let trendChart = null;
       let distributionChart = null;

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
                   onClick: (e, elements) => {
                       if (elements.length > 0) {
                           const index = elements[0].index;
                           const recordId = chartData.record_ids[index];
                           const count = chartData.counts[index];

                           // 複数項目選択時は最初の項目を対象とする（とりあえずの仕様）
                           const fieldId = $wire.chartFieldIds[0];

                           if (count > 1) {
                               $wire.showDistribution(recordId, fieldId);
                           } else {
                               Flux.toast('全数データがありません（単発入力です）');
                           }
                       }
                   },
                   plugins: {
                       tooltip: {
                           callbacks: {
                               afterBody: (context) => {
                                   const index = context[0].dataIndex;
                                   const count = chartData.counts[index];
                                   return count > 1 ? `全数データ: ${count}件 (クリックで詳細)` : '';
                               }
                           }
                       },
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

       $wire.$on('distribution-data-updated', (event) => {
           const distData = event.data;
           const ctx = document.getElementById('distributionChart').getContext('2d');

           // X軸（カテゴリ軸）における測定値のインデックスを計算する関数
           const getXIndex = (value) => {
               if (value === null || value === undefined) return null;
               const min = distData.range.min;
               const max = distData.range.max;
               const bins = distData.range.bins;

               if (max === min) return 0;

               // 階級の幅
               const binWidth = (max - min) / bins;
               // 測定値が最小値からどれくらい離れているかを階級幅で割る
               // 0番目のビンの中心がインデックス0に相当するので、中心からのオフセットを考慮
               // 各ビンの中心は min + (i + 0.5) * binWidth
               // したがって、値 v に対応するインデックス i は: v = min + (i + 0.5) * binWidth
               // i = (v - min) / binWidth - 0.5
               return ((value - min) / binWidth) - 0.5;
           };

           if (distributionChart) {
               distributionChart.destroy();
           }

           const maxIdx = getXIndex(distData.field.max);
           const minIdx = getXIndex(distData.field.min);
           const targetIdx = getXIndex(distData.field.target);
           const uclIdx = getXIndex(distData.stats.ucl);
           const lclIdx = getXIndex(distData.stats.lcl);

           distributionChart = new Chart(ctx, {
               type: 'bar',
               data: {
                   labels: distData.labels,
                   datasets: distData.datasets
               },
               options: {
                   responsive: true,
                   maintainAspectRatio: false,
                   plugins: {
                       legend: { display: false },
                       annotation: {
                           annotations: {
                               ...(maxIdx !== null ? {
                                   maxLine: {
                                       type: 'line',
                                       xMin: maxIdx,
                                       xMax: maxIdx,
                                       borderColor: 'rgb(239, 68, 68)',
                                       borderWidth: 2,
                                       borderDash: [5, 5],
                                       label: {
                                           display: true,
                                           content: '上限: ' + distData.field.max,
                                           position: 'end'
                                       }
                                   }
                               } : {}),
                               ...(minIdx !== null ? {
                                   minLine: {
                                       type: 'line',
                                       xMin: minIdx,
                                       xMax: minIdx,
                                       borderColor: 'rgb(239, 68, 68)',
                                       borderWidth: 2,
                                       borderDash: [5, 5],
                                       label: {
                                           display: true,
                                           content: '下限: ' + distData.field.min,
                                           position: 'end'
                                       }
                                   }
                               } : {}),
                               ...(targetIdx !== null ? {
                                   targetLine: {
                                       type: 'line',
                                       xMin: targetIdx,
                                       xMax: targetIdx,
                                       borderColor: 'rgb(34, 197, 94)',
                                       borderWidth: 1,
                                       label: {
                                           display: true,
                                           content: '目標: ' + distData.field.target,
                                           position: 'start'
                                       }
                                   }
                               } : {}),
                               ...(uclIdx !== null ? {
                                   uclLine: {
                                       type: 'line',
                                       xMin: uclIdx,
                                       xMax: uclIdx,
                                       borderColor: 'rgb(168, 85, 247)',
                                       borderWidth: 1.5,
                                       borderDash: [2, 2],
                                       label: {
                                           display: true,
                                           content: 'UCL: ' + distData.stats.ucl,
                                           position: 'start',
                                           backgroundColor: 'rgba(168, 85, 247, 0.8)'
                                       }
                                   }
                               } : {}),
                               ...(lclIdx !== null ? {
                                   lclLine: {
                                       type: 'line',
                                       xMin: lclIdx,
                                       xMax: lclIdx,
                                       borderColor: 'rgb(168, 85, 247)',
                                       borderWidth: 1.5,
                                       borderDash: [2, 2],
                                       label: {
                                           display: true,
                                           content: 'LCL: ' + distData.stats.lcl,
                                           position: 'start',
                                           backgroundColor: 'rgba(168, 85, 247, 0.8)'
                                       }
                                   }
                               } : {})
                           }
                       }
                   },
                   scales: {
                       x: {
                           title: { display: true, text: '測定値' }
                       },
                       y: {
                           beginAtZero: true,
                           title: { display: true, text: '頻度' }
                       }
                   }
               }
           });
       });
   </script>
</div>
