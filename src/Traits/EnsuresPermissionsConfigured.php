<?php

namespace Lastdino\Monox\Traits;

use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

trait EnsuresPermissionsConfigured
{
    /**
     * 権限設定ページにおいて、初期設定時のみ権限チェックをバイパスするか判断します。
     */
    protected function canConfigurePermissions(int $departmentId): bool
    {
        $permissionName = 'departments.permissions.'.$departmentId;
        $permission = Permission::where('name', $permissionName)->where('guard_name', 'web')->first();
        $isConfigured = $permission && $permission->roles()->exists();

        // 未設定なら誰でも（ログインしていれば）設定可能
        if (! $isConfigured) {
            return true;
        }

        // 設定済みなら、その権限を持っているユーザーのみ設定可能
        return Auth::user()->can($permissionName);
    }
}
