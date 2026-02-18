<?php

namespace Lastdino\Monox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $apiKey = config('monox.api_key');

        if (empty($apiKey)) {
            return $next($request);
        }

        if ($request->header('X-API-KEY') !== $apiKey) {
            return response()->json([
                'message' => 'Unauthorized. Invalid API Key.',
            ], 401);
        }

        return $next($request);
    }
}
