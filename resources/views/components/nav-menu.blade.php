@props(['department'])

@php
    $id = $department instanceof \Illuminate\Database\Eloquent\Model ? $department->getKey() : $department;
@endphp

<flux:dropdown>
    <flux:button variant="ghost" icon="chevron-down" size="sm" class="-ml-1" />

    <flux:menu>
        <flux:menu.item icon="chart-bar" href="{{ route('monox.production.analytics', ['department' => $id]) }}" wire:navigate>
            製造分析
        </flux:menu.item>

        <flux:menu.item icon="shopping-cart" href="{{ route('monox.orders.dashboard', ['department' => $id]) }}" wire:navigate>
            受注・出荷
        </flux:menu.item>

        <flux:menu.item icon="document-check" href="{{ route('monox.production.index', ['department' => $id]) }}" wire:navigate>
            製造記録
        </flux:menu.item>

        <flux:menu.item icon="clipboard-document-list" href="{{ route('monox.inventory.lot-summary', ['department' => $id]) }}" wire:navigate>
            在庫・仕掛
        </flux:menu.item>

        <flux:menu.item icon="shield-check" href="{{ route('monox.departments.permissions', ['department' => $id]) }}" wire:navigate>
            権限設定
        </flux:menu.item>

        <flux:menu.separator />

        <flux:menu.item icon="cube" href="{{ route('monox.items.index', ['department' => $id]) }}" wire:navigate>
            品目マスター
        </flux:menu.item>

        <flux:menu.item icon="users" href="{{ route('monox.partners.index', ['department' => $id]) }}" wire:navigate>
            取引先マスター
        </flux:menu.item>
    </flux:menu>
</flux:dropdown>
