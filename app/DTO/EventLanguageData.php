<?php

namespace App\DTO;

use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Models\Event;
use App\Services\EventLanguageService;
use Illuminate\Support\Facades\Log;

readonly class EventLanguageData
{
    public function __construct(
        public ?EventLanguageMode $mode = null,
        public ?array $spokenLanguages = null,
        public ?array $subtitleLanguages = null,
        public EventLanguageSource $source = EventLanguageSource::Unknown,
    ) {}

    public static function fromArray(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        $rawMode = $payload['mode'] ?? $payload['language_mode'] ?? null;
        $mode = null;
        if ($rawMode instanceof EventLanguageMode) {
            $mode = $rawMode;
        } elseif (is_string($rawMode)) {
            $mode = EventLanguageMode::tryFrom(trim($rawMode));
        }

        $spoken = $payload['spoken'] ?? $payload['spoken_languages'] ?? null;
        $subtitles = $payload['subtitles'] ?? $payload['subtitle_languages'] ?? null;

        $rawSource = $payload['source'] ?? $payload['language_source'] ?? null;
        $source = EventLanguageSource::Unknown;
        if ($rawSource instanceof EventLanguageSource) {
            $source = $rawSource;
        } elseif (is_string($rawSource)) {
            $source = EventLanguageSource::tryFrom(trim($rawSource)) ?? EventLanguageSource::Unknown;
        }

        $instance = new self(
            mode: $mode,
            spokenLanguages: is_array($spoken) ? $spoken : null,
            subtitleLanguages: is_array($subtitles) ? $subtitles : null,
            source: $source,
        );

        return EventLanguageService::normalizePayload($instance);
    }

    public static function fromScraperArray(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        $rawSource = $payload['source'] ?? $payload['language_source'] ?? null;
        $isManual = ($rawSource === EventLanguageSource::Manual || (is_string($rawSource) && trim($rawSource) === 'manual'));

        if ($isManual) {
            Log::warning('Scraper attempted to inject manual language source; downgrading to inferred', [
                'payload' => $payload,
            ]);
            $payload['source'] = EventLanguageSource::Inferred->value;
        }

        $data = self::fromArray($payload);
        if ($data && $data->source === EventLanguageSource::Manual) {
            return new self(
                mode: $data->mode,
                spokenLanguages: $data->spokenLanguages,
                subtitleLanguages: $data->subtitleLanguages,
                source: EventLanguageSource::Inferred,
            );
        }

        return $data;
    }

    public static function fromManualInput(
        EventLanguageMode $mode,
        ?array $spoken = null,
        ?array $subtitles = null
    ): self {
        $instance = new self(
            mode: $mode,
            spokenLanguages: $spoken,
            subtitleLanguages: $subtitles,
            source: EventLanguageSource::Manual,
        );

        return EventLanguageService::normalizePayload($instance);
    }

    public static function fromEvent(Event $event): self
    {
        $instance = new self(
            mode: $event->language_mode ?? EventLanguageMode::Unknown,
            spokenLanguages: $event->spoken_languages ?? [],
            subtitleLanguages: $event->subtitle_languages ?? [],
            source: $event->language_source ?? EventLanguageSource::Unknown,
        );

        return EventLanguageService::normalizePayload($instance);
    }

    public function toArray(): array
    {
        return [
            'mode' => $this->mode?->value ?? EventLanguageMode::Unknown->value,
            'spoken' => $this->spokenLanguages ?? [],
            'subtitles' => $this->subtitleLanguages ?? [],
            'source' => $this->source->value,
        ];
    }
}
