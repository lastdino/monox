<?php

use Illuminate\Support\Facades\DB;
use Lastdino\Monox\Traits\EnsuresPermissionsConfigured;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use EnsuresPermissionsConfigured, WithPagination;

    public string $search = '';

    public string $typeFilter = '';

    public ?int $departmentId = null;

    public bool $onlyInStock = false;

    public function mount($department = null): void
    {
        if ($department instanceof \Illuminate\Database\Eloquent\Model) {
            $this->departmentId = $department->getKey();
        } else {
            $this->departmentId = (int) $department;
        }
    }

    public function getTypesProperty(): array
    {
        return config('monox.models.department')::find($this->departmentId)?->getItemTypes() ?? [];
    }

    public ?\Lastdino\Monox\Models\Item $activeItem = null;

    public function delete(\Lastdino\Monox\Models\Item $item): void
    {
        if (! auth()->user()->can('items.manage.', $this->departmentId)) {
            Flux::toast('この品目を削除する権限がありません。', variant: 'danger');

            return;
        }

        $item->delete();
        Flux::toast('品目を削除しました。');
    }

    public function editItem(\Lastdino\Monox\Models\Item $item, string $modalName): void
    {
        $this->activeItem = $item;
        Flux::modal($modalName)->show();

        // $this->js("\$flux.modal('{$modalName}').show()");
    }

    #[On('item-created')]
    #[On('item-updated')]
    #[On('item-types-updated')]
    public function refresh(): void
    {
        // Handled by Livewire
    }

    public function getDepartmentProperty()
    {
        return config('monox.models.department')::find($this->departmentId);
    }

    public function items()
    {
        return \Lastdino\Monox\Models\Item::query()
            ->when($this->departmentId, fn ($q) => $q->where('department_id', $this->departmentId))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->search, fn ($query) => $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('code', 'like', '%'.$this->search.'%');
            }))
            ->when($this->onlyInStock, function ($q) {
                $q->whereExists(function ($sq) {
                    $sq->select(DB::raw(1))
                        ->from('monox_stock_movements')
                        ->whereColumn('monox_items.id', 'monox_stock_movements.item_id')
                        ->groupBy('item_id')
                        ->havingRaw('SUM(quantity) > 0');
                });
            })
            ->latest()
            ->paginate(10);
    }

    public function departments()
    {
        return collect();
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">品目マスター</flux:heading>
            <x-monox::nav-menu :department="$this->departmentId" />
        </div>

        <div class="flex gap-2">
            @can('items.types.manage.'. $this->departmentId)
                <flux:modal.trigger name="type-manager">
                    <flux:button variant="ghost" icon="cog-6-tooth">種類設定</flux:button>
                </flux:modal.trigger>
            @endcan

            @can('items.manage.'. $this->departmentId)
                <flux:modal.trigger name="create-item">
                    <flux:button variant="primary" icon="plus">新規登録</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    <div class="flex gap-4 mb-4">
        <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="品目コードや名前で検索..." class="flex-1" />

        <div class="flex items-center gap-2">
            <flux:checkbox wire:model.live="onlyInStock" label="在庫ありのみ" />
        </div>

        <flux:select wire:model.live="typeFilter" placeholder="すべての種類" class="w-48">
            <flux:select.option value="">すべての種類</flux:select.option>
            @foreach ($this->types as $type)
                <flux:select.option value="{{ $type['value'] }}">{{ $type['label'] }} ({{ $type['value'] }})</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->items()">
        <flux:table.columns>
            <flux:table.column>品目コード</flux:table.column>
            <flux:table.column>品目名</flux:table.column>
            <flux:table.column>在庫数</flux:table.column>
            <flux:table.column>アラート数</flux:table.column>
            <flux:table.column>種類</flux:table.column>
            <flux:table.column>単位</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->items() as $item)
                <flux:table.row :key="$item->id">
                    <flux:table.cell variant="strong">{{ $item->code }}</flux:table.cell>
                    <flux:table.cell>{{ $item->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:link class="cursor-pointer" wire:click="editItem({{ $item->id }}, 'inventory-manager')">{{ number_format($item->current_stock, 2) }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ number_format($item->inventory_alert_quantity, 2) }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $item->type_label }}</flux:table.cell>
                    <flux:table.cell>{{ $item->unit }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />

                            <flux:menu>
                                @can('items.manage.', $this->departmentId)
                                    <flux:menu.item icon="document-text" wire:click="editItem({{ $item->id }}, 'bom-manager')">BOM管理</flux:menu.item>
                                    <flux:menu.item icon="wrench-screwdriver" wire:click="editItem({{ $item->id }}, 'process-manager')">工程管理</flux:menu.item>
                                @endcan
                                @can('stock.manage.', $this->departmentId)
                                        <flux:menu.item icon="archive-box" wire:click="editItem({{ $item->id }}, 'inventory-manager')">在庫管理</flux:menu.item>
                                @endcan
                                @can('items.manage.', $this->departmentId)
                                    <flux:menu.item icon="pencil-square" wire:click="editItem({{ $item->id }}, 'edit-item')">編集</flux:menu.item>
                                    <flux:menu.item wire:click="delete({{ $item->id }})" wire:confirm="本当に削除しますか？" icon="trash" variant="danger">削除</flux:menu.item>
                                @endcan
                            </flux:menu>
                        </flux:dropdown>

                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="bom-manager" class="md:w-160">
        @isset($activeItem)
            <livewire:monox::items.bom-manager :item="$activeItem" :key="'bom-'.($activeItem->id ?? 'new')" />
        @endisset
    </flux:modal>

    <flux:modal name="process-manager" class="md:w-160">
        @isset($activeItem)
            <livewire:monox::items.process-manager :item="$activeItem" :key="'proc-'.($activeItem->id ?? 'new')" />
        @endisset
    </flux:modal>

    <flux:modal name="inventory-manager" class="md:w-160">
        @isset($activeItem)
            <livewire:monox::items.inventory-manager :item="$activeItem" :key="'inv-'.($activeItem->id ?? 'new')" />
        @endisset
    </flux:modal>

    @isset($activeItem)
        <livewire:monox::items.edit :item="$activeItem" :key="'edit-'.($activeItem->id ?? 'new')" />
    @endisset

    @if($this->department)
        <livewire:monox::departments.type-manager :department="$this->department" />
    @endif

    <livewire:monox::items.create />
</div>
