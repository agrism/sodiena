<?php

namespace App\Services;

class EventDescriptionFormatter
{
    /**
     * Common Latvian and English event headings.
     */
    /**
     * Common Latvian and English event headings for major sections.
     */
    protected array $headings = [
        'Par izrādi:', 'Par izrādi', 'Par filmu:', 'Par filmu', 'Par pasākumu:', 'Par pasākumu',
        'Par koncertu:', 'Par koncertu', 'Par izstādi:', 'Par izstādi', 'Par festivālu:', 'Par festivālu',
        'Vakara programmā:', 'Pasākuma programma:', 'PROGRAMMĀ:', 'Programma:', 'PROGRAMMA:', 'Pasākumu plāns:',
        'Izstādes atklāšana:', 'Izstāde apskatāma:', 'Izstāde atvērta:', 'Apskatāma:', 'Atklāšana:',
        'Galvenie darbības virzieni:', 'Galvenie virzieni:', 'Darbības virzieni:',
        'Dalībnieku pieteikšanās:', 'Pieteikšanās:', 'Reģistrācija:',
        'Svarīgi:', 'Uzmanību:', 'Ievērībai:', 'Piezīme:', 'Kāpēc piedalīties:', 'Atlaides:',
        'Vairāk informācijas:', 'Papildu informācija:', 'Papildus informācija:',
        'Plašāka informācija:', 'Sīkāka informācija:', 'Informācija:', 'Kontakti:',
    ];

    /**
     * Common key-value metadata attributes (styled compactly with bold labels).
     */
    protected array $attributeLabels = [
        'Režisors:', 'Režisore:', 'Aktieri:', 'Lomās:', 'Piedalās:', 'Mākslinieki:', 'Dalībnieki:',
        'Valsts:', 'Garums:', 'Ilgums:', 'Pasākuma ilgums:', 'Žanrs:', 'Žanri:',
        'Vecuma ierobežojums:', 'Vecums:', 'Pasākumu drīkst apmeklēt:',
        'Valoda:', 'Valodas:', 'Pasākuma valoda:', 'Subtitri:',
        'Vieta cilvēkam ar invaliditāti:', 'Vieta cilvēkam ar invaliditāti ratiņkrēslā:',
        'Ieeja pasākumā:', 'Ieejas maksa:', 'Biļešu cena:', 'Biļešu cenas:', 'Cena:', 'Cenas:', 'Dalības maksa:', 'Cena studentiem:', 'Bezmaksas ieeja:',
        'Organizators:', 'Rīkotājs:', 'Kurators:', 'Kuratore:', 'Organizē:',
        'Diriģents:', 'Diriģente:', 'Scenārijs:', 'Horeogrāfija:', 'Scenogrāfija:', 'Tērpu mākslinieks:', 'Tērpu māksliniece:',
        'Norises vieta:', 'Norises laiks:', 'Plenēra norises laiks:', 'Plenēra norises vieta:',
        'Plenēra mērķi:', 'Plenēra mērķis:', 'Plenēra dalībnieku pieteikšanās:', 'Plenēra dalībnieku pietiekšanās:',
        'Darba laiks:', 'Darba laiki:', 'Pieteikties līdz:', 'Adrese:', 'Vieta:', 'Laiks:',
    ];

    /**
     * Emojis that typically begin new items or activities.
     */
    protected array $emojis = [
        '🎵', '💃', '🛍️', '🥔', '📍', '➤', '🔹', '🔸', '🔺', '▪️', '▫️', '★', '⭐',
        '🗓️', '📅', '⏰', '🕒', '👉', '✅', '🏷️', '🎟️', '🎭', '🎨', '🎪', '🎬',
        '🎤', '🚴', '🏆', '📌', '⚠️'
    ];

    /**
     * Format raw event description into structured, clean HTML.
     */
    public function format(?string $text, bool $isFree = false): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        // 1. Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Convert existing <br> and paragraph closing tags to newlines
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(?:p|div|li|tr|h[1-6])>/i', "\n\n", $text);
        $text = strip_tags($text, '<a><b><strong><i><em><iframe>');

        // 2. Clean tracking parameters from URLs (e.g. utm_source=afiro)
        $text = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&\s\"\'<>]*/u', '', $text);
        $text = preg_replace('/(https?:\/\/[^\s\)\"\'<>]+)[?&]+(?=[\s\)\"\'<>]|$)/u', '$1', $text);

        // 2b. Sanitize "Biļetes:" if event is free or if the link is merely informational (e.g. liveriga.com, riga.lv, latvia.travel)
        if ($isFree) {
            $text = preg_replace('/\b(?:Biļetes|Biļešu cenas|Biļešu cena):\s*(?=https?:\/\/)/ui', "Papildu informācija: ", $text);
        } else {
            $text = preg_replace('/\b(?:Biļetes|Biļešu cenas|Biļešu cena):\s*(?=https?:\/\/(?:www\.)?(?:liveriga\.com|afiro\.lv|riga\.lv|latvia\.travel))/ui', "Papildu informācija: ", $text);
        }

