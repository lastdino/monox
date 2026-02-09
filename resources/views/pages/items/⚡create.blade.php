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

    public string $description = '';

    public ?int $departmentId = null;

    public function mount(): void
    {
        $department = request()->route('department');
        if ($department instanceof \Lastdino\Monox\Models\Department) {
            $this->departmentId = $department->id;
        } elseif ($department) {
            $this->departmentId = (int) $department;
        }

        $this->type = $this->types[0]['value'] ?? 'part';
    }

    public function getTypesProperty(): array
    {
        $id = $this->departmentId;
        if (! $id) {
            $department = request()->route('department');
            if ($department instanceof \Lastdino\Monox\Models\Department) {
                $id = $department->id;
            } elseif ($department) {
                $id = (int) $department;
            }
        }

        return \Lastdino\Monox\Models\Department::find($id)?->getItemTypes() ?? [
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
            'description' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $this->unit_price = $this->unit_price === '' ? null : $this->unit_price;
        $validated = $this->validate();
        $validated['department_id'] = $this->departmentId;

        Item::create($validated);

        $this->reset('code', 'name', 'unit_price', 'description');
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

            <flux:textarea wire:model="description" label="説明" />

            <div class="flex">
                <flux:spacer />

                <flux:button type="submit" variant="primary">登録</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
