<?php

use Flux\Flux;
use Lastdino\Monox\Models\Item;
use Livewire\Component;

new class extends Component
{
    public ?Item $item = null;

    public string $code = '';

    public string $name = '';

    public string $type = 'part';

    public string $unit = 'pcs';
    public ?float $unit_price = null;
    public float $inventory_alert_quantity = 0;
    public string $description = '';

    public bool $auto_inventory_update = false;

    public function mount(?Item $item = null): void
    {
        if ($item) {
            $this->setItem($item);
        }
    }

    public function setItem(Item $item): void
    {
        $this->item = $item;
        $this->code = $item->code;
        $this->name = $item->name;
        $this->type = $item->type;
        $this->unit = $item->unit;
        $this->unit_price = $item->unit_price;
        $this->inventory_alert_quantity = $item->inventory_alert_quantity ?? 0;
        $this->description = $item->description ?? '';
        $this->auto_inventory_update = $item->auto_inventory_update ?? false;
    }

    public function getTypesProperty(): array
    {
        return $this->item?->department?->getItemTypes() ?? [];
    }

    protected function rules(): array
    {
        $typeValues = collect($this->types)->pluck('value')->implode(',');

        return [
            'code' => ['required', 'string', 'unique:monox_items,code,'.$this->item?->id],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.$typeValues],
            'unit' => ['required', 'string', 'max:50'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'inventory_alert_quantity' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'auto_inventory_update' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->unit_price = $this->unit_price === '' ? null : $this->unit_price;
        $validated = $this->validate();

        $this->item->update($validated);

        Flux::modal('edit-item')->close();

        $this->dispatch('item-updated');
        Flux::toast('品目を更新しました。');
    }
};
?>

<flux:modal name="edit-item" class="md:w-[30rem]">
    <form wire:submit="save">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">品目編集</flux:heading>
                <flux:subheading>品目情報を更新します。</flux:subheading>
            </div>

            <flux:input wire:model="code" label="品目コード" placeholder="ITEM-001" />

            <flux:input wire:model="name" label="品目名" placeholder="ボルト M8" />

            <div class="flex gap-4">
                <flux:select wire:model="type" label="種類">
                    @foreach ($this->types as $type)
                        <flux:select.option value="{{ $type['value'] }}">{{ $type['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="unit" label="単位" placeholder="pcs" />
            </div>

            <flux:input wire:model="unit_price" type="number" step="0.0001" label="単価" placeholder="0.00" />

            <flux:input wire:model="inventory_alert_quantity" type="number" step="0.0001" label="在庫アラート数" placeholder="0.00" />

            <flux:textarea wire:model="description" label="説明" />

            <flux:checkbox wire:model="auto_inventory_update" label="最終工程完了時に在庫を自動更新する" />

            <div class="flex">
                <flux:spacer />

                <flux:button type="submit" variant="primary">更新</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
