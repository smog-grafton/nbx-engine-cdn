<?php

namespace App\Http\Middleware;

use App\Models\MediaApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMediaApiToken
{
    public function handle(Request $request, Closure $next, string $ability = '*'): Response
    {
        $header = (string) $request->header('Authorization', '');
        $token = '';

        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
        }

        if ($token === '') {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => 'Missing API token.',
            ], 401);
        }

        $hashed = hash('sha256', $token);
        $apiToken = MediaApiToken::where('token_hash', $hashed)->first();

        $envToken = (string) config('nbx.api_key', '');
        if (! $apiToken && $envToken !== '' && hash_equals($envToken, $token)) {
            return $next($request);
        }

        if (! $apiToken || ! $apiToken->isUsable() || ! $apiToken->can($ability)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => 'Invalid or expired API token.',
            ], 401);
        }

        $touchInterval = max(60, (int) config('cdn.api_token_touch_interval_seconds', 300));
        $touchKey = 'media-api-token:last-used:' . $apiToken->id;

        if (Cache::add($touchKey, true, now()->addSeconds($touchInterval))) {
            $apiToken->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('media_api_token', $apiToken);

        return $next($request);
    }
}
