<?php

use Flux\Flux;
use Lastdino\Monox\Models\Department;
use Lastdino\Monox\Traits\EnsuresPermissionsConfigured;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new class extends Component
{
    use EnsuresPermissionsConfigured;

    public int $departmentId;

    public string $userSearch = '';

    // 定義する権限リスト（表示用）
    public array $availablePermissions = [
        'items.manage' => ['label' => '品目登録・編集', 'icon' => 'cube'],
        'items.types.manage' => ['label' => '品目の種類設定', 'icon' => 'cog-6-tooth'],
        'sales-orders.create' => ['label' => '受注登録', 'icon' => 'plus-circle'],
        'sales-orders.status' => ['label' => '受注状態編集', 'icon' => 'check-circle'],
        'partners.manage' => ['label' => '取引先登録・編集', 'icon' => 'users'],
        'production.manage' => ['label' => '製造指図作成', 'icon' => 'document-plus'],
        'production.download' => ['label' => '製造実績DL', 'icon' => 'arrow-down-tray'],
        'stock.manage' => ['label' => '在庫管理', 'icon' => 'archive-box'],
        'stock.download' => ['label' => '在庫データDL', 'icon' => 'arrow-down-tray'],
        'departments.permissions' => ['label' => '権限設定', 'icon' => 'shield-check'],
    ];

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

    public function handleSort($roleName, $position, $targetGroupId = null): void
    {
        if ($targetGroupId === 'available' || ! $targetGroupId) {
            return;
        }

        if (! $this->canConfigurePermissions($this->departmentId)) {
            Flux::toast('権限を設定する権限がありません。', variant: 'danger');

            return;
        }

        // $targetGroupId will be "available" or "permission:{permissionName}"
        if ($targetGroupId && str_starts_with($targetGroupId, 'permission:')) {
            $basePermissionName = str_replace('permission:', '', $targetGroupId);
            $permissionName = $basePermissionName.'.'.$this->departmentId;

            // 権限がなければ作成
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);

            $role = Role::findByName($roleName, 'web');

            if (! $role->hasPermissionTo($permissionName)) {
                $role->givePermissionTo($permissionName);
                Flux::toast("ロール「{$roleName}」に「{$this->availablePermissions[$basePermissionName]['label']}」権限を付与しました。");
            }
        }
    }

    public function removePermissionFromRole(string $basePermissionName, string $roleName): void
    {
        if (! $this->canConfigurePermissions($this->departmentId)) {
            Flux::toast('権限設定を削除する権限がありません。', variant: 'danger');

            return;
        }
        $permissionName = $basePermissionName.'.'.$this->departmentId;
        $role = Role::findByName($roleName, 'web');
        $role->revokePermissionTo($permissionName);
        Flux::toast("ロール「{$roleName}」から「{$this->availablePermissions[$basePermissionName]['label']}」権限を削除しました。");
    }

    public function getRolesProperty()
    {
        return Role::where('guard_name', 'web')->get();
    }

    public function getPermissionsWithRolesProperty()
    {
        $departmentPermissionNames = array_map(fn ($name) => $name.'.'.$this->departmentId, array_keys($this->availablePermissions));

        $allPermissions = Permission::whereIn('name', $departmentPermissionNames)
            ->where('guard_name', 'web')
            ->with('roles')
            ->get();

        $result = [];
        foreach ($this->availablePermissions as $baseName => $info) {
            $permissionName = $baseName.'.'.$this->departmentId;
            $permission = $allPermissions->firstWhere('name', $permissionName);
            $result[$baseName] = [
                'label' => $info['label'],
                'icon' => $info['icon'],
                'roles' => $permission ? $permission->roles->pluck('name')->toArray() : [],
            ];
        }

        return $result;
    }
}; ?>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:heading size="xl">ロール別権限設定</flux:heading>
            <x-monox::nav-menu :department="$this->departmentId" />
        </div>
    </div>

    <div class="p-6 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-xl">
        <flux:heading size="lg" class="mb-4">利用可能なロール（ドラッグして権限に割り当て）</flux:heading>
        <div class="flex flex-wrap gap-2" wire:sort="handleSort" wire:sort:group="roles" wire:sort:group-id="available" wire:sort:no-drop>
            @foreach($this->roles as $role)
                <div wire:key="role-{{ $role->name }}" wire:sort:item="{{ $role->name }}" class="cursor-grab active:cursor-grabbing">
                    <flux:badge size="md" color="zinc" icon="identification">
                        {{ $role->name }}
                    </flux:badge>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($this->permissionsWithRoles as $name => $info)
            <flux:card class="p-0 overflow-hidden flex flex-col">
                <div class="bg-zinc-100 dark:bg-zinc-800 p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-2">
                    <flux:icon :name="$info['icon']" size="sm" class="text-zinc-500" />
                    <flux:heading size="md" class="flex-1">{{ $info['label'] }}</flux:heading>
                </div>

                <div
                    class="p-4 flex-1 min-h-[100px] bg-white dark:bg-zinc-900 transition-colors"
                    wire:sort="handleSort"
                    wire:sort:group="roles"
                    wire:sort:group-id="permission:{{ $name }}"
                >
                    <div class="flex flex-wrap gap-2">
                        @forelse($info['roles'] as $roleName)
                            <div wire:key="perm-{{ $name }}-role-{{ $roleName }}" class="group relative">
                                <flux:badge size="sm" color="blue" icon="identification" class="pr-6">
                                    {{ $roleName }}
                                </flux:badge>
                                <button
                                    wire:click="removePermissionFromRole('{{ $name }}', '{{ $roleName }}')"
                                    class="absolute right-1 top-1/2 -translate-y-1/2 text-blue-800 dark:text-blue-200 hover:text-red-500 transition-colors"
                                >
                                    <flux:icon name="x-mark" size="xs" />
                                </button>
                            </div>
                        @empty
                            <div class="w-full text-center py-4 text-zinc-400 text-sm italic border-2 border-dashed border-zinc-100 dark:border-zinc-800 rounded-lg">
                                ロールをここにドラッグ
                            </div>
                        @endforelse
                    </div>
                </div>
            </flux:card>
        @endforeach
    </div>
</div>
