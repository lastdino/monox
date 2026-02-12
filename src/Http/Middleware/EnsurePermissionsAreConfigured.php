<?php

namespace Lastdino\Monox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermissionsAreConfigured
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $departmentId = $request->route('department');

        if (! $departmentId) {
            return $next($request);
        }

        // 部門IDがモデルインスタンスの場合はIDを取得
        if ($departmentId instanceof \Illuminate\Database\Eloquent\Model) {
            $departmentId = $departmentId->getKey();
        }

        $permissionName = 'departments.permissions.'.$departmentId;

        // 権限が存在しない、またはその権限を持つロールが一つも設定されていない場合を「未設定」とみなす
        $permission = Permission::where('name', $permissionName)->where('guard_name', 'web')->first();
        $isConfigured = $permission && $permission->roles()->exists();

        if (! $isConfigured) {
            // 権限設定ページ自体へのアクセスならリダイレクトしない
            if ($request->routeIs('monox.departments.permissions')) {
                return $next($request);
            }

            return redirect()
                ->route('monox.departments.permissions', ['department' => $departmentId])
                ->with('status', '権限設定をしてください。');
        }

        return $next($request);
    }
}
