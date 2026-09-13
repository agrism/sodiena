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
}
