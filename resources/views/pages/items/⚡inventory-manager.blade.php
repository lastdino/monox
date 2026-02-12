<?php

use Flux\Flux;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Lot;
use Livewire\Component;

new class extends Component
{
    public Item $item;

    public float $adjustmentQuantity = 0;

    public string $type = 'in';

    public string $reason = '';

    public string $lotNumber = '';

    public ?int $selectedLotId = null;

    public function mount(Item $item): void
    {
        $this->item = $item;
    }

    public function adjustStock(): void
    {
        $this->validate([
            'adjustmentQuantity' => ['required', 'numeric', 'min:0.0001'],
            'type' => ['required', 'in:in,out,adjustment'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lotNumber' => ['nullable', 'string', 'max:255'],
            'selectedLotId' => ['nullable', 'exists:monox_lots,id'],
        ]);

        $quantity = $this->adjustmentQuantity;
        if ($this->type === 'out') {
            $quantity = -$quantity;
        }

        $lotId = $this->selectedLotId;

        // 出庫・調整の場合、在庫数を超えないかチェック
        if ($quantity < 0) {
            $availableStock = $lotId
                ? Lot::find($lotId)->current_stock
                : $this->item->current_stock;

            if (abs($quantity) > $availableStock) {
                $this->addError('adjustmentQuantity', '在庫数（'.number_format($availableStock, 2).'）を超える数量は指定できません。');

                return;
            }
        }

        // 入庫でロット番号が入力されている場合、新規作成または既存取得
        if ($this->type === 'in' && $this->lotNumber) {
            $lot = $this->item->lots()->firstOrCreate([
                'lot_number' => $this->lotNumber,
            ], [
                'department_id' => $this->item->department_id,
            ]);
            $lotId = $lot->id;
        }

        $this->item->stockMovements()->create([
            'lot_id' => $lotId,
            'quantity' => $quantity,
            'type' => $this->type,
            'reason' => $this->reason,
            'moved_at' => now(),
            'department_id' => $this->item->department_id,
        ]);

        $this->reset(['adjustmentQuantity', 'reason', 'lotNumber', 'selectedLotId']);
        $this->item = $this->item->fresh(['stockMovements.lot', 'lots.stockMovements']);

        Flux::toast('在庫を調整しました。');
        $this->dispatch('stock-updated');
    }

    public function movements()
    {
        return $this->item->stockMovements()->with('lot')->latest('moved_at')->get();
    }

    public function lots()
    {
        return $this->item->lots()->get()->map(function ($lot) {
            return [
                'id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'current_stock' => $lot->current_stock,
            ];
        });
    }

    public function currentStock()
    {
        return $this->item->current_stock;
    }
};
?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="lg">{{ $item->name }} の在庫管理</flux:heading>
            <flux:subheading>現在の在庫: {{ number_format($this->currentStock(), 2) }} {{ $item->unit }}</flux:subheading>
        </div>
    </div>

    @if($this->lots()->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="sm">ロット別在庫</flux:heading>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                @foreach($this->lots() as $lot)
                    @if($lot['current_stock'] != 0)
                        <div class="p-2 border border-zinc-200 dark:border-zinc-800 rounded flex justify-between items-center">
                            <span class="text-sm font-medium">{{ $lot['lot_number'] }}</span>
                            <span class="text-sm">{{ number_format($lot['current_stock'], 2) }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    <form wire:submit="adjustStock" class="p-4 bg-zinc-50 dark:bg-zinc-900 rounded-lg space-y-4">
        <flux:heading size="sm">在庫調整</flux:heading>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <flux:select wire:model.live="type" label="調整種別">
                <flux:select.option value="in">入庫</flux:select.option>
                <flux:select.option value="out">出庫</flux:select.option>
                <flux:select.option value="adjustment">棚卸調整</flux:select.option>
            </flux:select>

            @if($this->type === 'in')
                <flux:input wire:model="lotNumber" label="ロット番号 (新規)" placeholder="ロット番号を入力..." />
            @else
                <flux:select wire:model="selectedLotId" label="対象ロット (任意)">
                    <flux:select.option value="">品目全体</flux:select.option>
                    @foreach($this->lots() as $lot)
                        <flux:select.option :value="$lot['id']">{{ $lot['lot_number'] }} (在庫: {{ $lot['current_stock'] }})</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        <div class="flex gap-4 items-end">
            <flux:input wire:model="adjustmentQuantity" type="number" step="0.0001" label="数量" class="w-32" />

            <div class="flex-1">
                <flux:input wire:model="reason" label="理由" placeholder="入荷、出荷、棚卸など..." />
            </div>

            <flux:button type="submit" variant="primary">反映</flux:button>
        </div>
    </form>

    <div class="space-y-4">
        <flux:heading size="sm">入出庫履歴</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>日時</flux:table.column>
                <flux:table.column>ロット</flux:table.column>
                <flux:table.column>種別</flux:table.column>
                <flux:table.column>数量</flux:table.column>
                <flux:table.column>理由</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->movements() as $movement)
                    <flux:table.row :key="$movement->id">
                        <flux:table.cell class="whitespace-nowrap text-xs">{{ $movement->moved_at->format(config('monox.datetime.formats.datetime', 'Y-m-d H:i')) }}</flux:table.cell>
                        <flux:table.cell>{{ $movement->lot?->lot_number ?? '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @switch($movement->type)
                                @case('in')
                                    <flux:badge color="green" size="sm">入庫</flux:badge>
                                    @break
                                @case('out')
                                    <flux:badge color="orange" size="sm">出庫</flux:badge>
                                    @break
                                @case('shipment')
                                    <flux:badge color="blue" size="sm">出荷</flux:badge>
                                    @break
                                @case('adjustment')
                                    <flux:badge color="zinc" size="sm">調整</flux:badge>
                                    @break
                                @default
                                    <flux:badge color="zinc" size="sm">{{ $movement->type_label }}</flux:badge>
                            @endswitch
                        </flux:table.cell>
                        <flux:table.cell :class="$movement->quantity > 0 ? 'text-green-600' : 'text-orange-600'">
                            {{ $movement->quantity > 0 ? '+' : '' }}{{ number_format($movement->quantity, 2) }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $movement->reason }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</div>
