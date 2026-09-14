<?php

namespace Tests\Unit;

use App\Services\EventDescriptionFormatter;
use PHPUnit\Framework\TestCase;

class EventDescriptionFormatterTest extends TestCase
{
    protected EventDescriptionFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new EventDescriptionFormatter();
    }

    public function test_formats_collapsed_schedule_and_links(): void
    {
        $raw = '06/09 - Sēņu diena 11.09. | no 18.00 🎵 “Karakums” koncerts un zivju zupa pie “Zivju namiņa” Dzīvās mūzikas vakars pagalmā. Ieeja bez maksas. 20.09. | 10.00–17.00 🥔 Kartupeļu festivāls Vairāk informācijas: https://www.agenskalnatirgus.lv/aktuali?utm_source=afiro';

        $html = $this->formatter->format($raw);

        $this->assertStringContainsString('06/09 - Sēņu diena', $html);
        $this->assertStringContainsString('11.09. | no 18.00', $html);
        $this->assertStringContainsString('“Karakums” koncerts', $html);
        $this->assertStringContainsString('20.09. | 10.00–17.00', $html);
        $this->assertStringContainsString('Kartupeļu festivāls', $html);
        $this->assertStringContainsString('Vairāk informācijas:', $html);
        $this->assertStringContainsString('href="https://www.agenskalnatirgus.lv/aktuali"', $html);
        // UTM query removed
        $this->assertStringNotContainsString('utm_source=afiro', $html);
    }

    public function test_handles_empty_input(): void
    {
        $this->assertSame('', $this->formatter->format(null));
        $this->assertSame('', $this->formatter->format('   '));
    }

    public function test_sanitizes_bilesu_label_for_free_events_or_info_links(): void
    {
        $raw = 'Izstāde par godu arhitektam. Biļetes: https://www.liveriga.com/lv/apmekle/pasakumi/izstade';
        $html = $this->formatter->format($raw, true);

        $this->assertStringContainsString('Papildu informācija:', $html);
        $this->assertStringNotContainsString('Biļetes:', $html);
    }

    public function test_formats_metadata_attributes_cleanly(): void
    {
        $raw = "Un poeta\nRežisors: Simón Mesa Soto\nAktieri: Ubeimar Rios, Rebeca Andrade\nValsts: Kolumbija, Vācija, 2025.\nGarums: 123 min.\nŽanrs: Komēdija, Drāma\nVecuma ierobežojums: 16+\n\nŠķīries, rūpju pilns, pusmūžā, ar nopietnu dzeršanas atkarību?";
        $html = $this->formatter->format($raw);

        // Metadata attributes should be rendered with strong tags, not as h4 green-border section headings
        $this->assertStringContainsString('<strong class="font-bold text-slate-900">Režisors:</strong> Simón Mesa Soto', $html);
        $this->assertStringContainsString('<strong class="font-bold text-slate-900">Aktieri:</strong> Ubeimar Rios, Rebeca Andrade', $html);
        $this->assertStringContainsString('<strong class="font-bold text-slate-900">Garums:</strong> 123 min.', $html);
        $this->assertStringContainsString('<strong class="font-bold text-slate-900">Vecuma ierobežojums:</strong> 16+', $html);
        $this->assertStringNotContainsString('border-emerald-500 pl-3">Vecuma ierobežojums', $html);
        $this->assertStringContainsString('Šķīries, rūpju pilns', $html);
    }

    public function test_embeds_youtube_video_responsively(): void
    {
        $raw = "Anotācija par filmu.\nhttps://www.youtube.com/watch?v=yataczbXXss";
        $html = $this->formatter->format($raw);

        $this->assertStringContainsString('youtube-nocookie.com/embed/yataczbXXss', $html);
        $this->assertStringContainsString('aspect-video', $html);
    }
}
