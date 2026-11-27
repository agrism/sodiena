<?php

namespace App\Models;

use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Enums\SourceType;
use App\Services\EventLanguageService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'source_id',
        'source_slug',
        'location_id',
        'title',
        'slug',
        'description',
        'short_description',
        'start_at',
        'end_at',
        'all_day',
        'is_free',
        'price_min',
        'price_max',
        'currency',
        'ticket_url',
        'image_url',
        'internal_image_url',
        'source_url',
        'source_external_id',
        'fingerprint',
        'entertainment_type',
        'status',
        'published_at',
        'is_featured',
        'is_promoted',
        'views_count',
        'raw_data',
        'language_mode',
        'spoken_languages',
        'subtitle_languages',
        'language_source',
        'canonical_fingerprint',
        'primary_content_source_id',
        'next_start_at',
        'last_end_at',
        'occurrences_count',
        'raw_categories',
        'content_hash',
        'merged_into_id',
        'merged_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'published_at' => 'datetime',
        'all_day' => 'boolean',
        'is_free' => 'boolean',
        'price_min' => 'float',
        'price_max' => 'float',
        'is_featured' => 'boolean',
        'is_promoted' => 'boolean',
        'views_count' => 'integer',
        'raw_data' => 'array',
        'language_mode' => EventLanguageMode::class,
        'language_source' => EventLanguageSource::class,
        'spoken_languages' => 'array',
        'subtitle_languages' => 'array',
        'next_start_at' => 'datetime',
        'last_end_at' => 'datetime',
        'merged_at' => 'datetime',
        'occurrences_count' => 'integer',
        'raw_categories' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'published_at' => null,
        'all_day' => false,
        'is_free' => false,
        'currency' => 'EUR',
        'is_promoted' => false,
        'language_mode' => 'unknown',
        'spoken_languages' => '[]',
        'subtitle_languages' => '[]',
        'language_source' => 'unknown',
    ];

    protected static function booted(): void
    {
        static::creating(function ($event) {
            if (empty($event->slug)) {
                $event->slug = Str::slug($event->title) . '-' . Str::random(6);
            }
            if (empty($event->fingerprint)) {
                $dateStr = ($event->start_at && $event->start_at->format('H:i') !== '00:00') ? $event->start_at->format('Y-m-d H:i') : ($event->start_at ? $event->start_at->format('Y-m-d') : '');
                $event->fingerprint = md5(mb_strtolower(trim($event->title)) . '|' . $dateStr . '|' . ($event->location_id ?? ''));
            }
            if (empty($event->source_slug) && $event->source_id) {
                $source = Source::find($event->source_id);
                if ($source) {
                    $event->source_slug = $source->slug;
                }
            }
        });

        static::saving(function (Event $event) {
            if ($event->title !== null) {
                $event->title = mb_substr(trim($event->title), 0, 255, 'UTF-8');
            }

            if ($event->merged_into_id !== null && $event->id !== null && (int) $event->merged_into_id === (int) $event->id) {
                throw new \InvalidArgumentException('An event cannot be merged into itself.');
            }
        });
    }

    public function setTitleAttribute(?string $value): void
    {
        $this->attributes['title'] = $value !== null ? mb_substr(trim($value), 0, 255, 'UTF-8') : null;
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    public function activeOccurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class)->whereIn('status', ['scheduled', 'postponed']);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function primaryContentSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'primary_content_source_id');
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(EventRedirect::class, 'target_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(EventTranslation::class);
    }

    /**
     * Get specific or current locale translation from event_translations table
     */
    public function translation(?string $locale = null): ?EventTranslation
    {
        if (!$this->exists) {
            return null;
        }

        $locale = $locale ?: app()->getLocale();

        // If translations are loaded in relation, search the collection
        if ($this->relationLoaded('translations')) {
            $trans = $this->translations->firstWhere('locale', $locale);
            if ($trans) {
                return $trans;
            }
            // Fallback to 'lv'
            $transLv = $this->translations->firstWhere('locale', 'lv');
            if ($transLv) {
                return $transLv;
            }
            return $this->translations->first();
        }

        // Direct DB query fallback
        return $this->translations()->where('locale', $locale)->first()
            ?: $this->translations()->where('locale', 'lv')->first()
            ?: $this->translations()->first();
    }

    public function getLocalizedUrlAttribute(): string
    {
        return \App\Services\LocaleService::url(app()->getLocale(), route('events.show', $this->slug));
    }

    public function getTitleAttribute(?string $value): string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->title)) ? $trans->title : ($value ?? '');
    }

    public function getDescriptionAttribute(?string $value): ?string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->description)) ? $trans->description : $value;
    }

    public function getFormattedDescriptionHtmlAttribute(): string
    {
        return app(\App\Services\EventDescriptionFormatter::class)->format($this->description, (bool)$this->is_free);
    }

    public function getShortDescriptionAttribute(?string $value): ?string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->short_description)) ? $trans->short_description : $value;
    }

    public function getSeoDescriptionAttribute(): string
    {
        $desc = $this->short_description ?: Str::limit(strip_tags($this->description ?? ''), 300);
        $cleanDesc = trim(Str::squish($desc));

        if (!empty($cleanDesc)) {
            return $cleanDesc;
        }

        $locStr = $this->location?->name ?: ($this->location?->city ?: 'Latvijā');
        $dateStr = $this->formatted_date ?: ($this->start_at ? $this->start_at->format('d.m.Y H:i') : '');
        $catName = $this->categories->first()?->name;

        if ($catName) {
            return "{$this->title} — {$catName} ({$locStr}, {$dateStr}). Informācija un biļetes vietnē Šodiena.";
        }

        return "{$this->title} ({$locStr}, {$dateStr}). Informācija un biļetes vietnē Šodiena.";
    }

    public function getDisplayImageUrlAttribute(): string
    {
        if (!empty($this->internal_image_url)) {
            return str_starts_with($this->internal_image_url, 'http') ? $this->internal_image_url : url($this->internal_image_url);
        }

        if (!empty($this->image_url) && !str_contains($this->image_url, 'aplis-default-og-img.jpg')) {
            return str_starts_with($this->image_url, 'http') ? $this->image_url : url($this->image_url);
        }

        return asset('images/default-event.jpg');
    }

    public function getLocalizedSlugAttribute(): string
    {
        $trans = $this->translation();
        return ($trans && !empty($trans->slug)) ? $trans->slug : $this->slug;
    }

    public function getTicketUrlAttribute(?string $value): ?string
    {
        if (!empty($value) && !str_contains($value, 'afiro.lv') && !str_contains($value, 'imagekit.io')) {
            return $value;
        }

        return $this->extractRealTicketUrl();
    }

    public function getSourceUrlAttribute(?string $value): ?string
    {
        if (!empty($value) && !str_contains($value, 'afiro.lv') && !str_contains($value, 'imagekit.io')) {
            return $value;
        }

        return $this->extractRealOfficialUrl();
    }

    /**
     * Append or replace standard Sodiena.lv UTM parameters on outbound URLs.
     */
    public static function appendUtm(?string $url, string $content = 'ticket_button'): ?string
    {
        if (empty($url) || !is_string($url)) {
            return $url;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return $url;
        }

        $queryParams = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
        }

        // Strip stale/alien utm_* or tracking parameters
        foreach (array_keys($queryParams) as $key) {
            if (str_starts_with($key, 'utm_') || in_array(strtolower($key), ['ref', 'source', 'afiro'], true)) {
                unset($queryParams[$key]);
            }
        }

        // Append Sodiena.lv tracking parameters
        $queryParams['utm_source'] = 'sodiena.lv';
        $queryParams['utm_medium'] = 'referral';
        $queryParams['utm_campaign'] = 'event_detail';
        $queryParams['utm_content'] = $content;

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $user = $parsed['user'] ?? '';
        $pass = isset($parsed['pass']) ? ':' . $parsed['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = '?' . http_build_query($queryParams);
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return "{$scheme}{$user}{$pass}{$host}{$port}{$path}{$query}{$fragment}";
    }

    /**
     * Get ticket URL with Sodiena.lv UTM parameters.
     */
    public function getTrackedTicketUrl(?string $url = null): ?string
    {
        $target = $url ?: $this->ticket_url;
        return $target ? self::appendUtm($target, 'ticket_button') : null;
    }

    /**
     * Get official website URL with Sodiena.lv UTM parameters.
     */
    public function getTrackedSourceUrl(): ?string
    {
        $target = $this->source_url;
        return $target ? self::appendUtm($target, 'website_button') : null;
    }

    /**
     * Get ticket links with Sodiena.lv UTM parameters.
     *
     * @return array<array{url: string, original_url: string, title: string, label: string, platform: string}>
     */
    public function getTrackedTicketLinksAttribute(): array
    {
        $links = $this->ticket_links;
        return array_map(function ($link) {
            $link['original_url'] = $link['url'];
            $link['url'] = self::appendUtm($link['url'], 'ticket_button');
            return $link;
        }, $links);
    }

    public function extractRealTicketUrl(): ?string
    {
        $links = $this->ticket_links;
        return !empty($links) ? $links[0]['url'] : null;
    }

    public function extractRealOfficialUrl(): ?string
    {
        $text = ($this->attributes['description'] ?? '') . ' ' . json_encode($this->raw_data ?? []);
        if (empty(trim($text))) {
            return null;
        }

        preg_match_all('/https?:\/\/[^\s\)\"\'<>]+/i', $text, $matches);

        foreach ($matches[0] as $rawUrl) {
            $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $rawUrl);
            $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

            if (str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                continue;
            }

            if (!str_contains($cleanUrl, 'youtube.com') && !str_contains($cleanUrl, 'youtu.be') && !str_contains($cleanUrl, 'tiktok.com')) {
                return $cleanUrl;
            }
        }

        return null;
    }

    /**
     * Get all structured ticket and cinema booking links
     *
     * @return array<array{url: string, title: string, label: string, platform: string}>
     */
    public function getTicketLinksAttribute(): array
    {
        $links = [];
        $seenUrls = [];

        $platformNames = \App\Services\Tickets\TicketProviderResolver::getMerchants();

        // 1. Check raw_data cta.links
        if (!empty($this->raw_data['cta']['links']) && is_array($this->raw_data['cta']['links'])) {
            foreach ($this->raw_data['cta']['links'] as $item) {
                $rawUrl = is_array($item) ? ($item['url'] ?? null) : (is_string($item) ? $item : null);
                if (!$rawUrl || !is_string($rawUrl)) continue;

                $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $rawUrl);
                $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

                if (str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                    continue;
                }

                $normalized = strtolower($cleanUrl);
                if (isset($seenUrls[$normalized])) continue;
                $seenUrls[$normalized] = true;

                $providerName = \App\Services\Tickets\TicketProviderResolver::resolve($cleanUrl, $this->source_slug, $this->source?->name);

                if (!$providerName) {
                    $itemKind = is_array($item) ? strtolower($item['kind'] ?? '') : '';
                    if (!in_array($itemKind, ['ticket', 'tickets', 'buy_ticket'])) {
                        // Do not treat general website or details links as ticket purchase buttons
                        continue;
                    }
                }

                $links[] = [
                    'url' => $cleanUrl,
                    'title' => $providerName ?: 'Biļetes',
                    'provider_name' => $providerName,
                    'label' => \App\Services\Tickets\TicketProviderResolver::getLabel($cleanUrl, $providerName),
                    'platform' => \App\Services\Tickets\TicketProviderResolver::getPlatformSlug($cleanUrl),
                ];
            }
        }

        // 1.5. Check raw_data ticket_links
        if (!empty($this->raw_data['ticket_links']) && is_array($this->raw_data['ticket_links'])) {
            foreach ($this->raw_data['ticket_links'] as $item) {
                $rawUrl = is_array($item) ? ($item['url'] ?? null) : (is_string($item) ? $item : null);
                if (!$rawUrl || !is_string($rawUrl)) continue;

                $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $rawUrl);
                $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

                if (str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                    continue;
                }

                $normalized = strtolower($cleanUrl);
                if (isset($seenUrls[$normalized])) continue;
                $seenUrls[$normalized] = true;

                $providerName = \App\Services\Tickets\TicketProviderResolver::resolve($cleanUrl, $this->source_slug, $this->source?->name);

                $links[] = [
                    'url' => $cleanUrl,
                    'title' => $providerName ?: 'Biļetes',
                    'provider_name' => $providerName,
                    'label' => \App\Services\Tickets\TicketProviderResolver::getLabel($cleanUrl, $providerName),
                    'platform' => \App\Services\Tickets\TicketProviderResolver::getPlatformSlug($cleanUrl),
                ];
            }
        }

        // 2. Check ticket_url column
        $directTicketUrl = $this->attributes['ticket_url'] ?? null;
        if (!empty($directTicketUrl)) {
            $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $directTicketUrl);
            $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

            if (!str_contains($cleanUrl, 'afiro.lv') && !str_contains($cleanUrl, 'imagekit.io')) {
                $normalized = strtolower($cleanUrl);
                if (!isset($seenUrls[$normalized])) {
                    $seenUrls[$normalized] = true;

                    $providerName = \App\Services\Tickets\TicketProviderResolver::resolve($cleanUrl, $this->source_slug, $this->source?->name);

                    // If direct ticket URL is identical to official source URL and not on a recognized ticket platform,
                    // it is an official website link rather than a ticket purchase link.
                    $isDuplicateSource = !$providerName && !empty($this->attributes['source_url']) && $cleanUrl === $this->attributes['source_url'];

                    if (!$isDuplicateSource) {
                        $links[] = [
                            'url' => $cleanUrl,
                            'title' => $providerName ?: 'Biļetes',
                            'provider_name' => $providerName,
                            'label' => \App\Services\Tickets\TicketProviderResolver::getLabel($cleanUrl, $providerName),
                            'platform' => \App\Services\Tickets\TicketProviderResolver::getPlatformSlug($cleanUrl),
                        ];
                    }
                }
            }
        }

        // 3. Scan description for additional ticket platform URLs
        $text = (string) ($this->attributes['description'] ?? '');
        if (!empty(trim($text))) {
            preg_match_all('/https?:\/\/[^\s\)\"\'<>]+/i', $text, $matches);
            foreach ($matches[0] as $rawUrl) {
                $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $rawUrl);
                $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

                if (str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                    continue;
                }

                $normalized = strtolower($cleanUrl);
                if (isset($seenUrls[$normalized])) continue;

                $providerName = \App\Services\Tickets\TicketProviderResolver::resolve($cleanUrl);
                if ($providerName) {
                    $seenUrls[$normalized] = true;
                    $links[] = [
                        'url' => $cleanUrl,
                        'title' => $providerName,
                        'provider_name' => $providerName,
                        'label' => \App\Services\Tickets\TicketProviderResolver::getLabel($cleanUrl, $providerName),
                        'platform' => \App\Services\Tickets\TicketProviderResolver::getPlatformSlug($cleanUrl),
                    ];
                }
            }
        }

        // Venue isolation guard: Screenings of cinema chains must ONLY show tickets for that cinema chain
        $venueName = mb_strtolower(trim($this->location?->name ?? ''), 'UTF-8');
        $sourceSlug = mb_strtolower(trim($this->source_slug ?? ''), 'UTF-8');

        $isApollo = $sourceSlug === 'apollo-kino' || str_contains($venueName, 'apollo');
        $isForum = $sourceSlug === 'forum-cinemas' || str_contains($venueName, 'forum');
        $isCinamon = str_contains($venueName, 'cinamon');

        $filteredLinks = [];
        foreach ($links as $link) {
            $u = strtolower($link['url']);
            if ($isApollo) {
                if (!str_contains($u, 'apollokino.lv')) {
                    continue;
                }
            } elseif ($isForum) {
                if (!str_contains($u, 'forumcinemas.lv')) {
                    continue;
                }
            } elseif ($isCinamon) {
                if (!str_contains($u, 'cinamonkino.com')) {
                    continue;
                }
            }
            $filteredLinks[] = $link;
        }

        return $filteredLinks;
    }

    public function getDisplayVenueAttribute(): string
    {
        $locName = $this->location?->name;
        $locCity = $this->location?->city;

        $ticketLinks = $this->ticket_links;
        $cinemaPlatforms = ['apollo', 'forum', 'splendid', 'cinamon'];
        $foundCinemas = [];
        foreach ($ticketLinks as $tl) {
            if (in_array($tl['platform'], $cinemaPlatforms, true)) {
                $foundCinemas[] = $tl['title'];
            }
        }

        if (!empty($foundCinemas) && ($locName === 'Riga' || $locName === 'Latvija' || empty($locName) || $locName === $locCity)) {
            $cinemasText = implode(', ', array_unique($foundCinemas));
            return ($locCity ? "{$locCity}: " : '') . $cinemasText;
        }

        return $locName ?: ($locCity ?: 'Latvija');
    }

    public function getOrganizerDisplayNameAttribute(): ?string
    {
        $rawOrg = null;

        if (!empty($this->raw_data['organizer'])) {
            $org = $this->raw_data['organizer'];
            if (is_string($org) && !empty(trim($org)) && !str_contains(strtolower($org), 'afiro')) {
                $rawOrg = trim($org);
            } elseif (is_array($org) && !empty($org['name']) && !str_contains(strtolower($org['name']), 'afiro')) {
                $rawOrg = trim($org['name']);
            }
        }

        if (!$rawOrg && !empty($this->raw_data['contacts']['organizer'])) {
            $org = $this->raw_data['contacts']['organizer'];
            if (is_string($org) && !empty(trim($org)) && !str_contains(strtolower($org), 'afiro')) {
                $rawOrg = trim($org);
            }
        }

        // If explicitly provided in event data, clean up any URL/domain in parentheses and return
        if ($rawOrg) {
            $clean = preg_replace('/\s*\([^)]*\.[a-z]{2,}[^)]*\)/ui', '', $rawOrg);
            return trim($clean) ?: null;
        }

        // Only fall back to source name if source is explicitly a THEMATIC festival organizer
        // Never assume Sports leagues, Municipal agendas, Ticketing brokers, or Aggregators are the organizer
        if ($this->source && $this->source->type === SourceType::THEMATIC) {
            $clean = preg_replace('/\s*\([^)]*\.[a-z]{2,}[^)]*\)/ui', '', $this->source->name);
            return trim($clean) ?: null;
        }

        return null;
    }

    public function getOrganizerUrlAttribute(): ?string
    {
        if (!empty($this->raw_data['organizer']['url'])) {
            $url = $this->raw_data['organizer']['url'];
            if (is_string($url) && !str_contains($url, 'afiro.lv')) {
                return $url;
            }
        }

        if (!empty($this->raw_data['contacts']['url'])) {
            $url = $this->raw_data['contacts']['url'];
            if (is_string($url) && !str_contains($url, 'afiro.lv')) {
                return $url;
            }
        }

        if ($this->source && $this->source->type === SourceType::THEMATIC && !empty($this->source->url)) {
            return $this->source->url;
        }

        return null;
    }

    /**
     * Check if event originates from Afiro API
     */
    public function isAfiro(): bool
    {
        return $this->source_slug === 'afiro-api'
            || (isset($this->raw_data['originalLocale']) && !empty($this->source_external_id) && !str_starts_with($this->source_external_id, 'bezrindas-') && !str_starts_with($this->source_external_id, 'bilesu-') && !str_starts_with($this->source_external_id, 'gors-'));
    }

    /**
     * Get direct Afiro event URL if applicable
     */
    public function getAfiroUrlAttribute(): ?string
    {
        if ($this->isAfiro() && !empty($this->source_external_id)) {
            return "https://afiro.lv/events/{$this->source_external_id}";
        }

        return null;
    }

    // Scopes for querying and HTMX filtering
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
                     ->where('published_at', '<=', now())
                     ->where('status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now()) && $this->status === 'published';
    }

    public function publish(): self
    {
        $this->update([
            'published_at' => now(),
            'status' => 'published',
        ]);
        return $this;
    }

    public function unpublish(): self
    {
        $this->update([
            'published_at' => null,
            'status' => 'draft',
        ]);
        return $this;
    }

    public function isPromoted(): bool
    {
        return (bool) $this->is_promoted;
    }

    public function promote(): self
    {
        $this->update([
            'is_promoted' => true,
        ]);
        return $this;
    }

    public function unpromote(): self
    {
        $this->update([
            'is_promoted' => false,
        ]);
        return $this;
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        $todayStart = now()->startOfDay();

        return $query->published()->where(function ($q) use ($todayStart) {
            $q->where(function ($sq) use ($todayStart) {
                $sq->whereNotNull('next_start_at')->where('next_start_at', '>=', $todayStart);
            })->orWhere(function ($sq) {
                $sq->whereNotNull('last_end_at')->where('last_end_at', '>=', now());
            })->orWhere(function ($sq) use ($todayStart) {
                // Fallback for unmigrated events
                $sq->whereNull('next_start_at')
                   ->where(function ($sub) use ($todayStart) {
                       $sub->where('start_at', '>=', $todayStart)
                           ->orWhere(function ($s2) {
                               $s2->whereNotNull('end_at')->where('end_at', '>=', now());
                           });
                   });
            });
        })->orderByRaw(
            'is_promoted DESC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN 0 ELSE 1 END ASC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN COALESCE(next_start_at, start_at) ELSE COALESCE(last_end_at, end_at) END ASC, id ASC',
            [$todayStart, $todayStart]
        );
    }

    public function scopeFilterBySource(Builder $query, ?string $sourceSlug): Builder
    {
        if (empty($sourceSlug) || $sourceSlug === 'all') {
            return $query;
        }

        return $query->where('source_slug', $sourceSlug);
    }

    public function scopeWhereOccursBetween(Builder $query, Carbon $rangeStart, Carbon $rangeEnd): Builder
    {
        $startDateStr = $rangeStart->toDateString();
        $endDateStr = $rangeEnd->toDateString();

        return $query->where(function ($q) use ($startDateStr, $endDateStr) {
            // Check active occurrences in v2
            $q->whereExists(function ($sub) use ($startDateStr, $endDateStr) {
                $sub->select(DB::raw(1))
                    ->from('event_occurrences')
                    ->whereColumn('event_occurrences.event_id', 'events.id')
                    ->whereIn('event_occurrences.status', ['scheduled', 'postponed'])
                    ->whereNull('event_occurrences.deleted_at')
                    ->whereDate('event_occurrences.start_at', '<=', $endDateStr)
                    ->where(function ($sq) use ($startDateStr) {
                        $sq->whereDate('event_occurrences.end_at', '>=', $startDateStr)
                           ->orWhere(function ($s2) use ($startDateStr) {
                               $s2->whereNull('event_occurrences.end_at')
                                  ->whereDate('event_occurrences.start_at', '>=', $startDateStr);
                           });
                    });
            })->orWhere(function ($legacyQ) use ($startDateStr, $endDateStr) {
                // Fallback for events without occurrences
                $legacyQ->whereDoesntHave('occurrences')
                    ->whereDate('start_at', '<=', $endDateStr)
                    ->where(function ($sub) use ($startDateStr) {
                        $sub->whereDate('end_at', '>=', $startDateStr)
                            ->orWhere(function ($s2) use ($startDateStr) {
                                $s2->whereNull('end_at')->whereDate('start_at', '>=', $startDateStr);
                            });
                    });
            });
        });
    }

    public function scopeForDateFilter(Builder $query, ?string $period, ?string $exactDate = null): Builder
    {
        $targetDate = $exactDate;
        if (empty($targetDate) && !empty($period) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) {
            $targetDate = $period;
        }

        if (!empty($targetDate)) {
            try {
                $target = Carbon::parse($targetDate);
                $targetStart = $target->copy()->startOfDay();
                $targetEnd = $target->copy()->endOfDay();

                return $query->published()
                    ->whereOccursBetween($targetStart, $targetEnd)
                    ->orderByRaw(
                        'is_promoted DESC, CASE WHEN COALESCE(next_start_at, start_at) >= ? AND COALESCE(next_start_at, start_at) <= ? THEN 0 ELSE 1 END ASC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN COALESCE(next_start_at, start_at) ELSE COALESCE(last_end_at, end_at) END ASC, id ASC',
                        [$targetStart, $targetEnd, $targetStart]
                    );
            } catch (\Throwable $e) {
                // Fallback to period
            }
        }

        if (empty($period) || $period === 'all') {
            return $query->upcoming();
        }

        $now = now();

        return match ($period) {
            'today' => $query->published()
                ->whereOccursBetween($now->copy()->startOfDay(), $now->copy()->endOfDay())
                ->orderByRaw(
                    'is_promoted DESC, CASE WHEN COALESCE(next_start_at, start_at) >= ? AND COALESCE(next_start_at, start_at) <= ? THEN 0 ELSE 1 END ASC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN COALESCE(next_start_at, start_at) ELSE COALESCE(last_end_at, end_at) END ASC, id ASC',
                    [$now->copy()->startOfDay(), $now->copy()->endOfDay(), $now->copy()->startOfDay()]
                ),

            'tomorrow' => $query->published()
                ->whereOccursBetween($now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay())
                ->orderByRaw(
                    'is_promoted DESC, CASE WHEN COALESCE(next_start_at, start_at) >= ? AND COALESCE(next_start_at, start_at) <= ? THEN 0 ELSE 1 END ASC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN COALESCE(next_start_at, start_at) ELSE COALESCE(last_end_at, end_at) END ASC, id ASC',
                    [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay(), $now->copy()->addDay()->startOfDay()]
                ),

            'weekend' => $query->published()
                ->whereOccursBetween($now->copy()->next(Carbon::SATURDAY)->startOfDay(), $now->copy()->next(Carbon::SUNDAY)->endOfDay())
                ->orderByRaw(
                    'is_promoted DESC, CASE WHEN COALESCE(next_start_at, start_at) >= ? AND COALESCE(next_start_at, start_at) <= ? THEN 0 ELSE 1 END ASC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN COALESCE(next_start_at, start_at) ELSE COALESCE(last_end_at, end_at) END ASC, id ASC',
                    [
                        $now->copy()->next(Carbon::SATURDAY)->startOfDay(),
                        $now->copy()->next(Carbon::SUNDAY)->endOfDay(),
                        $now->copy()->next(Carbon::SATURDAY)->startOfDay()
                    ]
                ),

            'this_week' => $query->published()
                ->whereOccursBetween($now->copy()->startOfWeek(), $now->copy()->endOfWeek())
                ->orderByRaw(
                    'is_promoted DESC, CASE WHEN COALESCE(next_start_at, start_at) >= ? AND COALESCE(next_start_at, start_at) <= ? THEN 0 ELSE 1 END ASC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN COALESCE(next_start_at, start_at) ELSE COALESCE(last_end_at, end_at) END ASC, id ASC',
                    [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), $now->copy()->startOfWeek()]
                ),

            'this_month' => $query->published()
                ->whereOccursBetween($now->copy()->startOfMonth(), $now->copy()->endOfMonth())
                ->orderByRaw(
                    'is_promoted DESC, CASE WHEN COALESCE(next_start_at, start_at) >= ? AND COALESCE(next_start_at, start_at) <= ? THEN 0 ELSE 1 END ASC, CASE WHEN COALESCE(next_start_at, start_at) >= ? THEN COALESCE(next_start_at, start_at) ELSE COALESCE(last_end_at, end_at) END ASC, id ASC',
                    [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), $now->copy()->startOfMonth()]
                ),

            default => $query->upcoming(),
        };
    }

    public function scopeFilterByCategory(Builder $query, ?string $categorySlug): Builder
    {
        if (empty($categorySlug) || $categorySlug === 'all') {
            return $query;
        }

        return $query->whereHas('categories', function ($q) use ($categorySlug) {
            $q->where('slug', $categorySlug);
        });
    }

    public function scopeFilterByCity(Builder $query, ?string $city): Builder
    {
        if (empty($city) || $city === 'all') {
            return $query;
        }

        $allCities = Cache::remember('locations_distinct_cities', 3600, function () {
            return Location::query()->distinct()->pluck('city')->filter()->values()->all();
        });

        $inputs = array_map('trim', explode(',', $city));
        $matched = [];

        foreach ($inputs as $in) {
            $inSlug = Str::slug($in);
            $inLower = mb_strtolower($in);

            foreach ($allCities as $c) {
                if (mb_strtolower($c) === $inLower || Str::slug($c) === $inSlug) {
                    $matched[] = $c;
                }
            }
        }

        $matched = array_values(array_unique($matched));

        return $query->whereHas('location', function ($q) use ($matched, $city) {
            if (!empty($matched)) {
                $q->whereIn('city', $matched);
            } else {
                $q->where('city', $city);
            }
        });
    }

    public function scopeFilterByEntertainmentType(Builder $query, ?string $type): Builder
    {
        if (empty($type) || $type === 'all') {
            return $query;
        }

        return $query->where('entertainment_type', $type);
    }

    public function scopeFilterByPrice(Builder $query, ?string $priceFilter): Builder
    {
        if (empty($priceFilter) || $priceFilter === 'all') {
            return $query;
        }

        if ($priceFilter === 'free') {
            return $query->where('is_free', true);
        }

        if ($priceFilter === 'paid') {
            return $query->where('is_free', false);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $search = trim($search);
        $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return $query;
        }

        return $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $terms = [$word];
                $stem = static::extractSearchStem($word);
                if (!empty($stem) && $stem !== $word) {
                    $terms[] = $stem;
                }

                $q->where(function ($subQ) use ($terms) {
                    foreach ($terms as $term) {
                        $likeTerm = '%' . $term . '%';
                        $subQ->orWhere('title', 'like', $likeTerm)
                             ->orWhere('description', 'like', $likeTerm)
                             ->orWhere('short_description', 'like', $likeTerm)
                             ->orWhereHas('translations', function ($transQuery) use ($likeTerm) {
                                 $transQuery->where('title', 'like', $likeTerm)
                                            ->orWhere('description', 'like', $likeTerm)
                                            ->orWhere('short_description', 'like', $likeTerm);
                             })
                             ->orWhereHas('categories', function ($catQuery) use ($likeTerm) {
                                 $catQuery->where('name', 'like', $likeTerm)
                                          ->orWhereHas('translations', function ($ctQuery) use ($likeTerm) {
                                              $ctQuery->where('name', 'like', $likeTerm);
                                          });
                             })
                             ->orWhereHas('location', function ($locQuery) use ($likeTerm) {
                                 $locQuery->where('name', 'like', $likeTerm)
                                          ->orWhere('city', 'like', $likeTerm)
                                          ->orWhere('region', 'like', $likeTerm)
                                          ->orWhereHas('translations', function ($ltQuery) use ($likeTerm) {
                                              $ltQuery->where('name', 'like', $likeTerm)
                                                       ->orWhere('city', 'like', $likeTerm)
                                                       ->orWhere('region', 'like', $likeTerm);
                                          });
                             });
                    }
                });
            }
        });
    }

    public static function extractSearchStem(string $word): string
    {
        $word = mb_strtolower(trim($word));
        if (mb_strlen($word) < 5) {
            return $word;
        }

        // Common Latvian and generic noun/adjective inflections
        $endings = [
            'ajiem', 'ajām', 'ajai', 'ajam', 'iem', 'ām', 'ēm', 'os', 'īs', 'us',
            'am', 'om', 'em', 'im', 'as', 'es', 'is', 'us', 'os', 'ai', 'ei', 'ij',
            's', 'a', 'e', 'i', 'u', 'ā', 'ē', 'ī', 'ū', 'o'
        ];

        foreach ($endings as $ending) {
            $len = mb_strlen($ending);
            if (mb_substr($word, -$len) === $ending) {
                $candidate = mb_substr($word, 0, mb_strlen($word) - $len);
                if (mb_strlen($candidate) >= 4) {
                    return $candidate;
                }
            }
        }

        return $word;
    }

    public function getLocalizedEntertainmentTypeAttribute(): ?string
    {
        if (empty($this->entertainment_type)) {
            return null;
        }

        $locale = app()->getLocale();

        $types = [
            'performance' => [
                'lv' => 'Izrāde',
                'en' => 'Performance',
                'ru' => 'Спектакль',
            ],
            'movie' => [
                'lv' => 'Filma',
                'en' => 'Movie',
                'ru' => 'Фильм',
            ],
            'show' => [
                'lv' => 'Šovs',
                'en' => 'Show',
                'ru' => 'Шоу',
            ],
            'theatre' => [
                'lv' => 'Teātris',
                'en' => 'Theatre',
                'ru' => 'Театр',
            ],
            'festival' => [
                'lv' => 'Festivāls',
                'en' => 'Festival',
                'ru' => 'Фестиваль',
            ],
            'celebration' => [
                'lv' => 'Svētki',
                'en' => 'Celebration',
                'ru' => 'Праздник',
            ],
            'sports' => [
                'lv' => 'Sports',
                'en' => 'Sports',
                'ru' => 'Спорт',
            ],
            'concert' => [
                'lv' => 'Koncerts',
                'en' => 'Concert',
                'ru' => 'Концерт',
            ],
            'chill' => [
                'lv' => 'Atpūta',
                'en' => 'Relaxation',
                'ru' => 'Отдых',
            ],
            'exhibition' => [
                'lv' => 'Izstāde',
                'en' => 'Exhibition',
                'ru' => 'Выставка',
            ],
            'workshop' => [
                'lv' => 'Meistarklase',
                'en' => 'Workshop',
                'ru' => 'Мастер-класс',
            ],
            'active' => [
                'lv' => 'Aktīvā atpūta',
                'en' => 'Active leisure',
                'ru' => 'Активный отдых',
            ],
            'family' => [
                'lv' => 'Ģimenei',
                'en' => 'For families',
                'ru' => 'Для всей семьи',
            ],
            'party' => [
                'lv' => 'Ballīte',
                'en' => 'Party',
                'ru' => 'Вечеринка',
            ],
        ];

        $key = strtolower(trim($this->entertainment_type));

        return $types[$key][$locale] ?? $types[$key]['lv'] ?? __($this->entertainment_type);
    }

    public function getFormattedPriceAttribute(): string
    {
        $locale = app()->getLocale();

        if ($this->is_free) {
            return match($locale) {
                'en' => 'Free',
                'ru' => 'Бесплатно',
                default => 'Bezmaksas',
            };
        }

        $min = $this->price_min !== null ? (float)$this->price_min : null;
        $max = $this->price_max !== null ? (float)$this->price_max : null;

        if ($max !== null && $max <= 0) {
            $max = null;
        }

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $formatNum = function (float $n): string {
            return ($n == (int)$n) ? (string)(int)$n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        };

        if ($min !== null && $max !== null && $min != $max) {
            return "€{$formatNum($min)} - €{$formatNum($max)}";
        }

        if ($min !== null) {
            $from = match($locale) {
                'en' => 'From ',
                'ru' => 'От ',
                default => 'No ',
            };
            return "{$from}€{$formatNum($min)}";
        }

        if ($max !== null) {
            return "€{$formatNum($max)}";
        }

        return match($locale) {
            'en' => 'Price not specified',
            'ru' => 'Цена не указана',
            default => 'Cena nav norādīta',
        };
    }

    public function getFormattedDateAttribute(): string
    {
        if (!$this->start_at) {
            return '';
        }

        $start = $this->start_at;
        $end = $this->end_at;
        $locale = app()->getLocale();
        $now = now();

        $monthsLv = [
            1 => 'janv.', 2 => 'febr.', 3 => 'marts', 4 => 'apr.',
            5 => 'maijs', 6 => 'jūn.', 7 => 'jūl.', 8 => 'aug.',
            9 => 'sept.', 10 => 'okt.', 11 => 'nov.', 12 => 'dec.'
        ];

        $monthsEn = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
        ];

        $monthsRu = [
            1 => 'янв.', 2 => 'февр.', 3 => 'март', 4 => 'апр.',
            5 => 'май', 6 => 'июн.', 7 => 'июл.', 8 => 'авг.',
            9 => 'сент.', 10 => 'окт.', 11 => 'нояб.', 12 => 'дек.'
        ];

        $monthMap = match($locale) {
            'en' => $monthsEn,
            'ru' => $monthsRu,
            default => $monthsLv,
        };

        // If the event started in the past (before today) but is still ongoing until a future date:
        if ($start->lt($now->copy()->startOfDay()) && $end && $end->gte($now)) {
            $endMonth = $monthMap[(int)$end->format('n')] ?? $end->format('M');
            $untilPrefix = match($locale) {
                'en' => 'Until ',
                'ru' => 'До ',
                default => 'Līdz ',
            };

            if ($end->format('Y') !== $now->format('Y')) {
                return $untilPrefix . $end->format('d. ') . $endMonth . ' ' . $end->format('Y');
            }
            return $untilPrefix . $end->format('d. ') . $endMonth;
        }

        $month = $monthMap[(int)$start->format('n')] ?? $start->format('M');
        $dateFormatted = $start->format('d. ') . $month;

        if ($start->format('Y') > $now->format('Y')) {
            $dateFormatted .= ' ' . $start->format('Y');
        }

        if ($this->all_day) {
            return $dateFormatted;
        }

        $timePrefix = match($locale) {
            'en' => ' at ',
            'ru' => ' в ',
            default => ' plkst. ',
        };

        return $dateFormatted . $timePrefix . $start->format('H:i');
    }

    public function getCardFormattedDateAttribute(): string
    {
        if (!$this->start_at) {
            return '';
        }

        $start = $this->start_at;
        $end = $this->end_at;
        $locale = app()->getLocale();
        $now = now();

        $monthsLv = [
            1 => 'janv.', 2 => 'febr.', 3 => 'marts', 4 => 'apr.',
            5 => 'maijs', 6 => 'jūn.', 7 => 'jūl.', 8 => 'aug.',
            9 => 'sept.', 10 => 'okt.', 11 => 'nov.', 12 => 'dec.'
        ];

        $monthsEn = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
        ];

        $monthsRu = [
            1 => 'янв.', 2 => 'февр.', 3 => 'март', 4 => 'апр.',
            5 => 'май', 6 => 'июн.', 7 => 'июл.', 8 => 'авг.',
            9 => 'сент.', 10 => 'окт.', 11 => 'нояб.', 12 => 'дек.'
        ];

        $daysLv = [
            1 => 'Pirmd.', 2 => 'Otrd.', 3 => 'Trešd.', 4 => 'Ceturtd.', 5 => 'Piektd.', 6 => 'Sestd.', 7 => 'Svētd.'
        ];
        $daysEn = [
            1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'
        ];
        $daysRu = [
            1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'
        ];

        $monthMap = match($locale) {
            'en' => $monthsEn,
            'ru' => $monthsRu,
            default => $monthsLv,
        };

        $dayMap = match($locale) {
            'en' => $daysEn,
            'ru' => $daysRu,
            default => $daysLv,
        };

        // Multi-day ongoing event (started before today and still ongoing)
        if ($start->lt($now->copy()->startOfDay()) && $end && $end->gte($now)) {
            $endMonth = $monthMap[(int)$end->format('n')] ?? $end->format('M');
            $untilPrefix = match($locale) {
                'en' => 'Until ',
                'ru' => 'До ',
                default => 'Līdz ',
            };
            $yearStr = ($end->format('Y') !== $now->format('Y')) ? ' ' . $end->format('Y') : '';
            return $untilPrefix . $end->format('j. ') . $endMonth . $yearStr;
        }

        // Multi-day future event
        if ($end && !$start->isSameDay($end)) {
            $startMonth = $monthMap[(int)$start->format('n')] ?? $start->format('M');
            $endMonth = $monthMap[(int)$end->format('n')] ?? $end->format('M');
            $yearStr = ($start->format('Y') > $now->format('Y')) ? ' ' . $start->format('Y') : '';

            if ($start->format('n') === $end->format('n')) {
                return $start->format('j.') . ' – ' . $end->format('j. ') . $startMonth . $yearStr;
            }
            return $start->format('j. ') . $startMonth . ' – ' . $end->format('j. ') . $endMonth . $yearStr;
        }

        // Single day event
        $month = $monthMap[(int)$start->format('n')] ?? $start->format('M');
        $yearStr = ($start->format('Y') > $now->format('Y')) ? ' ' . $start->format('Y') : '';

        if ($start->isToday()) {
            $prefix = match($locale) {
                'en' => 'Today',
                'ru' => 'Сегодня',
                default => 'Šodien',
            };
            return $prefix . ' · ' . $start->format('j. ') . $month . $yearStr;
        }

        if ($start->isTomorrow()) {
            $prefix = match($locale) {
                'en' => 'Tomorrow',
                'ru' => 'Завтра',
                default => 'Rīt',
            };
            return $prefix . ' · ' . $start->format('j. ') . $month . $yearStr;
        }

        $dayName = $dayMap[(int)$start->format('N')] ?? '';
        return $dayName . ' · ' . $start->format('j. ') . $month . $yearStr;
    }

    public function getOriginUrlAttribute(): ?string
    {
        return $this->source_url ?: $this->ticket_url;
    }

    public function getOriginHostAttribute(): ?string
    {
        $url = $this->origin_url;
        if (empty($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Scope query to events accessible in a given language:
     * - language_free events (no language barrier)
     * - events where spoken_languages contains the code
     * - events where subtitle_languages contains the code
     */
    public function scopeAccessibleInLanguage(Builder $query, string $languageCode): Builder
    {
        $normalized = EventLanguageService::normalizeCodes([$languageCode]);
        if (empty($normalized)) {
            return $query->whereRaw('1 = 0');
        }

        $code = $normalized[0];
        $isMysql = \Illuminate\Support\Facades\DB::getDriverName() === 'mysql';

        return $query->where(function (Builder $q) use ($code, $isMysql) {
            $q->where('language_mode', EventLanguageMode::LanguageFree);

            if ($isMysql) {
                $q->orWhereRaw('JSON_CONTAINS(spoken_languages, ?)', [json_encode($code)])
                  ->orWhereRaw('JSON_CONTAINS(subtitle_languages, ?)', [json_encode($code)]);
            } else {
                $q->orWhereJsonContains('spoken_languages', $code)
                  ->orWhereJsonContains('subtitle_languages', $code);
            }

            // Also check session level occurrences in v2
            $q->orWhereHas('occurrences', function ($oq) use ($code, $isMysql) {
                $oq->whereIn('status', ['scheduled', 'postponed']);
                if ($isMysql) {
                    $oq->where(function ($sub) use ($code) {
                        $sub->whereRaw('JSON_CONTAINS(spoken_languages, ?)', [json_encode($code)])
                            ->orWhereRaw('JSON_CONTAINS(subtitle_languages, ?)', [json_encode($code)]);
                    });
                } else {
                    $oq->where(function ($sub) use ($code) {
                        $sub->whereJsonContains('spoken_languages', $code)
                            ->orWhereJsonContains('subtitle_languages', $code);
                    });
                }
            });
        });
    }
}
