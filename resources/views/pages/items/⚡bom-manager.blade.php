<?php

use Flux\Flux;
use Lastdino\Monox\Models\Item;
use Livewire\Component;

new class extends Component
{
    public Item $item;

    public string $search = '';

    public $selectedChildId = '';

    public float $quantity = 1.0;

    public string $note = '';

    public function mount(Item $item): void
    {
        $this->item = $item;
    }

    public function addComponent(): void
    {
        $this->validate([
            'selectedChildId' => [
                'required',
                'exists:monox_items,id',
                'different:item.id',
                function ($attribute, $value, $fail) {
                    $childItem = Item::find($value);
                    if ($childItem && $childItem->department_id !== $this->item->department_id) {
                        $fail('異なる部門の品目を構成部品に含めることはできません。');
                    }
                },
            ],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'selectedChildId.different' => '自分自身を構成部品に含めることはできません。',
        ]);

        $this->item->components()->attach($this->selectedChildId, [
            'quantity' => $this->quantity,
            'note' => $this->note,
            'department_id' => $this->item->department_id,
        ]);

        $this->reset(['selectedChildId', 'quantity', 'note', 'search']);
        $this->item->load('components');

        Flux::toast('構成部品を追加しました。');
    }

    public function removeComponent(int $childId): void
    {
        $this->item->components()->detach($childId);
        $this->item->load('components');

        Flux::toast('構成部品を削除しました。');
    }

    public function availableItems()
    {
        return Item::query()
            ->where('department_id', $this->item->department_id)
            ->where('id', '!=', $this->item->id)
            ->whereNotIn('id', $this->item->components->pluck('id'))
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%"))
            ->limit(10)
            ->get();
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="lg">{{ $item->name }} の構成部品 (BOM)</flux:heading>
            <flux:subheading>製品を構成する部品とその数量を管理します。</flux:subheading>
        </div>
    </div>

    <div class="space-y-4">
        <div class="flex gap-4 items-end">
            <div class="flex-1">
                <flux:select wire:model="selectedChildId" label="部品選択" placeholder="追加する部品を選択...">
                    @foreach ($this->availableItems() as $availableItem)
                        <flux:select.option :value="$availableItem->id">{{ $availableItem->code }}: {{ $availableItem->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="w-24">
                <flux:input wire:model="quantity" type="number" step="0.0001" label="数量" />
            </div>
            <flux:button wire:click="addComponent" variant="primary">追加</flux:button>
        </div>

        @if ($item->components->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>品目コード</flux:table.column>
                    <flux:table.column>品目名</flux:table.column>
                    <flux:table.column>数量</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($item->components as $bomComponent)
                        <flux:table.row :key="$bomComponent->id">
                            <flux:table.cell>{{ $bomComponent->code }}</flux:table.cell>
                            <flux:table.cell>{{ $bomComponent->name }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($bomComponent->pivot->quantity, 4) }} {{ $bomComponent->unit }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button wire:click="removeComponent({{ $bomComponent->id }})" variant="ghost" size="sm" icon="trash" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <flux:text align="center" class="py-8">構成部品が登録されていません。</flux:text>
        @endif
    </div>
</div>
