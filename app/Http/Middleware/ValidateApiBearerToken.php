<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiBearerToken
{
    /**
     * Handle an incoming request and validate the Bearer token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('api.bearer_token');
        $providedToken = $request->bearerToken();

        if (empty($expectedToken) || empty($providedToken) || !hash_equals((string) $expectedToken, (string) $providedToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Invalid or missing Bearer token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Handle locale parameter if provided in query, headers, or default to lv
        $locale = $request->input('locale') ?: $request->header('X-Locale');
        if (!empty($locale) && in_array(strtolower($locale), ['lv', 'en', 'ru'], true)) {
            App::setLocale(strtolower($locale));
        } else {
            App::setLocale('lv');
        }

        return $next($request);
    }
}
