<?php

namespace App\Services\Language;

use App\Services\EventLanguageService;

class LanguageDictionary
{
    /**
     * Map of normalized tokens (lowercase, stripped) directly to canonical ISO 639-1 code.
     */
    private const DIRECT_MAP = [
        // Latvian: lv
        'latviešu' => 'lv',
        'latviski' => 'lv',
        'latvian' => 'lv',
        'lv' => 'lv',
        'lat' => 'lv',
        'lav' => 'lv',

        // English: en
        'angļu' => 'en',
        'angliski' => 'en',
        'english' => 'en',
        'en' => 'en',
        'eng' => 'en',

        // Russian: ru
        'krievu' => 'ru',
        'krieviski' => 'ru',
        'russian' => 'ru',
        'ru' => 'ru',
        'rus' => 'ru',

        // German: de
        'vācu' => 'de',
        'vāciski' => 'de',
        'german' => 'de',
        'de' => 'de',
        'deu' => 'de',
        'ger' => 'de',

        // French: fr
        'franču' => 'fr',
        'franciski' => 'fr',
        'french' => 'fr',
        'fr' => 'fr',
        'fra' => 'fr',
        'fre' => 'fr',

        // Italian: it
        'itāļu' => 'it',
        'itāliski' => 'it',
        'italian' => 'it',
        'it' => 'it',
        'ita' => 'it',

        // Spanish: es
        'spāņu' => 'es',
        'spāniski' => 'es',
        'spanish' => 'es',
        'es' => 'es',
        'spa' => 'es',

        // Ukrainian: uk
        'ukraiņu' => 'uk',
        'ukrainiski' => 'uk',
        'ukrainian' => 'uk',
        'uk' => 'uk',
        'ua' => 'uk',
        'ukr' => 'uk',

        // Lithuanian: lt
        'lietuviešu' => 'lt',
        'lietuviski' => 'lt',
        'lithuanian' => 'lt',
        'lt' => 'lt',
        'lit' => 'lt',

        // Estonian: et
        'igauņu' => 'et',
        'igauniski' => 'et',
        'estonian' => 'et',
        'et' => 'et',
        'ee' => 'et',
        'est' => 'et',

        // Polish: pl
        'poļu' => 'pl',
        'poliski' => 'pl',
        'polish' => 'pl',
        'pl' => 'pl',
        'pol' => 'pl',

        // Japanese: ja
        'japāņu' => 'ja',
        'japāniski' => 'ja',
        'japanese' => 'ja',
        'ja' => 'ja',
        'jp' => 'ja',
        'jpn' => 'ja',

        // Dutch: nl
        'nīderlandiešu' => 'nl',
        'holandiešu' => 'nl',
        'dutch' => 'nl',
        'nl' => 'nl',
        'nld' => 'nl',
        'dut' => 'nl',

        // Swedish: sv
        'zviedru' => 'sv',
        'zviedriski' => 'sv',
        'swedish' => 'sv',
        'sv' => 'sv',
        'swe' => 'sv',

        // Norwegian: no
        'norvēģu' => 'no',
        'norvēģiski' => 'no',
        'norwegian' => 'no',
        'no' => 'no',
        'nor' => 'no',

        // Danish: da
        'dāņu' => 'da',
        'dāniski' => 'da',
        'danish' => 'da',
        'da' => 'da',
        'dan' => 'da',

        // Finnish: fi
        'somu' => 'fi',
        'somiski' => 'fi',
        'finnish' => 'fi',
        'fi' => 'fi',
        'fin' => 'fi',

        // Chinese: zh
        'ķīniešu' => 'zh',
        'ķīniski' => 'zh',
        'chinese' => 'zh',
        'zh' => 'zh',
        'zho' => 'zh',
        'chi' => 'zh',

        // Korean: ko
        'korejiešu' => 'ko',
        'korean' => 'ko',
        'ko' => 'ko',
        'kor' => 'ko',

        // Portuguese: pt
        'portugāļu' => 'pt',
        'portuguese' => 'pt',
        'pt' => 'pt',
        'por' => 'pt',

        // Czech: cs
        'čehu' => 'cs',
        'czech' => 'cs',
        'cs' => 'cs',
        'ces' => 'cs',
        'cze' => 'cs',

        // Hebrew: he
        'ivrits' => 'he',
        'ivritā' => 'he',
        'ivrita' => 'he',
        'ebreju' => 'he',
        'hebrew' => 'he',
        'he' => 'he',
        'heb' => 'he',

        // Arabic: ar
        'arābu' => 'ar',
        'arabic' => 'ar',
        'ar' => 'ar',
        'ara' => 'ar',

        // Turkish: tr
        'turku' => 'tr',
        'turkish' => 'tr',
        'tr' => 'tr',
        'tur' => 'tr',

        // Hindi: hi
        'hindi' => 'hi',
        'хинди' => 'hi',
        'hi' => 'hi',
        'hin' => 'hi',
    ];

