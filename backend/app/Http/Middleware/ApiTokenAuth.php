<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (!$plain) {
            return $this->unauthorized();
        }

        $token = ApiToken::with('user')->where('token_hash', hash('sha256', $plain))->first();
        if (!$token || $token->expires_at->isPast() || !$token->user || !$token->user->is_active) {
            return $this->unauthorized();
        }

        if (!$token->last_used_at || $token->last_used_at->diffInSeconds(now()) > 60) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Sesi tidak valid atau telah berakhir. Silakan login kembali.'], 401);
    }
}
