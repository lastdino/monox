<?php

use Flux\Flux;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Lastdino\Monox\Models\ProductionOrder;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public int $departmentId;

    public string $search = '';

    public string $statusFilter = '';

    // Create Order Form
    public  $item_id = '';

    public ?string $lot_number = null;

    public float $target_quantity = 1;

    public string $note = '';

    public function mount(Department $department): void
    {
        $this->departmentId = $department->id;
    }

    public function orders()
    {
        return ProductionOrder::query()
            ->where('department_id', $this->departmentId)
            ->with(['item.processes', 'lot', 'productionRecords.process'])
            ->when($this->search, function ($q) {
                $q->whereHas('item', fn ($qi) => $qi->where('name', 'like', '%'.$this->search.'%'))
                    ->orWhereHas('lot', fn ($ql) => $ql->where('lot_number', 'like', '%'.$this->search.'%'));
            })
            ->when($this->statusFilter, function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->latest()
            ->paginate(10);
    }

    public function createOrder(): void
    {
        $this->validate([
            'item_id' => ['required', 'exists:monox_items,id'],
            'lot_number' => ['nullable', 'string', 'max:255'],
            'target_quantity' => ['required', 'numeric', 'min:0.0001'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $lotId = null;
        if ($this->lot_number) {
            $lot = Lot::firstOrCreate([
                'lot_number' => $this->lot_number,
                'item_id' => $this->item_id,
                'department_id' => $this->departmentId,
            ]);
            $lotId = $lot->id;
        }

        $order = ProductionOrder::create([
            'department_id' => $this->departmentId,
            'item_id' => $this->item_id,
            'lot_id' => $lotId,
            'target_quantity' => $this->target_quantity,
            'status' => 'pending',
            'note' => $this->note,
        ]);

        $this->reset(['item_id', 'lot_number', 'target_quantity', 'note']);
        Flux::modal('create-order')->close();

        Flux::toast('製造指図を作成しました。');

        $this->redirect(route('monox.production.travel-sheet', [
            'department' => $this->departmentId,
            'order' => $order->id,
        ]), navigate: true);
    }

    public function items()
    {
        return Item::where('department_id', $this->departmentId)->get();
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">製造指図ダッシュボード</flux:heading>
            <x-monox::nav-menu :department="$departmentId" />
        </div>

        <flux:modal.trigger name="create-order">
            <flux:button variant="primary" icon="plus">指図作成</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="mb-4 flex flex-col md:flex-row gap-4">
        <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="品目名やロット番号で検索..." class="flex-1" />

        <flux:radio.group wire:model.live="statusFilter" variant="segmented" size="sm">
            <flux:radio value="" label="すべて" />
            <flux:radio value="pending" label="未着手" />
            <flux:radio value="in_progress" label="進行中" />
            <flux:radio value="completed" label="完了" />
        </flux:radio.group>
    </div>

    <flux:table :paginate="$this->orders()">
        <flux:table.columns>
            <flux:table.column>ID</flux:table.column>
            <flux:table.column>ステータス</flux:table.column>
            <flux:table.column>工程</flux:table.column>
            <flux:table.column>品目</flux:table.column>
            <flux:table.column>ロット</flux:table.column>
            <flux:table.column>予定数</flux:table.column>
            <flux:table.column>作成日</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->orders() as $order)
                <flux:table.row :key="$order->id">
                    <flux:table.cell>
                        <flux:badge size="sm" variant="outline" class="font-mono">{{ $order->id }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @switch($order->status)
                            @case('pending')
                                <flux:badge color="zinc">未着手</flux:badge>
                                @break
                            @case('in_progress')
                                <flux:badge color="blue">進行中</flux:badge>
                                @break
                            @case('completed')
                                <flux:badge color="green">完了</flux:badge>
                                @break
                            @default
                                <flux:badge color="zinc">{{ $order->status }}</flux:badge>
                        @endswitch
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($process = $order->currentProcess())
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" variant="outline" color="zinc">
                                    {{ $process->sort_order }}
                                </flux:badge>
                                <span class="text-sm">{{ $process->name }}</span>
                            </div>
                        @else
                            <span class="text-sm text-zinc-400">-</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="font-medium">{{ $order->item->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $order->item->code }}</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $order->lot->lot_number ?? '-' }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($order->target_quantity, 2) }} {{ $order->item->unit }}</flux:table.cell>
                    <flux:table.cell>{{ $order->created_at->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button href="{{ route('monox.production.travel-sheet', ['department' => $departmentId, 'order' => $order->id]) }}" variant="ghost" size="sm" icon="printer" square tooltip="トラベルシート" />
                        <flux:button href="{{ route('monox.production.worksheet', ['department' => $departmentId, 'order' => $order->id]) }}" variant="ghost" size="sm" icon="document-text" square tooltip="ワークシート" />
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="create-order" class="md:w-120">
        <form wire:submit="createOrder" class="space-y-4">
            <flux:heading size="lg">製造指図の新規作成</flux:heading>

            <flux:select wire:model="item_id" label="品目" placeholder="品目を選択してください...">
                @foreach($this->items() as $item)
                    <flux:select.option value="{{ $item->id }}">{{ $item->name }} ({{ $item->code }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="lot_number" label="ロット番号" placeholder="例：LOT-20240101" />

            <flux:input wire:model="target_quantity" type="number" step="0.0001" label="予定数量" />

            <flux:textarea wire:model="note" label="備考" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">キャンセル</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">作成</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
