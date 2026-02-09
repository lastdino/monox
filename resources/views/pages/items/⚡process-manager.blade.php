<?php

use Flux\Flux;
use Lastdino\Monox\Models\Item;
use Lastdino\Monox\Models\Process;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Item $item;

    public string $name = '';

    public ?float $standard_time_minutes = null;

    public string $description = '';

    public $template_image;

    public bool $share_template_with_previous = false;

    public ?int $editingProcessId = null;

    public function mount(Item $item): void
    {
        $this->item = $item;
    }

    public function addProcess(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'standard_time_minutes' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template_image' => ['nullable', 'image', 'max:2048'],
        ]);

        $maxOrder = $this->item->processes()->max('sort_order') ?? 0;

        $data = [
            'name' => $this->name,
            'standard_time_minutes' => $this->standard_time_minutes,
            'description' => $this->description,
            'sort_order' => $maxOrder + 10,
            'share_template_with_previous' => $this->share_template_with_previous,
        ];

        if ($this->template_image) {
            $process = $this->item->processes()->create($data);
            $process->addMedia($this->template_image->getRealPath())
                ->usingFileName($this->template_image->getClientOriginalName())
                ->toMediaCollection('template', 'local');
        } else {
            $this->item->processes()->create($data);
        }

        $this->reset(['name', 'standard_time_minutes', 'description', 'template_image']);
        $this->item->load('processes');

        Flux::toast('工程を追加しました。');
    }

    public function editProcess(int $id): void
    {
        $process = Process::findOrFail($id);
        $this->editingProcessId = $id;
        $this->name = $process->name;
        $this->standard_time_minutes = $process->standard_time_minutes;
        $this->description = $process->description ?? '';
        $this->share_template_with_previous = $process->share_template_with_previous;
        $this->template_image = null;
    }

    public function updateProcess(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'standard_time_minutes' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template_image' => ['nullable', 'image', 'max:2048'],
        ]);

        $process = Process::findOrFail($this->editingProcessId);
        $data = [
            'name' => $this->name,
            'standard_time_minutes' => $this->standard_time_minutes,
            'description' => $this->description,
            'share_template_with_previous' => $this->share_template_with_previous,
        ];

        if ($this->template_image) {
            $process->addMedia($this->template_image->getRealPath())
                ->usingFileName($this->template_image->getClientOriginalName())
                ->toMediaCollection('template', 'local');
        }

        $process->update($data);

        $this->cancelEdit();
        $this->item->load('processes');

        Flux::toast('工程を更新しました。');
    }

    public function cancelEdit(): void
    {
        $this->reset(['name', 'standard_time_minutes', 'description', 'editingProcessId', 'template_image', 'share_template_with_previous']);
    }

    public function removeProcess(int $id): void
    {
        $process = Process::findOrFail($id);
        $process->delete();
        $this->item->load('processes');

        Flux::toast('工程を削除しました。');
    }

    public function moveUp(int $id): void
    {
        $process = Process::findOrFail($id);
        $prevProcess = $this->item->processes()
            ->where('sort_order', '<', $process->sort_order)
            ->orderBy('sort_order', 'desc')
            ->first();

        if ($prevProcess) {
            $prevOrder = $prevProcess->sort_order;
            $prevProcess->update(['sort_order' => $process->sort_order]);
            $process->update(['sort_order' => $prevOrder]);
        }

        $this->item->load('processes');
    }

    public function moveDown(int $id): void
    {
        $process = Process::findOrFail($id);
        $nextProcess = $this->item->processes()
            ->where('sort_order', '>', $process->sort_order)
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($nextProcess) {
            $nextOrder = $nextProcess->sort_order;
            $nextProcess->update(['sort_order' => $process->sort_order]);
            $process->update(['sort_order' => $nextOrder]);
        }

        $this->item->load('processes');
    }
};
?>

<div class="space-y-6">
    <div>
        <flux:heading size="lg">{{ $item->name }} の工程管理</flux:heading>
        <flux:subheading>製造工程とその標準時間を登録します。</flux:subheading>
    </div>

    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-4 p-4 border rounded-lg bg-white dark:bg-zinc-800">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input wire:model="name" label="工程名" placeholder="例：切断、組立、検査" />
                <flux:input wire:model="standard_time_minutes" type="number" step="0.1" label="標準時間 (分)" />
            </div>
            <flux:textarea wire:model="description" label="備考" rows="2" />
            <flux:checkbox wire:model="share_template_with_previous" label="前工程の記録用紙（画像）を共有する" />
            <flux:input wire:model="template_image" type="file" label="製造記録表テンプレート (画像)" accept="image/*" />
            <div class="flex justify-end gap-2">
                @if ($editingProcessId)
                    <flux:button wire:click="cancelEdit" variant="ghost">キャンセル</flux:button>
                    <flux:button wire:click="updateProcess" variant="primary">更新</flux:button>
                @else
                    <flux:button wire:click="addProcess" variant="primary" icon="plus">工程を追加</flux:button>
                @endif
            </div>
        </div>

        @if ($item->processes->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>順序</flux:table.column>
                    <flux:table.column>工程名</flux:table.column>
                    <flux:table.column>標準時間</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($item->processes as $process)
                        <flux:table.row :key="$process->id">
                            <flux:table.cell>
                                <div class="flex items-center gap-1">
                                    <flux:button wire:click="moveUp({{ $process->id }})" variant="ghost" size="sm" icon="chevron-up" square />
                                    <flux:button wire:click="moveDown({{ $process->id }})" variant="ghost" size="sm" icon="chevron-down" square />
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    @if ($process->template_image_url)
                                        <flux:icon icon="photo" class="text-zinc-500" variant="micro" />
                                    @endif
                                    <div>
                                        <div class="font-medium text-zinc-800 dark:text-white">
                                            {{ $process->name }}
                                            @if($process->share_template_with_previous)
                                                <flux:badge size="sm" color="blue" variant="outline" class="ml-2">共有中</flux:badge>
                                            @endif
                                        </div>
                                        @if ($process->description)
                                            <div class="text-xs text-zinc-500">{{ $process->description }}</div>
                                        @endif
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $process->standard_time_minutes ? number_format($process->standard_time_minutes, 1) . ' 分' : '-' }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    @php
                                        $effectiveMedia = $process->template_media;
                                        if (!$effectiveMedia && $process->share_template_with_previous) {
                                            $curr = $process;
                                            while ($curr && $curr->share_template_with_previous) {
                                                $prev = $item->processes->where('sort_order', '<', $curr->sort_order)->last();
                                                if (!$prev) { break; }
                                                if ($prev->template_media) { $effectiveMedia = $prev->template_media; break; }
                                                $curr = $prev;
                                            }
                                        }
                                    @endphp
                                    @if ($effectiveMedia)
                                        <flux:button href="{{ route('monox.processes.annotations', ['process' => $process->id]) }}" variant="ghost" size="sm" icon="cursor-arrow-ripple" square />
                                    @endif
                                    <flux:button wire:click="editProcess({{ $process->id }})" variant="ghost" size="sm" icon="pencil-square" square />
                                    <flux:button wire:click="removeProcess({{ $process->id }})" wire:confirm="この工程を削除しますか？" variant="ghost" size="sm" icon="trash" square />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <flux:text align="center" class="py-8">工程が登録されていません。</flux:text>
        @endif
    </div>
</div>
