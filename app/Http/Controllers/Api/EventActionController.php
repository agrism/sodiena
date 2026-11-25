<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventActionController extends Controller
{
    /**
     * Handle outbound action tracking request (sendBeacon / fetch keepalive).
     */
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'action_type' => ['required', 'string', 'max:20'],
            'url' => ['required', 'string', 'max:1024'],
            'referer' => ['nullable', 'string', 'max:255'],
        ]);

        $userAgent = $request->userAgent() ?? '';
        $device = $this->detectDevice($userAgent);
        $targetDomain = $this->extractDomain($validated['url']);

        EventAction::create([
            'event_id' => $validated['event_id'],
            'action_type' => $validated['action_type'],
            'url' => $validated['url'],
            'target_domain' => $targetDomain,
            'referer' => $validated['referer'] ?? $request->header('referer'),
            'user_id' => auth()->id(),
            'session_id' => $request->header('X-Session-ID') ?: ($request->hasSession() ? $request->session()->getId() : null),
            'device' => $device,
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    /**
     * Extract and normalize domain name from URL.
     */
    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./i', '', $host);

        return substr($host, 0, 100) ?: null;
    }

    /**
     * Simple fast user-agent device detector.
     */
    private function detectDevice(string $ua): string
    {
        if (empty($ua)) {
            return 'desktop';
        }

        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/mobile|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }
}
