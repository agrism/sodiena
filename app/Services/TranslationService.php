<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    /**
     * Translate text from source language to target language (lv, en, ru).
     */
    public function translate(?string $text, string $from, string $to): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);
        if ($text === '' || $from === $to) {
            return $text;
        }

        $cacheKey = 'trans_' . md5("{$from}_{$to}_{$text}");
        return Cache::remember($cacheKey, 86400 * 30, function () use ($text, $from, $to) {
            if (mb_strlen($text, 'UTF-8') > 2500) {
                return $this->translateLongText($text, $from, $to);
            }

            return $this->translateChunk($text, $from, $to) ?: $text;
        });
    }

    /**
     * Translate longer text by bundling lines/paragraphs into ~2000 char blocks.
     */
    private function translateLongText(string $text, string $from, string $to): string
    {
        $lines = explode("\n", $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($lines as $line) {
            if (mb_strlen($currentChunk . "\n" . $line, 'UTF-8') > 2000 && !empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = $line;
            } else {
                $currentChunk = empty($currentChunk) ? $line : $currentChunk . "\n" . $line;
            }
        }
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        $translatedChunks = [];
        foreach ($chunks as $c) {
            $translatedChunks[] = $this->translateChunk($c, $from, $to) ?: $c;
        }

        return implode("\n", $translatedChunks);
    }

    /**
     * Translate a text chunk via Google dict-chrome-ex API with fallbacks.
     */
    public function translateChunk(string $text, string $from, string $to): ?string
    {
        // 1. Primary: Google translation endpoint
        try {
            $res = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
                ])
                ->get('https://translate.googleapis.com/translate_a/t', [
                    'client' => 'dict-chrome-ex',
                    'sl' => $from,
                    'tl' => $to,
                    'q' => $text,
                ]);

            if ($res->successful()) {
                $data = $res->json();
                if (is_array($data) && !empty($data[0])) {
                    return is_array($data[0]) ? implode(' ', $data[0]) : (string) $data[0];
                }
                if (is_string($data) && !empty($data)) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Primary Google translation API failed for {$from}->{$to}: " . $e->getMessage());
        }

        // 2. Secondary fallback: Disroot LibreTranslate
        try {
            $res = Http::timeout(6)
                ->post('https://translate.disroot.org/translate', [
                    'q' => $text,
                    'source' => $from,
                    'target' => $to,
                    'format' => 'text'
                ]);

            if ($res->successful() && !empty($res->json('translatedText'))) {
                return (string) $res->json('translatedText');
            }
        } catch (\Throwable $e) {
            // ignore and continue to next fallback
        }

        // 3. Third fallback: MyMemory API
        $langPair = "{$from}|{$to}";
        try {
            $res = Http::timeout(6)
                ->get('https://api.mymemory.translated.net/get', [
                    'q' => $text,
                    'langpair' => $langPair,
                ]);

            if ($res->successful()) {
                $data = $res->json();
                $translated = $data['responseData']['translatedText'] ?? null;
                if (!empty($translated) && !str_starts_with($translated, 'MYMEMORY WARNING:')) {
                    return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Translation fallback failed for {$langPair}: " . $e->getMessage());
        }

        return null;
    }
}
