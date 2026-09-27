<?php

namespace App\Services;

class LocaleService
{
    public const SUPPORTED_LOCALES = ['lv', 'en', 'ru'];
    public const DEFAULT_LOCALE = 'lv';

    /**
     * Get list of supported locale codes.
     */
    public static function supportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    /**
     * Get default system locale (LV).
     */
    public static function defaultLocale(): string
    {
        return self::DEFAULT_LOCALE;
    }

    /**
     * Check if the given locale code is supported.
     */
    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    /**
     * Generate localized URL for a target locale based on current URL or given URL.
     */
    public static function url(string $targetLocale, ?string $url = null): string
    {
        if (!self::isSupported($targetLocale)) {
            $targetLocale = self::DEFAULT_LOCALE;
        }

        $url = $url ?: request()->fullUrl();
        $parsed = parse_url($url);

        $scheme = $parsed['scheme'] ?? (request()->secure() ? 'https' : 'http');
        $host = $parsed['host'] ?? request()->getHost();
        $port = isset($parsed['port']) && !in_array($parsed['port'], [80, 443]) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';

        // Clean leading and trailing slashes for segment parsing
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        // If first segment is a supported locale (lv, en, ru), strip it
        if (!empty($segments) && in_array($segments[0], self::SUPPORTED_LOCALES, true)) {
            array_shift($segments);
        }

        $basePath = implode('/', $segments);

        // For default locale (lv), do not use prefix in URL
        if ($targetLocale === self::DEFAULT_LOCALE) {
            $newPath = $basePath !== '' ? '/' . $basePath : '';
        } else {
            $newPath = '/' . $targetLocale . ($basePath !== '' ? '/' . $basePath : '');
        }

        $origin = "{$scheme}://{$host}{$port}";

        return "{$origin}{$newPath}{$query}";
    }

    /**
     * Generate array of hreflang URLs for SEO (lv, en, ru, and x-default).
     */
    public static function hreflangUrls(?string $url = null): array
    {
        $links = [];
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $links[$locale] = self::url($locale, $url);
        }

        // x-default points to default language (lv)
        $links['x-default'] = self::url(self::DEFAULT_LOCALE, $url);

        return $links;
    }
}
