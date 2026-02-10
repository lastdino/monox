<?php

use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\Partner;
use Lastdino\Monox\Models\SalesOrder;
use Lastdino\Monox\Models\Shipment;
use Lastdino\Monox\Models\StockMovement;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public int $departmentId;

    public string $viewMode = 'list'; // list | calendar

    public string $search = '';

    public string $statusFilter = '';

    // Status Update
    public $editingEntryId = null;

    public $editingEntryType = null;

    public $editingStatus = '';

    public array $selectedLots = [];

    public float $shipmentQuantity = 0;

    public ?string $shipmentNumber = null;

    public ?string $shippingDate = null;

    // Create Order Form
    public $partner_id = '';

    public  $item_id = '';

    public ?string $order_number = null;

    public ?string $order_date = null;

    public ?string $due_date = null;

    public float $quantity = 1;

    public string $note = '';

    public function mount($department_id): void
    {
        if ($department_id instanceof \Illuminate\Database\Eloquent\Model) {
            $this->departmentId = $department_id->getKey();
        } else {
            $this->departmentId = (int) $department_id;
        }

        $this->order_date = now()->toDateString();
    }

    public function createOrder(): void
    {
        $this->validate([
            'partner_id' => ['required', 'exists:monox_partners,id'],
            'item_id' => ['required', 'exists:monox_items,id'],
            'order_number' => ['required', 'string', 'max:255'],
            'order_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        SalesOrder::create([
            'department_id' => $this->departmentId,
            'partner_id' => $this->partner_id,
            'item_id' => $this->item_id,
            'order_number' => $this->order_number,
            'order_date' => $this->order_date,
            'due_date' => $this->due_date,
            'quantity' => $this->quantity,
            'status' => 'pending',
            'note' => $this->note,
        ]);

        $this->reset(['partner_id', 'item_id', 'order_number', 'due_date', 'quantity', 'note']);
        $this->order_date = now()->toDateString();

        Flux::modal('create-order')->close();
        Flux::toast('受注を登録しました。');
    }

    public function items()
    {
        return Item::where('department_id', $this->departmentId)
            ->where(function ($q) {
                $q->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->from('monox_stock_movements')
                    ->whereColumn('monox_items.id', 'monox_stock_movements.item_id');
            }, '>', 0)
            ->get();
    }

    public function partners()
    {
        return Partner::where('department_id', $this->departmentId)
            ->where('type', 'customer')
            ->get();
    }

    public function openStatusModal($id, $type, $currentStatus, $quantity): void
    {
        $this->editingEntryId = $id;
        $this->editingEntryType = $type;
        $this->editingStatus = $currentStatus;
        $this->shipmentQuantity = $quantity;
        $this->selectedLots = [['lot_id' => '', 'quantity' => $quantity]];
        $this->shipmentNumber = 'SH-'.now()->format('YmdHis');
        $this->shippingDate = now()->toDateString();

        Flux::modal('status-modal')->show();
    }

    public function addLotRow(): void
    {
        $this->selectedLots[] = ['lot_id' => '', 'quantity' => 0];
    }

    public function removeLotRow($index): void
    {
        unset($this->selectedLots[$index]);
        $this->selectedLots = array_values($this->selectedLots);
    }

    public function updateStatus(): void
    {
        if ($this->editingEntryType === 'order') {
            $order = SalesOrder::findOrFail($this->editingEntryId);

            if ($this->editingStatus === 'shipped') {
                $this->validate([
                    'selectedLots.*.lot_id' => ['required', 'exists:monox_lots,id'],
                    'selectedLots.*.quantity' => ['required', 'numeric', 'min:0.0001'],
                    'shipmentNumber' => ['required', 'string', 'max:255'],
                    'shippingDate' => ['required', 'date'],
                ]);

                // Validate total quantity matches
                $totalQty = collect($this->selectedLots)->sum('quantity');
                if (abs($totalQty - $this->shipmentQuantity) > 0.00001) {
                    $this->addError('selectedLots', '合計数量が受注数量と一致しません。');

                    return;
                }

                // Create shipments for each lot
                foreach ($this->selectedLots as $index => $lotData) {
                    Shipment::create([
                        'department_id' => $this->departmentId,
                        'sales_order_id' => $order->id,
                        'item_id' => $order->item_id,
                        'lot_id' => $lotData['lot_id'],
                        'shipment_number' => $index === 0 ? $this->shipmentNumber : $this->shipmentNumber.'-'.($index + 1),
                        'shipping_date' => $this->shippingDate,
                        'quantity' => $lotData['quantity'],
                        'status' => 'shipped',
                    ]);

                    // 出庫記録を追加（在庫を減らす）
                    StockMovement::create([
                        'department_id' => $this->departmentId,
                        'item_id' => $order->item_id,
                        'lot_id' => $lotData['lot_id'],
                        'quantity' => -abs($lotData['quantity']),
                        'type' => 'shipment',
                        'reason' => 'Shipment: '.($index === 0 ? $this->shipmentNumber : $this->shipmentNumber.'-'.($index + 1)),
                        'moved_at' => $this->shippingDate ? \Illuminate\Support\Carbon::parse($this->shippingDate) : now(),
                    ]);
                }

                // Update order status
                $order->update(['status' => 'shipped']);
            } else {
                $order->update(['status' => $this->editingStatus]);
            }
        } else {
            // It's a shipment
            $shipment = Shipment::findOrFail($this->editingEntryId);

            if ($this->editingStatus === 'shipped' && ! $shipment->lot_id) {
                $this->validate([
                    'selectedLots.0.lot_id' => ['required', 'exists:monox_lots,id'],
                ]);
                $shipment->update(['lot_id' => $this->selectedLots[0]['lot_id']]);

                // 出荷レコードを「出荷済み」にする際に在庫を減らす（元々ロットがなかった場合など）
                StockMovement::create([
                    'department_id' => $this->departmentId,
                    'item_id' => $shipment->item_id,
                    'lot_id' => $this->selectedLots[0]['lot_id'],
                    'quantity' => -abs($shipment->quantity),
                    'type' => 'shipment',
                    'reason' => 'Shipment Update: '.$shipment->shipment_number,
                    'moved_at' => $shipment->shipping_date ?? now(),
                ]);
            } elseif ($this->editingStatus === 'shipped' && $shipment->status !== 'shipped') {
                // すでにロットがある出荷レコードを「出荷済み」に変更する場合
                StockMovement::create([
                    'department_id' => $this->departmentId,
                    'item_id' => $shipment->item_id,
                    'lot_id' => $shipment->lot_id,
                    'quantity' => -abs($shipment->quantity),
                    'type' => 'shipment',
                    'reason' => 'Shipment Status Update: '.$shipment->shipment_number,
                    'moved_at' => $shipment->shipping_date ?? now(),
                ]);
            }

            $shipment->update(['status' => $this->editingStatus]);

            // If shipment is completed, update linked order status if all shipped?
            // For now, let's keep it simple.
            if ($this->editingStatus === 'shipped' && $shipment->sales_order_id) {
                $shipment->salesOrder->update(['status' => 'shipped']);
            }
        }

        Flux::modal('status-modal')->close();
        Flux::toast('ステータスを更新しました。');
    }

    public function availableLots()
    {
        if (! $this->editingEntryId) {
            return collect();
        }

        $itemId = null;
        if ($this->editingEntryType === 'order') {
            $itemId = SalesOrder::find($this->editingEntryId)?->item_id;
        } else {
            $itemId = Shipment::find($this->editingEntryId)?->item_id;
        }

        if (! $itemId) {
            return collect();
        }

        return Lot::where('item_id', $itemId)
            ->where('department_id', $this->departmentId)
            ->where(function ($q) {
                $q->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->from('monox_stock_movements')
                    ->whereColumn('monox_lots.id', 'monox_stock_movements.lot_id');
            }, '>', 0)
            ->get();
    }

    public function unifiedEntries()
    {
        return SalesOrder::query()
            ->where('department_id', $this->departmentId)
            ->with(['item', 'partner'])
            ->when($this->search, function ($q) {
                $q->where('order_number', 'like', '%'.$this->search.'%')
                    ->orWhereHas('partner', fn ($qp) => $qp->where('name', 'like', '%'.$this->search.'%'))
                    ->orWhereHas('item', fn ($qi) => $qi->where('name', 'like', '%'.$this->search.'%'));
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy('due_date', 'desc')
            ->paginate(15);
    }

    public function calendarEvents(): array
    {
        return SalesOrder::query()
            ->where('department_id', $this->departmentId)
            ->select(['id', 'order_number', 'due_date', 'quantity'])
            ->whereNotNull('due_date')
            ->get()
            ->map(function ($o) {
                return [
                    'id' => 'order-'.$o->id,
                    'title' => '受注 #'.$o->order_number.' ('.$o->quantity.')',
                    'start' => $o->due_date?->format('Y-m-d'),
                    'color' => '#2563eb',
                ];
            })
            ->all();
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">受注・出荷管理ダッシュボード</flux:heading>
            <x-monox::nav-menu :department="$departmentId" />
        </div>

        <div class="flex items-center gap-2">
            <flux:button href="{{ route('monox.orders.trace', ['department' => $departmentId]) }}" variant="outline" icon="magnifying-glass">
                受注トレース
            </flux:button>

            <flux:modal.trigger name="create-order">
                <flux:button variant="primary" icon="plus">受注登録</flux:button>
            </flux:modal.trigger>

            <flux:radio.group wire:model.live="viewMode" variant="segmented" size="sm">
                <flux:radio value="list" label="一覧" />
                <flux:radio value="calendar" label="カレンダー" />
            </flux:radio.group>
        </div>
    </div>

    <div class="mb-4 flex flex-col md:flex-row gap-4">
        <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="受注番号・品目・得意先・ロットで検索..." class="flex-1" />

        <flux:radio.group wire:model.live="statusFilter" variant="segmented" size="sm">
            <flux:radio value="" label="すべて" />
            <flux:radio value="pending" label="未処理" />
            <flux:radio value="processing" label="手配中" />
            <flux:radio value="shipped" label="出荷済み" />
            <flux:radio value="cancelled" label="キャンセル" />
        </flux:radio.group>
    </div>

    @if($viewMode === 'list')
        <flux:table :paginate="$this->unifiedEntries()">
            <flux:table.columns>
                <flux:table.column>番号</flux:table.column>
                <flux:table.column>得意先</flux:table.column>
                <flux:table.column>品目</flux:table.column>
                <flux:table.column>日付</flux:table.column>
                <flux:table.column>数量</flux:table.column>
                <flux:table.column>ステータス</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach($this->unifiedEntries() as $order)
                    <flux:table.row :key="$order->id">
                        <flux:table.cell>
                            <div class="font-mono">
                                <flux:link href="{{ route('monox.orders.trace', ['department' => $departmentId, 'order_number' => $order->order_number]) }}" class="flex items-center gap-1">
                                    #{{ $order->order_number }}
                                    <flux:icon name="magnifying-glass" size="xs" class="text-zinc-400" />
                                </flux:link>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $order->partner?->name ?? '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @if($order->item)
                                <div class="flex items-center gap-2">
                                    <div>
                                        <div class="font-medium">{{ $order->item->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $order->item->code }}</div>
                                    </div>
                                    @if($order->item->current_stock < $order->item->inventory_alert_quantity)
                                        <flux:badge color="red" size="sm" title="在庫アラート数を下回っています">警告: 在庫少</flux:badge>
                                    @endif
                                </div>
                            @else
                                -
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $order->due_date ? $order->due_date->format(config('monox.datetime.formats.date', 'Y-m-d')) : '-' }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($order->quantity, 2) }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="cursor-pointer" wire:click="openStatusModal({{ $order->id }}, 'order', '{{ $order->status }}', {{ $order->quantity }})">
                                @switch($order->status)
                                    @case('pending')
                                        <flux:badge color="zinc" size="sm" icon="pencil-square" >未処理</flux:badge>
                                        @break
                                    @case('processing')
                                        <flux:badge color="blue" size="sm" icon="pencil-square" >手配中</flux:badge>
                                        @break
                                    @case('shipped')
                                        <flux:badge color="green" size="sm" icon="pencil-square" >出荷済み</flux:badge>
                                        @break
                                    @case('cancelled')
                                        <flux:badge color="red" size="sm" icon="pencil-square" >キャンセル</flux:badge>
                                        @break
                                    @default
                                        <flux:badge color="zinc" size="sm" icon="pencil-square" >{{ $order->status }}</flux:badge>
                                @endswitch
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <div class="rounded border border-zinc-200 dark:border-zinc-700 overflow-hidden"
             x-data="{
                calendar: null,
                events: {{ Js::from($this->calendarEvents()) }},
                init() {
                    let el = document.getElementById('orderShipCalendar');
                    if (! el) return;

                    this.calendar = new FullCalendar.Calendar(el, {
                        plugins: [
                            FullCalendar.dayGridPlugin,
                            FullCalendar.timeGridPlugin,
                            FullCalendar.listPlugin,
                            FullCalendar.interactionPlugin,
                        ],
                        initialView: 'dayGridMonth',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek,listWeek'
                        },
                        events: this.events,
                        locale: 'ja',
                    });
                    this.calendar.render();
                }
             }">
            <div id="orderShipCalendar" wire:ignore></div>
        </div>

    @endif

    <flux:modal name="create-order" class="md:w-120">
        <form wire:submit="createOrder" class="space-y-4">
            <flux:heading size="lg">受注の新規登録</flux:heading>

            <flux:select wire:model="partner_id" label="得意先" placeholder="得意先を選択してください...">
                @foreach($this->partners() as $partner)
                    <flux:select.option value="{{ $partner->id }}">{{ $partner->name }} ({{ $partner->code }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="item_id" label="品目" placeholder="品目を選択してください...">
                @foreach($this->items() as $item)
                    <flux:select.option value="{{ $item->id }}">{{ $item->name }} ({{ $item->code }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="order_number" label="受注番号" placeholder="例：SO-2024001" />

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="order_date" type="date" label="受注日" />
                <flux:input wire:model="due_date" type="date" label="納期" />
            </div>

            <flux:input wire:model="quantity" type="number" step="0.0001" label="数量" />

            <flux:textarea wire:model="note" label="備考" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">キャンセル</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">登録</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="status-modal" class="md:w-120">
        <form wire:submit="updateStatus" class="space-y-4">
            <flux:heading size="lg">ステータスの更新</flux:heading>

            <flux:radio.group wire:model.live="editingStatus" label="新しいステータス">
                <flux:radio value="pending" label="未処理" />
                <flux:radio value="processing" label="手配中" />
                <flux:radio value="shipped" label="出荷済み" />
                <flux:radio value="cancelled" label="キャンセル" />
            </flux:radio.group>

            @if($editingStatus === 'shipped')
                <div class="p-4 bg-zinc-50 dark:bg-white/5 rounded-lg border border-zinc-200 dark:border-zinc-700 space-y-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm">出荷情報の登録</flux:heading>
                        @if($editingEntryType === 'order')
                            <flux:button variant="ghost" size="sm" icon="plus" wire:click="addLotRow">ロット追加</flux:button>
                        @endif
                    </div>

                    <div class="space-y-4">
                        @foreach($selectedLots as $index => $lotSelection)
                            <div class="p-3 bg-white dark:bg-zinc-800 rounded border border-zinc-200 dark:border-zinc-700 relative">
                                @if(count($selectedLots) > 1)
                                    <button type="button" wire:click="removeLotRow({{ $index }})" class="absolute top-2 right-2 text-zinc-400 hover:text-red-500">
                                        <flux:icon name="x-mark" variant="micro" />
                                    </button>
                                @endif

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <flux:select wire:model="selectedLots.{{ $index }}.lot_id" label="使用ロット" placeholder="選択...">
                                        @foreach($this->availableLots() as $lot)
                                            <flux:select.option value="{{ $lot->id }}">
                                                {{ $lot->lot_number }} (在庫: {{ number_format($lot->current_stock, 2) }})
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <flux:input wire:model.live="selectedLots.{{ $index }}.quantity" type="number" step="0.0001" label="数量" />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($errors->has('selectedLots'))
                        <p class="text-sm text-red-500">{{ $errors->first('selectedLots') }}</p>
                    @endif

                    <div class="grid grid-cols-2 gap-4 pt-2">
                        <flux:input wire:model="shipmentNumber" label="出荷番号" />
                        <flux:input wire:model="shippingDate" type="date" label="出荷日" />
                    </div>

                    @php
                        $totalQty = collect($selectedLots)->sum('quantity');
                    @endphp
                    <div class="text-sm flex justify-between items-center px-1">
                        <span class="text-zinc-500">合計数量:</span>
                        <span class="font-bold {{ abs($totalQty - $shipmentQuantity) > 0.00001 ? 'text-red-500' : 'text-green-600' }}">
                            {{ number_format($totalQty, 2) }} / {{ number_format($shipmentQuantity, 2) }}
                        </span>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">キャンセル</flux:button>
                </flux:modal.close>
                @php
                    $isMismatch = $editingStatus === 'shipped' && abs(collect($selectedLots)->sum('quantity') - $shipmentQuantity) > 0.00001;
                @endphp
                <flux:button type="submit" variant="primary" :disabled="$isMismatch">更新</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
