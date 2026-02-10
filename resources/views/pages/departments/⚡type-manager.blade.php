<?php

use Livewire\Component;

new class extends Component
{
    public $department;

    public array $types = [];

    public function mount($department): void
    {
        $departmentModel = config('monox.models.department');
        if (! $department instanceof $departmentModel) {
            abort(404);
        }

        $this->department = $department_id;
        $this->types = $department_id->getItemTypes();
    }

    public function addType(): void
    {
        $this->types[] = ['value' => '', 'label' => ''];
    }

    public function removeType(int $index): void
    {
        unset($this->types[$index]);
        $this->types = array_values($this->types);
    }

    public function save(): void
    {
        $this->validate([
            'types' => ['required', 'array', 'min:1'],
            'types.*.value' => ['required', 'string', 'alpha_dash'],
            'types.*.label' => ['required', 'string', 'max:255'],
        ], [
            'types.*.value.required' => '種類（英名）は必須です。',
            'types.*.value.alpha_dash' => '種類（英名）は半角英数字、ダッシュ、アンダースコアのみ使用できます。',
            'types.*.label.required' => '種類名（表示名）は必須です。',
        ]);

        // 既存の種類を削除して再登録（シンプルにするため）
        $this->department->itemTypes()->delete();

        foreach ($this->types as $index => $type) {
            $this->department->itemTypes()->create([
                'value' => $type['value'],
                'label' => $type['label'],
                'sort_order' => $index,
            ]);
        }

        Flux::modal('type-manager')->close();
        Flux::toast('品目の種類を更新しました。');
        $this->dispatch('item-types-updated');
    }
};
?>

<flux:modal name="type-manager" class="md:w-120">
    <form wire:submit="save">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">品目の種類設定</flux:heading>
                <flux:subheading>{{ $department->name }} 部門で使用する品目の種類を定義します。</flux:subheading>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <flux:label>種類（英名/ID）</flux:label>
                    <flux:label>種類名（表示名）</flux:label>
                </div>

                @foreach ($types as $index => $type)
                    <div class="flex items-start gap-4" :key="'type-'.$index">
                        <div class="flex-1">
                            <flux:input wire:model="types.{{ $index }}.value" placeholder="part" />
                            <x-flux::error name="types.{{ $index }}.value" />
                        </div>
                        <div class="flex-1">
                            <flux:input wire:model="types.{{ $index }}.label" placeholder="部品" />
                            <x-flux::error name="types.{{ $index }}.label" />
                        </div>
                        <flux:button variant="ghost" icon="trash" size="sm" wire:click="removeType({{ $index }})" />
                    </div>
                @endforeach

                <flux:button variant="ghost" icon="plus" size="sm" wire:click="addType">種類を追加</flux:button>
            </div>

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary">保存</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
