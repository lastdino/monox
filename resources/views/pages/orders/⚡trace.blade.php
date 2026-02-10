<?php

use Flux\Flux;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\SalesOrder;
use Livewire\Component;

new class extends Component
{
    public int $departmentId;

    public string $search = '';

    public string $searchType = 'order'; // 'order' or 'lot'

    public ?SalesOrder $order = null;

    public \Illuminate\Support\Collection $orders;

    public ?\Lastdino\Monox\Models\Lot $lot = null;

    public \Illuminate\Support\Collection $usedInLots;

    public function mount(Department $department): void
    {
        $this->departmentId = $department->id;
        $this->orders = collect();
        $this->usedInLots = collect();

        if (request()->query('order_number')) {
            $this->search = request()->query('order_number');
            $this->searchType = 'order';
            $this->trace();
        } elseif (request()->query('lot_number')) {
            $this->search = request()->query('lot_number');
            $this->searchType = 'lot';
            $this->trace();
        }
    }

    public function trace(): void
    {
        if (empty($this->search)) {
            $this->order = null;
            $this->orders = collect();
            $this->lot = null;

            return;
        }

        $this->order = null;
        $this->orders = collect();
        $this->lot = null;
        $this->usedInLots = collect();

        if ($this->searchType === 'order') {
            // 受注番号で検索
            $this->order = SalesOrder::where('department_id', $this->departmentId)
                ->where('order_number', $this->search)
                ->with([
                    'partner',
                    'item',
                    'shipments.lot.productionOrder.productionRecords.annotationValues.lot.item',
                    'shipments.lot.productionOrder.productionRecords.worker',
                    'shipments.lot.productionOrder.productionRecords.process',
                ])
                ->first();

            if (! $this->order) {
                Flux::toast(variant: 'danger', text: '対象の受注が見つかりませんでした。');
            }
        } else {
            // ロット番号で検索
            $this->lot = \Lastdino\Monox\Models\Lot::where('lot_number', $this->search)
                ->where('department_id', $this->departmentId)
                ->with([
                    'item',
                    'productionOrder.productionRecords.annotationValues.lot.item',
                    'productionOrder.productionRecords.worker',
                    'productionOrder.productionRecords.process',
                ])
                ->first();

            if ($this->lot) {
                // 出荷履歴から関連するすべての受注を特定
                $this->orders = SalesOrder::whereIn('id', function ($query) {
                    $query->select('sales_order_id')
                        ->from('monox_shipments')
                        ->where('lot_id', $this->lot->id);
                })
                    ->with(['partner', 'item'])
                    ->get();

                // このロットが他の製品の「部材」として使用されていないか逆引き
                $this->usedInLots = \Lastdino\Monox\Models\Lot::whereIn('id', function ($query) {
                    $query->select('lot_id')
                        ->from('monox_production_orders')
                        ->whereIn('id', function ($q2) {
                            $q2->select('production_order_id')
                                ->from('monox_production_records')
                                ->whereIn('id', function ($q3) {
                                    $q3->select('production_record_id')
                                        ->from('monox_production_annotation_values')
                                        ->where('lot_id', $this->lot->id);
                                });
                        });
                })
                    ->with(['item', 'stockMovements'])
                    ->get();

                // 部材として使用されていた場合、その先の完成品の出荷先も受注一覧に含める
                if ($this->usedInLots->isNotEmpty()) {
                    $extraOrders = SalesOrder::whereIn('id', function ($query) {
                        $query->select('sales_order_id')
                            ->from('monox_shipments')
                            ->whereIn('lot_id', $this->usedInLots->pluck('id'));
                    })
                        ->with(['partner', 'item'])
                        ->get();

                    $this->orders = $this->orders->concat($extraOrders)->unique('id');
                }
            } else {
                Flux::toast(variant: 'danger', text: '対象のロットが見つかりませんでした。');
            }
        }
    }
}; ?>