    /**
     * Regex patterns for inflected forms (particularly Russian declensions).
     */
    private const REGEX_MAP = [
        '/^латышск\w*$/ui' => 'lv',
        '/^английск\w*$/ui' => 'en',
        '/^русск\w*$/ui' => 'ru',
        '/^немецк\w*$/ui' => 'de',
        '/^французск\w*$/ui' => 'fr',
        '/^итальянск\w*$/ui' => 'it',
        '/^испанск\w*$/ui' => 'es',
        '/^украинск\w*$/ui' => 'uk',
        '/^литовск\w*$/ui' => 'lt',
        '/^эстонск\w*$/ui' => 'et',
        '/^польск\w*$/ui' => 'pl',
        '/^японск\w*$/ui' => 'ja',
        '/^нидерландск\w*$/ui' => 'nl',
        '/^голландск\w*$/ui' => 'nl',
        '/^шведск\w*$/ui' => 'sv',
        '/^норвежск\w*$/ui' => 'no',
        '/^датск\w*$/ui' => 'da',
        '/^финск\w*$/ui' => 'fi',
        '/^китайск\w*$/ui' => 'zh',
        '/^корейск\w*$/ui' => 'ko',
        '/^португальск\w*$/ui' => 'pt',
        '/^чешск\w*$/ui' => 'cs',
        '/^иврит\w*$/ui' => 'he',
        '/^арабск\w*$/ui' => 'ar',
        '/^турецк\w*$/ui' => 'tr',
    ];

    /**
     * Resolve a single token to ISO 639-1 code.
     */
    public static function resolve(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        $clean = trim(mb_strtolower($token, 'UTF-8'));
        $clean = trim($clean, " \t\n\r\0\x0B.,;:-/()[]");

        if ($clean === '') {
            return null;
        }

        if (isset(self::DIRECT_MAP[$clean])) {
            return self::DIRECT_MAP[$clean];
        }

        foreach (self::REGEX_MAP as $pattern => $code) {
            if (preg_match($pattern, $clean)) {
                return $code;
            }
        }

        // Generic 2-letter ISO code fallback if valid
        $normalized = EventLanguageService::normalizeCodes([$clean]);
        return $normalized[0] ?? null;
    }

    /**
     * Resolve multiple language names or delimited string into a sorted, normalized array of ISO 639-1 codes.
     *
     * @param string|array|null $input
     * @return array<int, string>
     */
    public static function resolveMultiple(string|array|null $input): array
    {
        if ($input === null) {
            return [];
        }

        if (is_array($input)) {
            $codes = [];
            foreach ($input as $item) {
                if (is_string($item)) {
                    $codes = array_merge($codes, self::resolveMultiple($item));
                } elseif (is_array($item)) {
                    // E.g. Markus XML SubtitleLanguage or SpokenLanguage array with ISOTwoLetterCode/Name
                    if (!empty($item['ISOTwoLetterCode']) && is_string($item['ISOTwoLetterCode'])) {
                        $c = self::resolve($item['ISOTwoLetterCode']);
                        if ($c) {
                            $codes[] = $c;
                            continue;
                        }
                    }
                    if (!empty($item['Name']) && is_string($item['Name'])) {
                        $codes = array_merge($codes, self::resolveMultiple($item['Name']));
                    }
                    if (!empty($item['NameInLanguage']) && is_string($item['NameInLanguage'])) {
                        $codes = array_merge($codes, self::resolveMultiple($item['NameInLanguage']));
                    }
                }
            }
            return EventLanguageService::normalizeCodes($codes);
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            return [];
        }

        // Split by separators: comma, slash, semicolon, ampersand, plus, pipe, and words 'un', 'and', 'и'
        $tokens = preg_split('/\s*(?:[\/,;&|+]\s*|\s+(?:un|and|и)\s+)\s*/ui', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return [];
        }

        $codes = [];
        foreach ($tokens as $token) {
            $resolved = self::resolve($token);
            if ($resolved !== null) {
                $codes[] = $resolved;
            }
        }

        return EventLanguageService::normalizeCodes($codes);
    }
}
