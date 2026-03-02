<?php

namespace Lastdino\Monox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lastdino\Monox\Models\Department;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');

        if (empty($apiKey)) {
            return response()->json([
                'message' => 'API Key is required.',
            ], 401);
        }

        // 部門のトークンを確認
        $departmentClass = config('monox.models.department', Department::class);
        $department = $departmentClass::where('api_token', $apiKey)->first();

        if ($department) {
            $request->attributes->set('current_department', $department);

            return $next($request);
        }

        // 互換性のため、グローバルなAPIキーも確認
        $globalKey = config('monox.api_key');
        if (! empty($globalKey) && $apiKey === $globalKey) {
            $request->attributes->set('is_global_api', true);

            return $next($request);
        }

        return response()->json([
            'message' => 'Unauthorized. Invalid API Key.',
        ], 401);
    }
}
