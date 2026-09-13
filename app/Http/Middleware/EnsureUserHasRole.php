<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->guest(route('login'))->with('error', __('Please login to access this section.'));
        }

        if (empty($roles)) {
            return $next($request);
        }

        if (!$user->hasRole($roles)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized. Insufficient permissions.'], 403);
            }
            abort(403, __('Unauthorized. Insufficient permissions.'));
        }

        return $next($request);
    }
}