        // 3. Break before common section headings and attribute labels
        $breakKeys = array_merge($this->headings, $this->attributeLabels);
        usort($breakKeys, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($breakKeys as $key) {
            $pattern = '/(?<=\S|\b)\s*(' . preg_quote($key, '/') . ')/u';
            $text = preg_replace($pattern, "\n\n$1 ", $text);
        }

        // 4. Break before emojis & bullet symbols
        $emojiGroup = implode('', array_map(fn($e) => preg_quote($e, '/'), $this->emojis));
        $text = preg_replace('/(?<=\S)\s+(?=[' . $emojiGroup . '])/u', "\n", $text);

        // 5. Break before dates in schedules (e.g. "06/09 -", "11.09. |", "20.09. | 10.00–17.00")
        // Skip phrases like "un 26.09.", "līdz 20.09.", "no 18.00", "1918–1940. gadā"
        $datePattern = '/(?:\b(?:un|līdz|no|vai|kopš|ap|ar)\s+\d{1,2}[\.\/](?:0?[1-9]|1[0-2]))(*SKIP)(*F)|(?<=\S)\s+(?=\d{1,2}[\.\/](?:0?[1-9]|1[0-2])(?:\.|\/|\s*[-–|]|\s+[A-ZĀČĒĢĪĶĻŅŠŪŽ]))/u';
        $text = preg_replace($datePattern, "\n", $text);

        // 6. Break before recurring days like "Svētdienās", "Sestdienās"
        $dayPattern = '/(?<=\S)\s+(?=(?:Katru\s+(?:svētdienu|sestdienu|piektdienu)|Svētdienās|Sestdienās|Piektdienās|Ceturtdienās|Trešdienās|Otrdienās|Pirmdienās)\b)/u';
        $text = preg_replace($dayPattern, "\n", $text);

        // 7. Process lines into structured items
        $rawLines = preg_split('/\r?\n/', $text);
        $items = [];

        foreach ($rawLines as $rawLine) {
            $line = trim($rawLine);
            $line = trim($line, "\xC2\xA0\x20\t\n\r\0\x0B"); // Clean non-breaking spaces
            if ($line === '' || $line === '•' || $line === '.' || $line === '-') {
                continue;
            }
            $items[] = $line;
        }

        $html = '';
        $inList = false;

        foreach ($items as $line) {
            // Check for standalone YouTube URL / iframe video
            if (preg_match('/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_\-]{11})/i', $line, $ym)) {
                $youtubeId = $ym[1];
                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }
                $html .= '<div class="my-6 rounded-2xl overflow-hidden aspect-video shadow-md border border-slate-200">'
                    . '<iframe class="w-full h-full" src="https://www.youtube-nocookie.com/embed/' . htmlspecialchars($youtubeId, ENT_QUOTES, 'UTF-8') . '" title="Video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
                    . '</div>' . "\n";
                continue;
            }

            // Check if line is a section heading
            $isHeading = false;
            foreach ($this->headings as $heading) {
                if (mb_stripos($line, $heading) === 0 && mb_strlen($line) <= mb_strlen($heading) + 35) {
                    $isHeading = true;
                    break;
                }
            }

            if ($isHeading) {
                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }
                $html .= '<h4 class="text-base sm:text-lg font-bold text-slate-900 mt-7 mb-3 flex items-center gap-2 border-l-4 border-emerald-500 pl-3">'
                    . $this->renderLineHtml($line)
                    . '</h4>' . "\n";
                continue;
            }

