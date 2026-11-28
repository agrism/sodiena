<?php

namespace App\Services\Tickets;

class TicketProviderResolver
{
    /**
     * Known ticketing merchants and direct cinema/theatre booking platforms.
     */
    public const MERCHANTS = [
        // Primary Ticketing Platforms
        'bilesuparadize.lv' => ['name' => 'Biļešu Paradīze', 'platform' => 'bilesuparadize', 'is_cinema' => false],
        'bilesuserviss.lv'  => ['name' => 'Biļešu Serviss', 'platform' => 'bilesuserviss', 'is_cinema' => false],
        'bezrindas.lv'      => ['name' => 'BezRindas', 'platform' => 'bezrindas', 'is_cinema' => false],
        'aula.lv'           => ['name' => 'Aula', 'platform' => 'aula', 'is_cinema' => false],
        'ekase.lv'          => ['name' => 'eKase', 'platform' => 'ekase', 'is_cinema' => false],
        'ticketshop.lv'     => ['name' => 'Ticketshop', 'platform' => 'ticketshop', 'is_cinema' => false],
        'mobilly.lv'        => ['name' => 'Mobilly', 'platform' => 'mobilly', 'is_cinema' => false],
        'passportix.eu'     => ['name' => 'Passportix', 'platform' => 'passportix', 'is_cinema' => false],
        'fienta.com'        => ['name' => 'Fienta', 'platform' => 'fienta', 'is_cinema' => false],
        'eventbrite.com'    => ['name' => 'Eventbrite', 'platform' => 'eventbrite', 'is_cinema' => false],
        'eventbrite.co.uk'  => ['name' => 'Eventbrite', 'platform' => 'eventbrite', 'is_cinema' => false],
        'eventbrite.lv'     => ['name' => 'Eventbrite', 'platform' => 'eventbrite', 'is_cinema' => false],
        'livenation.lv'     => ['name' => 'Live Nation', 'platform' => 'livenation', 'is_cinema' => false],
        'ticketbest.eu'     => ['name' => 'TicketBest', 'platform' => 'ticketbest', 'is_cinema' => false],
        'ticketbest.lv'     => ['name' => 'TicketBest', 'platform' => 'ticketbest', 'is_cinema' => false],
        'ticketly.eu'       => ['name' => 'Ticketly', 'platform' => 'ticketly', 'is_cinema' => false],

        // Cinemas
        'apollokino.lv'     => ['name' => 'Apollo Kino', 'platform' => 'apollo', 'is_cinema' => true],
        'forumcinemas.lv'   => ['name' => 'Forum Cinemas', 'platform' => 'forum', 'is_cinema' => true],
        'splendidpalace.lv' => ['name' => 'Splendid Palace', 'platform' => 'splendid', 'is_cinema' => true],
        'cinamonkino.com'   => ['name' => 'Cinamon Kino', 'platform' => 'cinamon', 'is_cinema' => true],
        'kinobize.lv'       => ['name' => 'Kino Bize', 'platform' => 'kinobize', 'is_cinema' => true],
        'kinogaisma.lv'     => ['name' => 'Kino Gaisma', 'platform' => 'kinogaisma', 'is_cinema' => true],
        'kinolora.lv'       => ['name' => 'Kino Lora', 'platform' => 'kinolora', 'is_cinema' => true],

        // Theatres & Concert Halls (direct booking)
        'opera.lv'          => ['name' => 'LNOB', 'platform' => 'opera', 'is_cinema' => false],
        'dailesteatris.lv'  => ['name' => 'Dailes teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'teatris.lv'        => ['name' => 'Latvijas Nacionālais teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'jrt.lv'            => ['name' => 'Jaunais Rīgas teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'mct.lv'            => ['name' => 'Mihaila Čehova Rīgas Krievu teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'vdt.lv'            => ['name' => 'Valmieras Drāmas teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'liepajasteatris.lv'=> ['name' => 'Liepājas teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'lelluteatris.lv'   => ['name' => 'Latvijas Leļļu teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'dirtydealteatro.lv'=> ['name' => 'Dirty Deal Teatro', 'platform' => 'theatre', 'is_cinema' => false],
        'git.lv'            => ['name' => 'Ģertrūdes ielas teātris', 'platform' => 'theatre', 'is_cinema' => false],
        'gors.lv'           => ['name' => 'Latgales vēstniecība GORS', 'platform' => 'venue', 'is_cinema' => false],
        'lielaisdzintars.lv'=> ['name' => 'Lielais dzintars', 'platform' => 'venue', 'is_cinema' => false],
        'cesukoncertzale.lv'=> ['name' => 'Vidzemes koncertzāle Cēsis', 'platform' => 'venue', 'is_cinema' => false],
        'dzintarukoncertzale.lv'=> ['name' => 'Dzintaru koncertzāle', 'platform' => 'venue', 'is_cinema' => false],
    ];

    /**
     * Non-seller sources (aggregators, municipality portals, calendar feeds) that never sell tickets.
     */
    public const AGGREGATOR_SLUGS = [
        'afiro-api',
        'afiro',
        'kulturasdati',
        'kulturasdati-lv',
        'riga-lv',
        'liveriga-com',
        'liveriga',
        'latvia-travel',
        'sodiena',
    ];

    /**
     * Extract normalized hostname from a URL.
     */
    public static function extractDomain(?string $url): ?string
    {
        if (empty($url) || !is_string($url)) {
            return null;
        }

        $trimmed = trim($url);
        $host = parse_url($trimmed, PHP_URL_HOST);
        if (!$host) {
            $host = parse_url('http://' . ltrim($trimmed, '/'), PHP_URL_HOST);
        }

        if (!$host) {
            return null;
        }

        $host = strtolower(trim($host));
        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Resolve legitimate ticket provider name from URL, source slug, and optional fallback.
     *
     * @param string|null $url The ticket purchase URL
     * @param string|null $sourceSlug Originating source slug (e.g. 'afiro-api', 'apollo-kino')
     * @param string|null $fallbackProviderName Scraper-provided or source name fallback
     * @return string|null Resolved merchant name, or null (never returns aggregator name)
     */
    public static function resolve(?string $url, ?string $sourceSlug = null, ?string $fallbackProviderName = null): ?string
    {
        // 1. Priority 1: Match against known ticket merchant domains
        if (!empty($url)) {
            $domain = self::extractDomain($url);
            if ($domain) {
                foreach (self::MERCHANTS as $pattern => $info) {
                    if ($domain === $pattern || str_ends_with($domain, '.' . $pattern)) {
                        return $info['name'];
                    }
                }
            }

            // Fallback substring search in full clean URL
            $cleanLower = strtolower($url);
            foreach (self::MERCHANTS as $pattern => $info) {
                if (str_contains($cleanLower, $pattern)) {
                    return $info['name'];
                }
            }
        }

        // 2. Priority 2: URL didn't match a known merchant.
        // Check if the source or fallback is an aggregator or technical non-seller.
        if (self::isAggregator($sourceSlug, $fallbackProviderName)) {
            return null;
        }

        // If source is a direct venue or trusted seller with an official name
        $cleanFallback = trim((string) $fallbackProviderName);
        if ($cleanFallback !== '' && !self::isAggregatorName($cleanFallback)) {
            return $cleanFallback;
        }

        return null;
    }

    /**
     * Check if a source slug or provider name represents an aggregator.
     */
    public static function isAggregator(?string $sourceSlug, ?string $providerName = null): bool
    {
        if ($sourceSlug) {
            $slugLower = strtolower(trim($sourceSlug));
            if (in_array($slugLower, self::AGGREGATOR_SLUGS, true)) {
                return true;
            }
            if (str_contains($slugLower, 'afiro') || str_contains($slugLower, 'kulturasdati') || str_contains($slugLower, 'agregator')) {
                return true;
            }
        }

        if ($providerName && self::isAggregatorName($providerName)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a given name contains technical or aggregator keywords.
     */
    public static function isAggregatorName(string $name): bool
    {
        $nameLower = mb_strtolower(trim($name), 'UTF-8');
        return (bool) preg_match('/(afiro|pasākumu api|\bapi\b|\baggregator\b|\bscraper\b|\bfeed\b|\bsodiena\b)/iu', $nameLower);
    }

    /**
     * Get platform identifier key for an URL.
     */
    public static function getPlatformSlug(?string $url): string
    {
        if (!empty($url)) {
            $domain = self::extractDomain($url);
            if ($domain) {
                foreach (self::MERCHANTS as $pattern => $info) {
                    if ($domain === $pattern || str_ends_with($domain, '.' . $pattern)) {
                        return $info['platform'];
                    }
                }
            }
        }
        return 'tickets';
    }

    /**
     * Format user-facing button label.
     */
    public static function getLabel(?string $url, ?string $providerName = null): string
    {
        if (empty($providerName)) {
            $providerName = self::resolve($url);
        }

        if (!$providerName) {
            return 'Pirkt biļetes';
        }

        // Check if cinema platform
        if (!empty($url)) {
            $domain = self::extractDomain($url);
            if ($domain && isset(self::MERCHANTS[$domain]) && self::MERCHANTS[$domain]['is_cinema']) {
                return "{$providerName} seansi un biļetes";
            }
        }

        return "Pirkt biļetes ({$providerName})";
    }

    /**
     * Return all merchant configurations.
     */
    public static function getMerchants(): array
    {
        return self::MERCHANTS;
    }
}
