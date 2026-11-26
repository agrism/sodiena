<?php

namespace App\Services\Language;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Services\EventLanguageService;

class EventLanguageTextAnalyzer
{
    /**
     * Allowed uppercase language tags in titles (e.g., (LV), [EN/RU]).
     */
    private const TITLE_TAG_CODES = 'LV|EN|ENG|RU|RUS|DE|FR|UA|UK|LT|ET';

    /**
     * Analyze event title and descriptions to infer language information.
     */
    public static function analyze(?string $title, ?string $shortDescription = null, ?string $description = null): ?EventLanguageData
    {
        $titleClean = trim((string) $title);
        $fullText = trim($titleClean . "\n" . (string) $shortDescription . "\n" . (string) $description);

        if ($fullText === '') {
            return null;
        }

        // Clean HTML, decode entities, and normalize whitespace
        $cleanText = html_entity_decode($fullText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleanText = strip_tags($cleanText);
        $cleanText = preg_replace('/[^\S\r\n]+/u', ' ', $cleanText);

        $workingText = $cleanText;

        // -------------------------------------------------------------
        // Step A — Negations and metaphor masking
        // -------------------------------------------------------------

        // 1. Subtitle negations
        $noSubtitlesPattern = '/\b(?:bez\s+subtitr\w*|bez\s+titriem|bez\s+tulkojuma|without\s+subtitles|no\s+subtitles|без\s+субтитров)\b/ui';
        $noSubtitlesExplicit = (bool) preg_match($noSubtitlesPattern, $workingText);
        if ($noSubtitlesExplicit) {
            $workingText = preg_replace($noSubtitlesPattern, ' ', $workingText);
        }

        // 2. Metaphorical & educational phrases
        $metaphorPattern = '/(?:mūzikas|ķermeņa|dejas|mākslas|ziedu|krāsu|kino|teātra|gaismas|sirds|mīlestības|dabas|dzīvnieku|putnu|zīmes|zīmju)\s+valod\w*/ui';
        $workingText = preg_replace($metaphorPattern, ' ', $workingText);

        $holidayPattern = '/(?:dzimtās\s+)?valodas\s+(?:dien\w*|svētk\w*)|valodu\s+dien\w*/ui';
        $workingText = preg_replace($holidayPattern, ' ', $workingText);

        $learningPattern = '/(?:mācīties|apgūt|izkopt)\s+\w+\s+valod\w*/ui';
        $workingText = preg_replace($learningPattern, ' ', $workingText);

        // -------------------------------------------------------------
        // Step B — Explicit non-verbal signals
        // -------------------------------------------------------------
        $nonVerbalPattern = '/\b(?:bez\s+vārdiem|bez\s+teksta|neverbāl\w*\s+izrād\w*|kustību\s+izrād\w*|pantomīm\w*|without\s+words|wordless|no\s+dialogue|non-?verbal|без\s+слов|без\s+текста)\b/ui';
        $isNonVerbal = (bool) preg_match($nonVerbalPattern, $workingText);

        // -------------------------------------------------------------
        // Step C — Subtitles / surtitles extraction (BEFORE spoken language)
        // -------------------------------------------------------------
        $subtitles = [];

        if (!$noSubtitlesExplicit) {
            // Pattern C1: Subtitle keyword followed by languages
            // e.g. "ar subtitriem latviešu un krievu valodā", "ar subtitirem angļu valodā", "subtitri: LV, RU", "sinhronais tulkojums angļu valodā"
            $c1Pattern = '/\b(?:ar\s+)?(?:subtit\w*|virstitr\w*|titr\w*|sinhron\w*\s+tulkojum\w*|tulkojum\w*)\s*(?:[:\-–]|ar|uz)?/ui';
            if (preg_match_all($c1Pattern, $workingText, $matches, PREG_OFFSET_CAPTURE)) {
                // Iterate backwards by offset to safely replace substrings
                $matchesReversed = array_reverse($matches[0]);
                foreach ($matchesReversed as $matchPair) {
                    $matchStr = $matchPair[0];
                    $offset = $matchPair[1];
                    $afterOffset = $offset + strlen($matchStr);
                    $afterText = substr($workingText, $afterOffset);
                    $parsed = self::extractLanguagesAfterKeywordWithLength($afterText);
                    if (!empty($parsed['codes']) && $parsed['end_offset'] !== null) {
                        $subtitles = array_merge($subtitles, $parsed['codes']);
                        // Check if trailing "valodā" or "val." should be absorbed
                        $rest = substr($afterText, $parsed['end_offset']);
                        $trailingVal = '';
                        if (preg_match('/^(?:\s+(?:valodā|valodas|valodās|val\.))\b/ui', $rest, $valMatch)) {
                            $trailingVal = $valMatch[0];
                        }
                        $phraseLen = strlen($matchStr) + $parsed['end_offset'] + strlen($trailingVal);
                        $workingText = substr_replace($workingText, str_repeat(' ', $phraseLen), $offset, $phraseLen);
                    }
                }
            }

            // Pattern C2: Languages before subtitle keyword
            // e.g. "ar angļu un latviešu subtitriem", "ar angļu valodas titriem", "LV/EN titri", "latviešu un angļu titriem"
            $c2Pattern = '/\b(subtit\w*|virstitr\w*|titr\w*)\b/ui';
            if (preg_match_all($c2Pattern, $workingText, $matches, PREG_OFFSET_CAPTURE)) {
                $matchesReversed = array_reverse($matches[0]);
                foreach ($matchesReversed as $matchPair) {
                    $matchStr = $matchPair[0];
                    $kwOffset = $matchPair[1];
                    $beforeText = substr($workingText, 0, $kwOffset);
                    $parsed = self::extractLanguagesBeforeKeywordWithLength($beforeText);
                    if (!empty($parsed['codes']) && $parsed['start_offset'] !== null) {
                        $subtitles = array_merge($subtitles, $parsed['codes']);
                        // Check if "ar " was directly before the first language token
                        $leading = substr($workingText, 0, $parsed['start_offset']);
                        $phraseStart = $parsed['start_offset'];
                        if (preg_match('/(?:ar\s+)$/ui', $leading, $arMatch)) {
                            $phraseStart -= strlen($arMatch[0]);
                        }
                        $phraseLen = ($kwOffset + strlen($matchStr)) - $phraseStart;
                        $workingText = substr_replace($workingText, str_repeat(' ', $phraseLen), $phraseStart, $phraseLen);
                    }
                }
            }

            // Pattern C3: English patterns
            // e.g. "with English subtitles", "subtitles in English"
            $c3Pattern = '/\b(?:subtitles|surtitles|captions|translation)\s*(?:in\s+|:\s*)?/ui';
            if (preg_match_all($c3Pattern, $workingText, $matches, PREG_OFFSET_CAPTURE)) {
                $matchesReversed = array_reverse($matches[0]);
                foreach ($matchesReversed as $matchPair) {
                    $matchStr = $matchPair[0];
                    $offset = $matchPair[1];
                    $afterText = substr($workingText, $offset + strlen($matchStr));
                    $parsed = self::extractLanguagesAfterKeywordWithLength($afterText);
                    if (!empty($parsed['codes']) && $parsed['end_offset'] !== null) {
                        $subtitles = array_merge($subtitles, $parsed['codes']);
                        $phraseLen = strlen($matchStr) + $parsed['end_offset'];
                        $workingText = substr_replace($workingText, str_repeat(' ', $phraseLen), $offset, $phraseLen);
                    }
                }
            }

            // Pattern C4: Russian patterns
            // e.g. "с русскими субтитрами", "субтитры на русском"
            $c4Pattern = '/\bсубтитры\s*(?:на\s+|:\s*)?/ui';
            if (preg_match_all($c4Pattern, $workingText, $matches, PREG_OFFSET_CAPTURE)) {
                $matchesReversed = array_reverse($matches[0]);
                foreach ($matchesReversed as $matchPair) {
                    $matchStr = $matchPair[0];
                    $offset = $matchPair[1];
                    $afterText = substr($workingText, $offset + strlen($matchStr));
                    $parsed = self::extractLanguagesAfterKeywordWithLength($afterText);
                    if (!empty($parsed['codes']) && $parsed['end_offset'] !== null) {
                        $subtitles = array_merge($subtitles, $parsed['codes']);
                        $phraseLen = strlen($matchStr) + $parsed['end_offset'];
                        $workingText = substr_replace($workingText, str_repeat(' ', $phraseLen), $offset, $phraseLen);
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // Step D — Spoken language extraction from remaining text
        // -------------------------------------------------------------
        $spoken = [];

        // Pattern D1: LV locative e.g. "angļu, itāļu, nīderlandiešu un franču valodā", "Izrāde krievu valodā", "Lekcija angļu val."
        $d1Pattern = '/([^.!?;\n\(\)]+?)\s+(?:valodā|val\.)\b/ui';
        if (preg_match_all($d1Pattern, $workingText, $matches, PREG_OFFSET_CAPTURE)) {
            $matchesReversed = array_reverse($matches[0]);
            foreach ($matchesReversed as $idx => $matchPair) {
                $matchStr = $matchPair[0];
                $offset = $matchPair[1];
                $beforeText = $matches[1][count($matches[0]) - 1 - $idx][0];
                $parsed = self::extractLanguagesBeforeKeywordWithLength($beforeText);
                if (!empty($parsed['codes'])) {
                    $spoken = array_merge($spoken, $parsed['codes']);
                    $workingText = substr_replace($workingText, str_repeat(' ', strlen($matchStr)), $offset, strlen($matchStr));
                }
            }
        }

        // Pattern D2: LV adverb in context
        // e.g. "runās latviešu valodā", "runās latviski", "notiek latviski", "stand-up angliski"
        $d2Pattern = '/(?:notiek|notiks|runā|runās|dublēt\w*|ieskaņot\w*|izrāde|lekcija|seminārs|ekskursija|darbnīca|stand-up)\s+[^.!?;\n]{0,60}?\b(latviski|angliski|krieviski|vāciski|franciski|itāliski|spāniski|ukrainiski|lietuviski|igauniski|poliski)\b/ui';
        if (preg_match_all($d2Pattern, $workingText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $resolved = LanguageDictionary::resolve($match[1]);
                if ($resolved) {
                    $spoken[] = $resolved;
                }
            }
        }

        // Pattern D3: Structured label
        // e.g. "Pasākuma valoda: latviešu, angļu", "Valoda: LV/EN"
        $d3Pattern = '/\b(?:pasākuma\s+|norises\s+|izrādes\s+|filmas\s+|darba\s+)?valoda\s*[:\-–]\s*([^\n.!;()]+)/ui';
        if (preg_match_all($d3Pattern, $workingText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parsed = self::extractLanguagesAfterKeywordWithLength($match[1]);
                if (!empty($parsed['codes'])) {
                    $spoken = array_merge($spoken, $parsed['codes']);
                }
            }
        }

        // Pattern D4: Parenthesis tag strictly in title
        // e.g. "Movie Title (LV/EN)", "Show [RU]"
        $tagCodes = self::TITLE_TAG_CODES;
        $d4Pattern = '/[\(\[]\s*((?:' . $tagCodes . ')(?:\s*[\/,|]\s*(?:' . $tagCodes . '))*)\s*[\)\]]/u';
        if (preg_match($d4Pattern, $titleClean, $match)) {
            $resolved = LanguageDictionary::resolveMultiple($match[1]);
            if (!empty($resolved)) {
                $spoken = array_merge($spoken, $resolved);
            }
        }

        // Pattern D5: English patterns
        // e.g. "Stand-up comedy in English", "Language: English, Latvian"
        $d5Pattern = '/\b(?:in|language\s*[:\-–])\s+([a-zA-Z\s,\/;&|+]+?)(?=[.!;?\n\(\)]|$)/ui';
        if (preg_match_all($d5Pattern, $workingText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parsed = self::extractLanguagesAfterKeywordWithLength($match[1]);
                if (!empty($parsed['codes'])) {
                    $spoken = array_merge($spoken, $parsed['codes']);
                }
            }
        }

        // Pattern D6: Russian patterns
        // e.g. "Спектакль на русском языке", "на русском", "язык: русский"
        $d6Pattern = '/\b(?:на\s+([а-яёА-ЯЁ\s,\/;&|+и]+?)(?:\s+языке)?|язык\s*[:\-–]\s*([а-яёА-ЯЁ\s,\/;&|+и]+))\b/ui';
        if (preg_match_all($d6Pattern, $workingText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $langStr = !empty($match[1]) ? $match[1] : ($match[2] ?? '');
                $parsed = self::extractLanguagesAfterKeywordWithLength($langStr);
                if (!empty($parsed['codes'])) {
                    $spoken = array_merge($spoken, $parsed['codes']);
                }
            }
        }

        // -------------------------------------------------------------
        // Step E — Return results
        // -------------------------------------------------------------
        $spoken = EventLanguageService::normalizeCodes($spoken);
        $subtitles = EventLanguageService::normalizeCodes($subtitles);

        if (!empty($spoken) || !empty($subtitles)) {
            return new EventLanguageData(
                mode: EventLanguageMode::Spoken,
                spokenLanguages: $spoken,
                subtitleLanguages: $subtitles,
                source: EventLanguageSource::Inferred,
            );
        }

        if ($isNonVerbal) {
            return new EventLanguageData(
                mode: EventLanguageMode::LanguageFree,
                spokenLanguages: [],
                subtitleLanguages: [],
                source: EventLanguageSource::Inferred,
            );
        }

        return null;
    }

    /**
     * Scan tokens backwards from right to left before a keyword (e.g. before "valodā" or before "subtitriem").
     * Stops as soon as a non-language, non-connector token is reached.
     *
     * @return array{codes: array<int, string>, start_offset: ?int}
     */
    public static function extractLanguagesBeforeKeywordWithLength(string $textBefore): array
    {
        preg_match_all('/[a-zA-ZāčēģīķļņšūžĀČĒĢĪĶĻŅŠŪŽа-яёА-ЯЁ0-9]+|[,\\/;&|+]/u', $textBefore, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0] ?? [];
        $reversed = array_reverse($tokens);
        $matchedCodes = [];
        $expectSeparator = false;
        $firstTokenOffset = null;

        foreach ($reversed as $pair) {
            $token = $pair[0];
            $offset = $pair[1];
            $tokenLower = mb_strtolower(trim($token), 'UTF-8');
            if ($expectSeparator) {
                if (in_array($tokenLower, [',', '/', ';', '&', '|', '+', 'un', 'and', 'и'], true)) {
                    $expectSeparator = false;
                    $firstTokenOffset = $offset;
                    continue;
                } else {
                    break;
                }
            }
            $code = LanguageDictionary::resolve($token);
            if ($code !== null) {
                $matchedCodes[] = $code;
                $expectSeparator = true;
                $firstTokenOffset = $offset;
            } else {
                break;
            }
        }

        return [
            'codes' => EventLanguageService::normalizeCodes($matchedCodes),
            'start_offset' => $firstTokenOffset,
        ];
    }

    /**
     * Scan tokens forwards from left to right after a keyword (e.g. after "ar subtitriem", "in", "valoda:").
     * Stops as soon as a non-language, non-connector token is reached.
     *
     * @return array{codes: array<int, string>, end_offset: ?int}
     */
    public static function extractLanguagesAfterKeywordWithLength(string $textAfter): array
    {
        preg_match_all('/[a-zA-ZāčēģīķļņšūžĀČĒĢĪĶĻŅŠŪŽа-яёА-ЯЁ0-9]+|[,\\/;&|+:]/u', $textAfter, $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0] ?? [];
        $matchedCodes = [];
        $expectSeparator = false;
        $lastTokenEnd = null;

        foreach ($tokens as $pair) {
            $token = $pair[0];
            $offset = $pair[1];
            $tokenLower = mb_strtolower(trim($token), 'UTF-8');
            if ($tokenLower === ':' && empty($matchedCodes)) {
                continue;
            }
            if ($expectSeparator) {
                if (in_array($tokenLower, [',', '/', ';', '&', '|', '+', 'un', 'and', 'и'], true)) {
                    $expectSeparator = false;
                    $lastTokenEnd = $offset + strlen($token);
                    continue;
                } else {
                    break;
                }
            }
            $code = LanguageDictionary::resolve($token);
            if ($code !== null) {
                $matchedCodes[] = $code;
                $expectSeparator = true;
                $lastTokenEnd = $offset + strlen($token);
            } else {
                break;
            }
        }

        return [
            'codes' => EventLanguageService::normalizeCodes($matchedCodes),
            'end_offset' => $lastTokenEnd,
        ];
    }

    /**
     * Check if text contains ambiguous dubbing signals with multiple languages (e.g. "dublēta latviešu un krievu valodā").
     */
    public static function hasAmbiguousDubbingSignal(?string $text): bool
    {
        if (empty($text)) {
            return false;
        }

        if (preg_match('/\b(?:dublēt\w*|ieskaņot\w*)\b/ui', $text)) {
            // Check if more than one language is mentioned in the text
            $detected = self::analyze('', null, $text);
            if ($detected && count($detected->spokenLanguages ?? []) > 1) {
                return true;
            }
        }

        return false;
    }
}
