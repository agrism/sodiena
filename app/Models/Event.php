<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
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
        'views_count',
        'raw_data',
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
        'views_count' => 'integer',
        'raw_data' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'published_at' => null,
        'all_day' => false,
        'is_free' => false,
        'currency' => 'EUR',
    ];

    protected static function booted(): void
    {
        static::creating(function ($event) {
            if (empty($event->slug)) {
                $event->slug = Str::slug($event->title) . '-' . Str::random(6);
            }
            if (empty($event->fingerprint)) {
                $dateStr = $event->start_at ? $event->start_at->format('Y-m-d') : '';
                $event->fingerprint = md5(mb_strtolower(trim($event->title)) . '|' . $dateStr . '|' . ($event->location_id ?? ''));
            }
            if (empty($event->source_slug) && $event->source_id) {
                $source = Source::find($event->source_id);
                if ($source) {
                    $event->source_slug = $source->slug;
                }
            }
        });
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

    public function getDisplayImageUrlAttribute(): string
    {
        if (!empty($this->internal_image_url)) {
            return $this->internal_image_url;
        }

        if (!empty($this->image_url) && !str_contains($this->image_url, 'aplis-default-og-img.jpg')) {
            return $this->image_url;
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

    public function extractRealTicketUrl(): ?string
    {
        $text = ($this->attributes['description'] ?? '') . ' ' . json_encode($this->raw_data ?? []);
        if (empty(trim($text))) {
            return null;
        }

        preg_match_all('/https?:\/\/[^\s\)\"\'<>]+/i', $text, $matches);
        $ticketPlatforms = [
            'bilesuparadize.lv', 'bilesuserviss.lv', 'bezrindas.lv', 'ticketshop.lv',
            'aula.lv', 'fienta.com', 'apollokino.lv', 'forumcinemas.lv', 'splendidpalace.lv',
            'cinamonkino.com', 'opera.lv', 'passportix.eu', 'ticketbest.eu', 'ticketly.eu',
            'forms.gle', 'docs.google.com/forms', 'tally.so', 'distantrace.com',
            'play.fiba3x3.com', 'cuescore.com'
        ];

        foreach ($matches[0] as $rawUrl) {
            $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $rawUrl);
            $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

            if (str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                continue;
            }

            foreach ($ticketPlatforms as $platform) {
                if (str_contains($cleanUrl, $platform)) {
                    return $cleanUrl;
                }
            }
        }

        return null;
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

    public function scopeUpcoming(Builder $query): Builder
    {
        $todayStart = now()->startOfDay();

        return $query->published()->where(function ($q) use ($todayStart) {
            $q->where('start_at', '>=', $todayStart)
              ->orWhere(function ($sub) {
                  $sub->whereNotNull('end_at')->where('end_at', '>=', now());
              });
        })->orderByRaw(
            'CASE WHEN start_at >= ? THEN 0 ELSE 1 END ASC, CASE WHEN start_at >= ? THEN start_at ELSE end_at END ASC, id ASC',
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

    public function scopeForDateFilter(Builder $query, ?string $period, ?string $exactDate = null): Builder
    {
        if (!empty($exactDate)) {
            try {
                $target = Carbon::parse($exactDate);
                $targetDateStr = $target->toDateString();
                $targetStart = $target->copy()->startOfDay();
                $targetEnd = $target->copy()->endOfDay();

                return $query->published()->where(function ($q) use ($targetDateStr) {
                    $q->whereDate('start_at', '<=', $targetDateStr)
                      ->where(function ($sub) use ($targetDateStr) {
                          $sub->whereDate('end_at', '>=', $targetDateStr)
                              ->orWhere(function ($s2) use ($targetDateStr) {
                                  $s2->whereNull('end_at')
                                     ->whereDate('start_at', $targetDateStr);
                              });
                      });
                })->orderByRaw(
                    'CASE WHEN start_at >= ? AND start_at <= ? THEN 0 ELSE 1 END ASC, CASE WHEN start_at >= ? THEN start_at ELSE end_at END ASC, id ASC',
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
                ->where(function ($q) use ($now) {
                    $todayStr = $now->toDateString();
                    $q->whereDate('start_at', $todayStr)
                      ->orWhere(function ($sub) use ($todayStr) {
                          $sub->whereDate('start_at', '<=', $todayStr)
                              ->whereDate('end_at', '>=', $todayStr);
                      });
                })
                ->orderByRaw(
                    'CASE WHEN start_at >= ? AND start_at <= ? THEN 0 ELSE 1 END ASC, CASE WHEN start_at >= ? THEN start_at ELSE end_at END ASC, id ASC',
                    [$now->copy()->startOfDay(), $now->copy()->endOfDay(), $now->copy()->startOfDay()]
                ),

            'tomorrow' => $query->published()
                ->where(function ($q) use ($now) {
                    $tomStr = $now->copy()->addDay()->toDateString();
                    $q->whereDate('start_at', $tomStr)
                      ->orWhere(function ($sub) use ($tomStr) {
                          $sub->whereDate('start_at', '<=', $tomStr)
                              ->whereDate('end_at', '>=', $tomStr);
                      });
                })
                ->orderByRaw(
                    'CASE WHEN start_at >= ? AND start_at <= ? THEN 0 ELSE 1 END ASC, CASE WHEN start_at >= ? THEN start_at ELSE end_at END ASC, id ASC',
                    [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay(), $now->copy()->addDay()->startOfDay()]
                ),

            'weekend' => $query->published()
                ->where(function ($q) use ($now) {
                    $sat = $now->copy()->next(Carbon::SATURDAY)->startOfDay();
                    $sun = $now->copy()->next(Carbon::SUNDAY)->endOfDay();
                    $q->whereBetween('start_at', [$sat, $sun])
                      ->orWhere(function ($sub) use ($sat, $sun) {
                          $sub->where('start_at', '<=', $sun)
                              ->where('end_at', '>=', $sat);
                      });
                })
                ->orderByRaw(
                    'CASE WHEN start_at >= ? AND start_at <= ? THEN 0 ELSE 1 END ASC, CASE WHEN start_at >= ? THEN start_at ELSE end_at END ASC, id ASC',
                    [
                        $now->copy()->next(Carbon::SATURDAY)->startOfDay(),
                        $now->copy()->next(Carbon::SUNDAY)->endOfDay(),
                        $now->copy()->next(Carbon::SATURDAY)->startOfDay()
                    ]
                ),

            'this_week' => $query->published()
                ->where(function ($q) use ($now) {
                    $start = $now->copy()->startOfWeek();
                    $end = $now->copy()->endOfWeek();
                    $q->whereBetween('start_at', [$start, $end])
                      ->orWhere(function ($sub) use ($start, $end) {
                          $sub->where('start_at', '<=', $end)
                              ->where('end_at', '>=', $start);
                      });
                })
                ->orderByRaw(
                    'CASE WHEN start_at >= ? AND start_at <= ? THEN 0 ELSE 1 END ASC, CASE WHEN start_at >= ? THEN start_at ELSE end_at END ASC, id ASC',
                    [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), $now->copy()->startOfWeek()]
                ),

            'this_month' => $query->published()
                ->where(function ($q) use ($now) {
                    $start = $now->copy()->startOfMonth();
                    $end = $now->copy()->endOfMonth();
                    $q->whereBetween('start_at', [$start, $end])
                      ->orWhere(function ($sub) use ($start, $end) {
                          $sub->where('start_at', '<=', $end)
                              ->where('end_at', '>=', $start);
                      });
                })
                ->orderByRaw(
                    'CASE WHEN start_at >= ? AND start_at <= ? THEN 0 ELSE 1 END ASC, CASE WHEN start_at >= ? THEN start_at ELSE end_at END ASC, id ASC',
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

        return $query->whereHas('location', function ($q) use ($city) {
            $q->where('city', $city);
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
        if (mb_strlen($word) < 4) {
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
                if (mb_strlen($candidate) >= 3) {
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

        if ($this->price_min && $this->price_max && $this->price_min != $this->price_max) {
            return "€{$this->price_min} - €{$this->price_max}";
        }

        if ($this->price_min) {
            $from = match($locale) {
                'en' => 'From ',
                'ru' => 'От ',
                default => 'No ',
            };
            return "{$from}€{$this->price_min}";
        }

        if ($this->price_max) {
            return "€{$this->price_max}";
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
}
