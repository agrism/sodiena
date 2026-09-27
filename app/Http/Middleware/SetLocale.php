<?php

namespace App\Http\Middleware;

use App\Services\LocaleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request and set application locale based on URL segment.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $firstSegment = $request->segment(1);

        // 1. If someone accesses /lv or /lv/events/..., 301 redirect to canonical non-prefixed URL
        if ($firstSegment === 'lv') {
            $canonicalUrl = LocaleService::url('lv', $request->fullUrl());
            return redirect($canonicalUrl, 301);
        }

        // 2. If URL starts with /en or /ru, set locale accordingly
        if (in_array($firstSegment, ['en', 'ru'], true)) {
            $locale = $firstSegment;
        } else {
            // Default locale is Latvian
            $locale = LocaleService::DEFAULT_LOCALE;
        }

        App::setLocale($locale);
        Session::put('locale', $locale);

        return $next($request);
    }
}