<div class="p-6">
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="xl" level="1">トレサビリティ</flux:heading>
        <flux:button href="{{ route('monox.orders.dashboard', ['department' => $departmentId]) }}" variant="ghost" icon="arrow-left">
            ダッシュボードへ戻る
        </flux:button>
    </div>

    <div class="mb-8">
        <div class="mb-4">
            <flux:radio.group wire:model.live="searchType" variant="segmented" size="sm">
                <flux:radio value="order" label="受注番号で検索" />
                <flux:radio value="lot" label="製造ロットで検索" />
            </flux:radio.group>
        </div>

        <div class="flex gap-2 max-w-md">
            <flux:input wire:model="search" wire:keydown.enter="trace" :placeholder="$searchType === 'order' ? '受注番号を入力' : '製造ロット番号を入力'" icon="magnifying-glass" />
            <flux:button wire:click="trace" variant="primary">検索</flux:button>
        </div>
        <flux:description class="mt-2">
            {{ $searchType === 'order' ? '受注番号 (例: SO-001) でトレースできます。' : '製造ロット番号 (例: LOT-20240101) でトレースできます。' }}
        </flux:description>
    </div>

    @if($order)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <flux:card>
                <flux:heading level="2" size="lg" class="mb-4">受注基本情報</flux:heading>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">受注番号</span>
                        <span class="font-medium">{{ $order->order_number }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">得意先</span>
                        <span class="font-medium">{{ $order->partner?->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">品目</span>
                        <span class="font-medium">{{ $order->item?->name }} ({{ $order->item?->code }})</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">受注日</span>
                        <span>{{ $order->order_date?->format('Y/m/d') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">納期</span>
                        <span class="font-bold text-blue-600">{{ $order->due_date?->format('Y/m/d') }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-2">
                        <span class="text-zinc-500">数量</span>
                        <span class="font-bold text-lg">{{ number_format($order->quantity) }} {{ $order->item?->unit }}</span>
                    </div>
                </div>
            </flux:card>

            <flux:card class="md:col-span-2">
                <flux:heading level="2" size="lg" class="mb-4">出荷・ロット履歴</flux:heading>
                @if($order->shipments->isEmpty())
                    <div class="text-zinc-400 py-4 text-center">出荷実績はありません。</div>
                @else
                    @php
                        $totalShipped = $order->shipments->sum('quantity');
                        $progress = $order->quantity > 0 ? min(100, ($totalShipped / $order->quantity) * 100) : 0;
                    @endphp
                    <div class="mb-4 p-3 bg-zinc-50 rounded-lg border border-zinc-200">
                        <div class="flex justify-between items-center mb-1">
                            <span class="text-sm font-medium text-zinc-700">出荷進捗</span>
                            <span class="text-sm font-bold text-zinc-900">{{ number_format($totalShipped) }} / {{ number_format($order->quantity) }} ({{ number_format($progress, 1) }}%)</span>
                        </div>
                        <div class="w-full bg-zinc-200 rounded-full h-2">
                            <div class="bg-blue-600 h-2 rounded-full" style="width: {{ $progress }}%"></div>
                        </div>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>出荷番号</flux:table.column>
                            <flux:table.column>出荷日</flux:table.column>
                            <flux:table.column>ロット番号</flux:table.column>
                            <flux:table.column align="end">数量</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($order->shipments as $shipment)
                                <flux:table.row>
                                    <flux:table.cell>{{ $shipment->shipment_number }}</flux:table.cell>
                                    <flux:table.cell>{{ $shipment->shipping_date?->format('Y/m/d') }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if($shipment->lot)
                                            <flux:badge variant="neutral">{{ $shipment->lot->lot_number }}</flux:badge>
                                        @else
                                            <span class="text-zinc-400">-</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end">{{ number_format($shipment->quantity) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        </div>

        <flux:heading level="2" size="lg" class="mb-4">製造プロセス追跡</flux:heading>
        <div class="space-y-6">
            @php
                $uniqueLots = $order->shipments->map(fn($s) => $s->lot)->filter()->unique('id');
            @endphp

            @foreach($uniqueLots as $lot)
                @if($lot->productionOrder)
                    @php $prodOrder = $lot->productionOrder; @endphp
                    <flux:card>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <flux:heading level="3" size="md">製品ロット: {{ $lot->lot_number }}</flux:heading>
                                <div class="text-sm text-zinc-500">
                                    製造指示: #{{ $prodOrder->id }} | ステータス: {{ $prodOrder->status }}
                                    <span class="ml-2">
                                        (出荷数: {{ number_format($order->shipments->where('lot_id', $lot->id)->sum('quantity')) }})
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto mt-4">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>工程</flux:table.column>
                                    <flux:table.column>実績時間</flux:table.column>
                                    <flux:table.column>担当者</flux:table.column>
                                    <flux:table.column>実績数 (良品/不良)</flux:table.column>
                                    <flux:table.column>使用部材ロット / 記録内容</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach($prodOrder->productionRecords as $record)
                                        <flux:table.row>
                                            <flux:table.cell class="font-medium">
                                                {{ $record->process?->name }}
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                <div class="text-xs">
                                                    @if($record->work_started_at)
                                                        開始: {{ $record->work_started_at->format('m/d H:i') }}<br>
                                                    @endif
                                                    @if($record->work_finished_at)
                                                        終了: {{ $record->work_finished_at->format('m/d H:i') }}
                                                        <div class="mt-1 font-bold text-zinc-700">
                                                            @php
                                                                $durationSeconds = $record->work_started_at->diffInSeconds($record->work_finished_at, false);
                                                                $actualSeconds = max(0, $durationSeconds - ($record->total_paused_seconds ?? 0));
                                                                $durationMinutes = round($actualSeconds / 60, 1);
                                                            @endphp
                                                            {{ $durationMinutes }} 分
                                                        </div>
                                                    @endif
                                                </div>
                                            </flux:table.cell>
                                            <flux:table.cell>{{ $record->worker?->name ?? '-' }}</flux:table.cell>
                                            <flux:table.cell>
                                                <span class="text-green-600 font-bold">{{ number_format($record->good_quantity) }}</span>
                                                /
                                                <span class="text-red-600">{{ number_format($record->defective_quantity) }}</span>
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                @php
                                                    $hasTemplate = false;
                                                    if ($record->process) {
                                                        if ($record->process->template_media) {
                                                            $hasTemplate = true;
                                                        } elseif ($record->process->share_template_with_previous) {
                                                            // 遡ってテンプレートを探す
                                                            $curr = $record->process;
                                                            while ($curr && $curr->share_template_with_previous) {
                                                                $prev = $prodOrder->item->processes
                                                                    ->where('sort_order', '<', $curr->sort_order)
                                                                    ->last();
                                                                if ($prev && $prev->template_media) {
                                                                    $hasTemplate = true;
                                                                    break;
                                                                }
                                                                $curr = $prev;
                                                            }
                                                        }
                                                    }
                                                @endphp

                                                @forelse($record->annotationValues as $val)
                                                    <div class="mb-1 last:mb-0">
                                                        <span class="text-xs text-zinc-500">{{ $val->field?->label }}:</span>
                                                        @if($val->lot)
                                                            <div class="inline-flex flex-col">
                                                                <div class="flex items-center gap-1">
                                                                    <flux:badge size="sm" variant="outline">{{ $val->lot->item?->name }}: {{ $val->lot->lot_number }}</flux:badge>
                                                                    <flux:button href="{{ route('monox.orders.trace', ['department' => $departmentId, 'order_number' => $val->lot->lot_number]) }}" size="xs" variant="ghost" icon="magnifying-glass" square tooltip="このLotをトレース" />
                                                                </div>
                                                                @if($val->quantity)
                                                                    <span class="text-[10px] text-zinc-500 ml-1">使用量: {{ number_format($val->quantity) }} {{ $val->lot->item?->unit }}</span>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <span class="text-sm">{{ $val->value }}</span>
                                                        @endif
                                                    </div>
                                                @empty
                                                    <span class="text-zinc-400 text-xs">記録なし</span>
                                                @endforelse

                                                @if($hasTemplate)
                                                    <div class="mt-2 pt-2 border-t border-zinc-100">
                                                        <flux:link href="{{ route('monox.production.worksheet', ['department' => $departmentId, 'order' => $prodOrder->id, 'process' => $record->process_id]) }}" icon="image" size="sm">
                                                            図面で確認
                                                        </flux:link>
                                                    </div>
                                                @endif
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </flux:card>
                @endif
            @endforeach

            @php
                $hasProductionData = $order->shipments->map(fn($s) => $s->lot)->filter()->contains(fn($l) => $l->productionOrder()->exists());
            @endphp
            @if(!$hasProductionData)
                <flux:card>
                    <div class="text-zinc-400 py-8 text-center">
                        製品ロットに紐づく製造実績データが見つかりませんでした。
                    </div>
                </flux:card>
            @endif
        </div>
    @elseif($lot)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <flux:card>
                <flux:heading level="2" size="lg" class="mb-4">ロット基本情報</flux:heading>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">ロット番号</span>
                        <span class="font-bold text-lg text-blue-600">{{ $lot->lot_number }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">品目</span>
                        <span class="font-medium">{{ $lot->item?->name }} ({{ $lot->item?->code }})</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">有効期限</span>
                        <span>{{ $lot->expired_at?->format('Y/m/d') ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-2">
                        <span class="text-zinc-500">現在庫</span>
                        <span class="font-bold text-lg">{{ number_format($lot->current_stock) }} {{ $lot->item?->unit }}</span>
                    </div>
                </div>
            </flux:card>

            <flux:card class="md:col-span-2">
                <flux:heading level="2" size="lg" class="mb-4">使用された受注一覧</flux:heading>
                @if($orders->isEmpty())
                    <div class="text-zinc-400 py-4 text-center">このロットを使用した出荷実績はありません。</div>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>受注番号</flux:table.column>
                            <flux:table.column>得意先</flux:table.column>
                            <flux:table.column>品目</flux:table.column>
                            <flux:table.column>納期</flux:table.column>
                            <flux:table.column align="end">受注数量</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($orders as $o)
                                <flux:table.row>
                                    <flux:table.cell class="font-mono">#{{ $o->order_number }}</flux:table.cell>
                                    <flux:table.cell>{{ $o->partner?->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $o->item?->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $o->due_date?->format('Y/m/d') }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ number_format($o->quantity) }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:button href="{{ route('monox.orders.trace', ['department' => $departmentId, 'order_number' => $o->order_number]) }}" size="xs" variant="ghost" icon="magnifying-glass" square tooltip="受注詳細をトレース" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        </div>

        <flux:heading level="2" size="lg" class="mb-4">製造プロセス実績</flux:heading>
        @if($lot->productionOrder)
            <flux:card class="mb-8">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <flux:heading level="3" size="md">製造指示: #{{ $lot->productionOrder->id }}</flux:heading>
                        <div class="text-sm text-zinc-500">
                            ステータス: {{ $lot->productionOrder->status }}
                            | 指示数: {{ number_format($lot->productionOrder->target_quantity) }} {{ $lot->item?->unit }}
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>工程</flux:table.column>
                            <flux:table.column>実績時間</flux:table.column>
                            <flux:table.column>担当者</flux:table.column>
                            <flux:table.column>実績数 (良品/不良)</flux:table.column>
                            <flux:table.column>使用部材ロット / 記録内容</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($lot->productionOrder->productionRecords as $record)
                                <flux:table.row>
                                    <flux:table.cell class="font-medium">
                                        {{ $record->process?->name }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="text-xs">
                                            @if($record->work_started_at)
                                                開始: {{ $record->work_started_at->format('m/d H:i') }}<br>
                                            @endif
                                            @if($record->work_finished_at)
                                                終了: {{ $record->work_finished_at->format('m/d H:i') }}
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $record->worker?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        <span class="text-green-600 font-bold">{{ number_format($record->good_quantity) }}</span>
                                        /
                                        <span class="text-red-600">{{ number_format($record->defective_quantity) }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @forelse($record->annotationValues as $val)
                                            <div class="mb-1 last:mb-0">
                                                <span class="text-xs text-zinc-500">{{ $val->field?->label }}:</span>
                                                @if($val->lot)
                                                    <div class="inline-flex flex-col">
                                                        <div class="flex items-center gap-1">
                                                            <flux:badge size="sm" variant="outline">{{ $val->lot->item?->name }}: {{ $val->lot->lot_number }}</flux:badge>
                                                            <flux:button href="{{ route('monox.orders.trace', ['department' => $departmentId, 'lot_number' => $val->lot->lot_number]) }}" size="xs" variant="ghost" icon="magnifying-glass" square tooltip="このLotをトレース" />
                                                        </div>
                                                        @if($val->quantity)
                                                            <span class="text-[10px] text-zinc-500 ml-1">使用量: {{ number_format($val->quantity) }} {{ $val->lot->item?->unit }}</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-sm">{{ $val->value }}</span>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="text-zinc-400 text-xs">記録なし</span>
                                        @endforelse
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>
        @else
            <flux:card class="mb-8">
                <div class="text-zinc-400 py-8 text-center">
                    このロットに紐づく製造実績データは見つかりませんでした。
                </div>
            </flux:card>
        @endif

        @if($usedInLots->isNotEmpty())
            <flux:heading level="2" size="lg" class="mb-4">このロットを使用した製品ロット</flux:heading>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($usedInLots as $prodLot)
                    <flux:card>
                        <div class="flex justify-between items-start">
                            <div>
                                <flux:heading level="3" size="md">{{ $prodLot->item?->name }}</flux:heading>
                                <div class="text-sm font-mono text-blue-600">{{ $prodLot->lot_number }}</div>
                            </div>
                            <flux:button href="{{ route('monox.orders.trace', ['department' => $departmentId, 'lot_number' => $prodLot->lot_number]) }}" size="sm" variant="outline" icon="magnifying-glass">
                                トレース
                            </flux:button>
                        </div>
                        <div class="mt-4 text-xs space-y-1">
                            <div class="flex justify-between">
                                <span class="text-zinc-500">現在庫:</span>
                                <span>{{ number_format($prodLot->current_stock) }} {{ $prodLot->item?->unit }}</span>
                            </div>
                            @if($prodLot->productionOrder)
                                <div class="flex justify-between">
                                    <span class="text-zinc-500">製造指示:</span>
                                    <span>#{{ $prodLot->productionOrder->id }} ({{ $prodLot->productionOrder->status }})</span>
                                </div>
                            @endif
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    @elseif($search)
        <div class="text-center py-12">
            <flux:heading level="3" class="text-zinc-400">「{{ $search }}」に該当する受注またはロットは見つかりませんでした。</flux:heading>
        </div>
    @else
        <div class="flex flex-col items-center justify-center py-20 text-zinc-400">
            <flux:icon name="magnifying-glass" size="xl" class="mb-4 opacity-20" />
            <p>受注番号または製造ロット番号を入力して検索を開始してください。</p>
        </div>
    @endif
</div>
