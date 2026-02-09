<?php

use Flux\Flux;
use Lastdino\Monox\Models\Partner;
use Lastdino\Monox\Models\Department;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $departmentId = null;

    public function mount(Department $department): void
    {
        $this->departmentId = $department->id;
    }

    public ?Partner $activePartner = null;

    public function editPartner(Partner $partner, string $modalName): void
    {
        $this->activePartner = $partner;
        Flux::modal($modalName)->show();
    }

    public function delete(Partner $partner): void
    {
        $partner->delete();
        Flux::toast('取引先を削除しました。');
    }

    #[On('partner-created')]
    #[On('partner-updated')]
    public function refresh(): void
    {
        // Handled by Livewire
    }

    public function partners()
    {
        return Partner::query()
            ->where('department_id', $this->departmentId)
            ->when($this->search, fn ($query) => $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('code', 'like', '%'.$this->search.'%');
            }))
            ->latest()
            ->paginate(10);
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="xl">取引先マスター</flux:heading>

        <flux:modal.trigger name="create-partner">
            <flux:button variant="primary" icon="plus">新規登録</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="mb-4">
        <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="取引先コードや名前で検索..." />
    </div>

    <flux:table :paginate="$this->partners()">
        <flux:table.columns>
            <flux:table.column>取引先コード</flux:table.column>
            <flux:table.column>取引先名</flux:table.column>
            <flux:table.column>種別</flux:table.column>
            <flux:table.column>連絡先</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->partners() as $partner)
                <flux:table.row :key="$partner->id">
                    <flux:table.cell variant="strong">{{ $partner->code }}</flux:table.cell>
                    <flux:table.cell>{{ $partner->name }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($partner->type === 'supplier') <flux:badge color="blue">仕入先</flux:badge>
                        @elseif ($partner->type === 'customer') <flux:badge color="green">販売先</flux:badge>
                        @else <flux:badge color="purple">両方</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="text-sm">
                            @if ($partner->email) <div class="flex items-center gap-1"><flux:icon name="envelope" size="sm" class="text-zinc-400" /> {{ $partner->email }}</div> @endif
                            @if ($partner->phone) <div class="flex items-center gap-1"><flux:icon name="phone" size="sm" class="text-zinc-400" /> {{ $partner->phone }}</div> @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />

                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="editPartner({{ $partner->id }}, 'edit-partner')">編集</flux:menu.item>
                                <flux:menu.item wire:click="delete({{ $partner->id }})" wire:confirm="本当に削除しますか？" icon="trash" variant="danger">削除</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @isset($activePartner)
        <livewire:monox::partners.edit :partner="$activePartner" :key="'edit-'.($activePartner->id ?? 'new')" />
    @endisset

    <livewire:monox::partners.create />
</div>
