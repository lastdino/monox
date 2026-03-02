<?php

use Flux\Flux;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Models\Equipment;
use Livewire\Component;

new class extends Component
{
    public $departmentId;

    public $search = '';

    public function mount($department): void
    {
        if ($department instanceof Department) {
            $this->departmentId = $department->id;
        } else {
            $this->departmentId = (int) $department;
        }

        if (session('status')) {
            Flux::toast(session('status'));
        }
    }

    public function getDepartmentProperty(): Department
    {
        return Department::findOrFail($this->departmentId);
    }

    public function getSelectedEquipmentsProperty()
    {
        return $this->department->equipments()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    public function getUnselectedEquipmentsProperty()
    {
        $selectedIds = $this->selectedEquipments->pluck('id')->toArray();
        $equipmentModel = config('monox.models.equipment', Equipment::class);

        return $equipmentModel::whereNotIn('id', $selectedIds)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('code')
            ->limit(50)
            ->get();
    }

    public function handleSort($equipmentId, $position, $targetGroupId): void
    {
        if ($targetGroupId === 'selected') {
            // 連動させる
            $this->department->equipments()->syncWithoutDetaching([$equipmentId]);
            Flux::toast('設備を連動させました。');
        } elseif ($targetGroupId === 'unselected') {
            // 連動を解除する
            $this->department->equipments()->detach([$equipmentId]);
            Flux::toast('設備の連動を解除しました。');
        }
    }

    public function disconnect(int $equipmentId): void
    {
        $this->department->equipments()->detach([$equipmentId]);
        Flux::toast('設備の連動を解除しました。');
    }
}; ?>

<div class="space-y-6">
    <header class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <flux:heading size="xl">設備連動設定: {{ $this->department->name }}</flux:heading>
            <x-monox::nav-menu :department="$this->departmentId" />
        </div>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- 未連動ボックス --}}
        <div class="flex flex-col h-full min-h-[500px]">
            <div class="mb-4 space-y-3">
                <flux:heading size="lg" icon="wrench-screwdriver">未連動の設備</flux:heading>
                <flux:subheading>ドラッグして右側のボックスへ移動させると連動します。</flux:subheading>
                <flux:input wire:model.live.debounce.300ms="search" placeholder="設備コード・名称で検索..." icon="magnifying-glass" clearable />
            </div>

            <flux:card class="flex-1 p-0 overflow-hidden flex flex-col">
                <div
                    class="p-4 flex-1 overflow-y-auto space-y-2 bg-zinc-50 dark:bg-zinc-800/50 min-h-[300px]"
                    wire:sort="handleSort"
                    wire:sort:group="equipments"
                    wire:sort:group-id="unselected"
                >
                    @forelse($this->unselectedEquipments as $equipment)
                        <div
                            wire:key="unselected-{{ $equipment->id }}"
                            wire:sort:item="{{ $equipment->id }}"
                            class="p-3 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-sm cursor-grab active:cursor-grabbing hover:border-blue-400 dark:hover:border-blue-500 transition-colors"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <flux:icon name="bars-3" size="sm" class="text-zinc-400" />
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $equipment->name }}</div>
                                        <div class="text-xs text-zinc-500 font-mono">{{ $equipment->code }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-12 text-zinc-400 italic">
                            該当する設備が見つかりません。
                        </div>
                    @endforelse
                </div>
            </flux:card>
        </div>

        {{-- 連動済みボックス --}}
        <div class="flex flex-col h-full min-h-[500px]">
            <div class="mb-4 space-y-3">
                <flux:heading size="lg" icon="check-circle" class="text-blue-600 dark:text-blue-400">連動済みの設備</flux:heading>
                <flux:subheading>この部門で利用可能な設備です。ドラッグして左側へ戻すと解除されます。</flux:subheading>
                <div class="h-[42px]"></div> {{-- 検索バーとの高さを合わせる --}}
            </div>

            <flux:card class="flex-1 p-0 border-blue-200 dark:border-blue-900 overflow-hidden flex flex-col">
                <div
                    class="p-4 flex-1 overflow-y-auto space-y-2 bg-blue-50/50 dark:bg-blue-900/10 min-h-[300px]"
                    wire:sort="handleSort"
                    wire:sort:group="equipments"
                    wire:sort:group-id="selected"
                >
                    @forelse($this->selectedEquipments as $equipment)
                        <div
                            wire:key="selected-{{ $equipment->id }}"
                            wire:sort:item="{{ $equipment->id }}"
                            class="p-3 bg-white dark:bg-zinc-900 border border-blue-200 dark:border-blue-800 rounded-lg shadow-sm cursor-grab active:cursor-grabbing hover:border-red-400 transition-colors group relative"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <flux:icon name="bars-3" size="sm" class="text-blue-400" />
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $equipment->name }}</div>
                                        <div class="text-xs text-blue-600 dark:text-blue-400 font-mono">{{ $equipment->code }}</div>
                                    </div>
                                </div>

                                <button
                                    wire:click="disconnect({{ $equipment->id }})"
                                    class="text-zinc-400 hover:text-red-500 transition-colors"
                                    title="解除"
                                >
                                    <flux:icon name="x-mark" size="sm" />
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-12 text-zinc-400 italic">
                            連動されている設備はありません。
                        </div>
                    @endforelse
                </div>
            </flux:card>
        </div>
    </div>
</div>
