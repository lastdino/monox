<?php

use Flux\Flux;
use Lastdino\Monox\Models\Item;
use Livewire\Component;

new class extends Component
{
    public string $code = '';

    public string $name = '';

    public string $type = 'part';

    public string $unit = 'pcs';

    public ?float $unit_price = null;

    public float $inventory_alert_quantity = 0;

    public ?int $expiration_days = null;

    public string $description = '';

    public ?int $departmentId = null;

    public bool $auto_inventory_update = false;
    public bool $sync_to_procurement = false;

    public function mount(): void
    {
        $this->type = $this->types[0]['value'] ?? 'part';
    }

    public function getTypesProperty(): array
    {
        $id = $this->departmentId;
        if (! $id) {
            $department = request()->route('department');
            if ($department instanceof \Illuminate\Database\Eloquent\Model) {
                $id = $department->getKey();
            } elseif ($department) {
                $id = (int) $department;
            }
        }

        return config('monox.models.department')::find($id)?->getItemTypes() ?? [
            ['value' => 'part', 'label' => '部品'],
            ['value' => 'product', 'label' => '製品'],
        ];
    }

    protected function rules(): array
    {
        $types = $this->types;
        $typeValues = collect($types)->pluck('value')->implode(',');

        return [
            'code' => ['required', 'string', 'unique:monox_items,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.$typeValues],
            'unit' => ['required', 'string', 'max:50'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'inventory_alert_quantity' => ['required', 'numeric', 'min:0'],
            'expiration_days' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'auto_inventory_update' => ['boolean'],
            'sync_to_procurement' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->unit_price = $this->unit_price === '' ? null : $this->unit_price;
        $validated = $this->validate();
        $validated['department_id'] = $this->departmentId;

        Item::create($validated);

        $this->reset('code', 'name', 'unit_price', 'inventory_alert_quantity', 'expiration_days', 'description', 'auto_inventory_update', 'sync_to_procurement');
        $this->type = $this->types[0]['value'] ?? 'part';

        Flux::modal('create-item')->close();

        $this->dispatch('item-created');
    }
};
?>

<flux:modal name="create-item" class="md:w-[30rem]">
    <form wire:submit="save">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">品目登録</flux:heading>
                <flux:subheading>新しい品目をシステムに登録します。</flux:subheading>
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

            <flux:input wire:model="expiration_days" type="number" label="有効期限（日数）" placeholder="365" />

            <flux:textarea wire:model="description" label="説明" />

            <flux:checkbox wire:model="auto_inventory_update" label="最終工程完了時に在庫を自動更新する" />

            <flux:checkbox wire:model="sync_to_procurement" label="資材管理(procurement-flow)と在庫を連動する" />

            <div class="flex">
                <flux:spacer />

                <flux:button type="submit" variant="primary">登録</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
