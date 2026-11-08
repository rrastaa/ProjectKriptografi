<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Anda tidak memiliki hak akses untuk tindakan ini.'], 403);
        }
        return $next($request);
    }
}
