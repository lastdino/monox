<?php

use Flux\Flux;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Traits\EnsuresPermissionsConfigured;
use Livewire\Component;
use Illuminate\Support\Str;

new class extends Component
{
    use EnsuresPermissionsConfigured;

    public int $departmentId;
    public ?string $api_token = null;

    public function getDepartmentClass(): string
    {
        return config('monox.models.department', Department::class);
    }

    public function mount($department): void
    {
        $departmentClass = $this->getDepartmentClass();
        if ($department instanceof $departmentClass) {
            $this->departmentId = $department->id;
        } else {
            $this->departmentId = (int) $department;
        }

        $this->refreshData();
    }

    public function refreshData(): void
    {
        $department = ($this->getDepartmentClass())::findOrFail($this->departmentId);
        $this->api_token = $department->api_token;
    }

    public function generateToken(): void
    {
        if (! auth()->user()->can('api-tokens.manage.' . $this->departmentId)) {
            Flux::toast('APIトークンを管理する権限がありません。', variant: 'danger');
            return;
        }

        $department = ($this->getDepartmentClass())::findOrFail($this->departmentId);
        $department->update([
            'api_token' => Str::random(40),
        ]);

        $this->refreshData();
        Flux::toast('APIトークンを発行しました。');
    }

    public function revokeToken(): void
    {
        if (! auth()->user()->can('api-tokens.manage.' . $this->departmentId)) {
            Flux::toast('APIトークンを管理する権限がありません。', variant: 'danger');
            return;
        }

        $department = ($this->getDepartmentClass())::findOrFail($this->departmentId);
        $department->update([
            'api_token' => null,
        ]);

        $this->refreshData();
        Flux::toast('APIトークンを失効させました。');
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">APIトークン管理</flux:heading>
            <x-monox::nav-menu :department="$this->departmentId" />
        </div>
    </div>

    <flux:card>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">外部システム連携用トークン</flux:heading>
                <flux:subheading>検査機器や在庫管理システムからデータを送信する際に使用する `X-API-KEY` ヘッダーの値です。</flux:subheading>
            </div>

            @if($api_token)
                <div class="p-4 bg-zinc-100 dark:bg-zinc-800 rounded-lg flex items-center justify-between gap-4">
                    <div class="flex-1">
                        <div class="text-xs text-zinc-500 font-medium mb-1">現在のトークン</div>
                        <div class="font-mono text-lg break-all select-all">{{ $api_token }}</div>
                    </div>
                    <flux:button wire:click="revokeToken" wire:confirm="トークンを失効させますか？このトークンを使用しているシステムは連携できなくなります。" variant="danger" size="sm" icon="x-mark" square />
                </div>

                <div class="flex justify-end">
                    <flux:button wire:click="generateToken" wire:confirm="新しいトークンを発行しますか？既存のトークンは無効になります。" variant="ghost" size="sm" icon="arrow-path">トークンを再発行</flux:button>
                </div>
            @else
                <div class="p-8 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-lg text-center">
                    <flux:icon name="key" class="mx-auto mb-2 text-zinc-300" size="xl" />
                    <flux:heading size="md" class="mb-4">トークンが発行されていません</flux:heading>
                    <flux:button wire:click="generateToken" variant="primary" icon="plus">トークンを発行する</flux:button>
                </div>
            @endif
        </div>
    </flux:card>

    <flux:card>
        <flux:heading size="md" class="mb-4">使い方</flux:heading>
        <div class="space-y-4 text-sm text-zinc-600 dark:text-zinc-400">
            <p>APIリクエストのヘッダーに以下を含めて送信してください：</p>
            <div class="p-3 bg-zinc-900 text-zinc-100 rounded-md font-mono">
                X-API-KEY: {{ $api_token ?: 'YOUR_TOKEN_HERE' }}
            </div>

            <p><strong>注意:</strong> このトークンを知っている人は、この部門のデータに対して操作を行うことができます。取り扱いには十分注意してください。</p>
        </div>
    </flux:card>
</div>
