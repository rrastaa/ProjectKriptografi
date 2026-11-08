<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure a USB security key (or any external token file) is present.
 *
 * The presence of a file at the path defined by `USB_KEY_PATH` is used as a
 * hardware‑bound token. Its SHA‑256 hash must match the expected hash stored in
 * `USB_KEY_HASH`. If the file is missing or the hash does not match, the request
 * is aborted with a 403 response.
 */
class CheckUsbKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $path = env('USB_KEY_PATH');
        $expectedHash = env('USB_KEY_HASH');

        // If not configured, allow access (fail‑open) – developers can opt‑in.
        if (empty($path) || empty($expectedHash)) {
            return $next($request);
        }

        if (!file_exists($path)) {
            return response()->json([
                'message' => 'USB security key not detected.',
            ], Response::HTTP_FORBIDDEN);
        }

        $contents = file_get_contents($path);
        $hash = hash('sha256', $contents);
        if (!hash_equals($expectedHash, $hash)) {
            return response()->json([
                'message' => 'Invalid USB security key.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
?>