            // Check if line is a metadata key-value attribute
            $matchedAttr = $this->matchAttributeLine($line);
            if ($matchedAttr !== null) {
                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }
                [$attrKey, $attrVal] = $matchedAttr;
                if (!empty($attrVal)) {
                    $html .= '<p class="mb-1.5 text-slate-700 leading-relaxed"><strong class="font-bold text-slate-900">'
                        . htmlspecialchars($attrKey, ENT_QUOTES, 'UTF-8') . ':</strong> '
                        . $this->renderLineHtml($attrVal) . '</p>' . "\n";
                } else {
                    $html .= '<p class="mb-1.5 text-slate-700 leading-relaxed"><strong class="font-bold text-slate-900">'
                        . htmlspecialchars($attrKey, ENT_QUOTES, 'UTF-8') . ':</strong></p>' . "\n";
                }
                continue;
            }

            // Check if line is a day header (e.g. "Svētdienās", "Sestdienās")
            if (preg_match('/^(?:Katru\s+(?:svētdienu|sestdienu|piektdienu)|Svētdienās|Sestdienās|Piektdienās|Ceturtdienās|Trešdienās|Otrdienās|Pirmdienās):?$/u', $line)) {
                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }
                $html .= '<h5 class="text-sm font-extrabold text-emerald-800 uppercase tracking-wider mt-5 mb-2 flex items-center gap-1.5">'
                    . '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>'
                    . $this->renderLineHtml($line)
                    . '</h5>' . "\n";
                continue;
            }

            // Check if line is a list item, bullet, emoji, or date schedule
            $matchedEmoji = null;
            foreach ($this->emojis as $emoji) {
                if (mb_strpos($line, $emoji) === 0) {
                    $matchedEmoji = $emoji;
                    break;
                }
            }

            $isDate = (bool) preg_match('/^\d{1,2}[\.\/]\d{1,2}(?:\.|\/|\s*[-–|]|\s+[A-ZĀČĒĢĪĶĻŅŠŪŽ])/u', $line);
            $isBullet = (bool) preg_match('/^[•\-\*]\s+/u', $line);

            if ($matchedEmoji !== null || $isDate || $isBullet) {
                if (!$inList) {
                    $html .= "<ul class=\"my-4 space-y-2.5\">\n";
                    $inList = true;
                }

                if ($matchedEmoji !== null) {
                    $cleanLine = trim(mb_substr($line, mb_strlen($matchedEmoji)));
                    // UTF-8 safe strip of leading punctuation
                    $cleanLine = preg_replace('/^[\s\-\x{2013}\x{2014}\:\.]+/u', '', $cleanLine);
                    $html .= '<li class="flex items-start gap-2.5 text-slate-700 leading-relaxed">'
                        . '<span class="shrink-0 text-base leading-snug mt-0.5">' . $matchedEmoji . '</span>'
                        . '<span>' . $this->renderLineHtml($cleanLine) . '</span></li>' . "\n";
                } elseif ($isDate) {
                    $html .= '<li class="flex items-start gap-2.5 text-slate-800 font-medium leading-relaxed">'
                        . '<span class="shrink-0 text-emerald-600 font-bold mt-0.5"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></span>'
                        . '<span>' . $this->renderLineHtml($line) . '</span></li>' . "\n";
                } else {
                    $cleanLine = preg_replace('/^[•\-\*]\s+/u', '', $line);
                    $html .= '<li class="flex items-start gap-2.5 text-slate-700 leading-relaxed">'
                        . '<span class="text-emerald-500 font-bold shrink-0 mt-0.5">•</span>'
                        . '<span>' . $this->renderLineHtml($cleanLine) . '</span></li>' . "\n";
                }
                continue;
            }

            // Regular paragraph
            if ($inList) {
                $html .= "</ul>\n";
                $inList = false;
            }

            $html .= '<p class="mb-4 text-slate-700 leading-relaxed">' . $this->renderLineHtml($line) . '</p>' . "\n";
        }

        if ($inList) {
            $html .= "</ul>\n";
        }

        return trim($html);
    }

    /**
     * Check if a line matches a key-value attribute
     */
    protected function matchAttributeLine(string $line): ?array
    {
        foreach ($this->attributeLabels as $attr) {
            if (mb_stripos($line, $attr) === 0) {
                $val = trim(mb_substr($line, mb_strlen($attr)));
                return [rtrim($attr, ':'), $val];
            }
        }

        // Generic key-value check (e.g. "Režisors: Jānis Bērziņš", "Lomās: ...")
        if (preg_match('/^([A-ZĀČĒĢĪĶĻŅŠŪŽ][a-zA-Z0-9āčēģīķļņšūž\s\/\(\)\-]{1,28}):\s*(.+)$/u', $line, $m)) {
            $key = trim($m[1]);
            $val = trim($m[2]);

            // Avoid catching URLs, days, or known full section headings
            if (!str_contains($key, 'http') && !in_array($key . ':', $this->headings) && !in_array($key, $this->headings)) {
                return [$key, $val];
            }
        }

        return null;
    }

    /**
     * Escape HTML and auto-link URLs (both https:// and raw www. links).
     */
    protected function renderLineHtml(string $text): string
    {
        // 1. First escape the raw text safely
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // 2. Auto-link http(s) URLs
        $escaped = preg_replace_callback(
            '/(https?:\/\/[^\s\)\"\'<>]+)/i',
            function ($matches) {
                $url = rtrim($matches[1], '.,;:\'\"');
                $displayUrl = preg_replace('/^https?:\/\/(www\.)?/', '', $url);
                $displayUrl = rtrim($displayUrl, '/');
                if (mb_strlen($displayUrl) > 48) {
                    $displayUrl = mb_substr($displayUrl, 0, 45) . '...';
                }
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-800 underline underline-offset-2 break-all">'
                    . htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8')
                    . ' <svg class="w-3.5 h-3.5 inline shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a>';
            },
            $escaped
        );

        // 3. Auto-link www. domains that weren't preceded by http(s)://
        $escaped = preg_replace_callback(
            '/(?<!href=")(?<!">)(?<!\/)(www\.[a-zA-Z0-9\-]+(?:\.[a-zA-Z]{2,})+(?:\/[^\s\)\"\'<>]*)?)/i',
            function ($matches) {
                $raw = rtrim($matches[1], '.,;:\'\"');
                $url = 'https://' . $raw;
                $displayUrl = preg_replace('/^www\./', '', $raw);
                $displayUrl = rtrim($displayUrl, '/');
                if (mb_strlen($displayUrl) > 48) {
                    $displayUrl = mb_substr($displayUrl, 0, 45) . '...';
                }
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-800 underline underline-offset-2 break-all">'
                    . htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8')
                    . ' <svg class="w-3.5 h-3.5 inline shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a>';
            },
            $escaped
        );

        return $escaped;
    }
}
