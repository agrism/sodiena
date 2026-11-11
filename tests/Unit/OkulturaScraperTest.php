<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Services\Scrapers\Sources\OkulturaScraper;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\TestCase;

class OkulturaScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_okultura_scraper_metadata(): void
    {
        $scraper = new OkulturaScraper();
        $this->assertEquals('okultura', $scraper->getSlug());
        $this->assertEquals('Ogres novada Kultūras centrs (okultura.lv)', $scraper->getName());
        $this->assertEquals('https://www.okultura.lv', OkulturaScraper::BASE_URL);
        $this->assertEquals('/lv/pasakumi', OkulturaScraper::EVENTS_PATH);
    }

    public function test_parse_event_card_standard_concert(): void
    {
        $scraper = new OkulturaScraper();

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $dm = $tomorrow->format('d.m');

        $html = <<<HTML
        <div class="event-list-item" id="event-179325">
            <div class="event-date">
                <div class="event-date-time">
                    <div class="event-day-month">{$dm}</div>
                    <div class="event-time">19.00</div>
                </div>
            </div>
            <div class="event-list-image">
                <a href="https://www.okultura.lv//images/events/test/s1_179325.jpg" class="gallery-item">
                    <img src="https://www.okultura.lv/images/userfiles/svg/default.svg" data-src="https://www.okultura.lv//images/events/test/s3_179325.jpg" />
                </a>
            </div>
            <div class="event-list-descr">
                <h2><a href="https://www.okultura.lv/lv/pasakumi/koncerts-vilim-179325/">Koncerts VILIM ar pūtēju orķestri</a></h2>
                <div class="event-more-descr">
                    <div class="event-details">
                        <ul class="contacts">
                            <li><label>Datums</label> {$dm}</li>
                            <li><label>Norises vieta</label> Ogres novada Kultūras centra Lielā zāle</li>
                            <li><label>Adrese</label> Ogre, Brīvības iela 15</li>
                            <li><label>Biļešu cenas</label> € 25,00 - 35,00</li>
                            <li><a href="https://www.bilesuparadize.lv/lv/event/179325" class="btn">Pirkt biļeti</a></li>
                        </ul>
                    </div>
                    <p>Ogres novada Kultūras centra Lielajā zālē izskanēs pūtēju orķestra koncerts.</p>
                    <div class="share-btn">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=..." class="btn">Share</a>
                    </div>
                </div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html, 'https://www.okultura.lv/lv/pasakumi');
        $node = $crawler->filter('.event-list-item')->first();

        $dto = $scraper->parseEventItem($node);

        $this->assertNotNull($dto);
        $this->assertEquals('Koncerts VILIM ar pūtēju orķestri', $dto->title);
        $this->assertEquals('okultura-179325', $dto->sourceExternalId);
        $this->assertEquals($tomorrow->toDateString(), $dto->startAt->toDateString());
        $this->assertEquals('19:00:00', $dto->startAt->toTimeString());
        $this->assertEquals('Ogres novada Kultūras centrs, Lielā zāle', $dto->venueName);
        $this->assertEquals('Ogre', $dto->city);
        $this->assertEquals('Vidzeme', $dto->region);
        $this->assertEquals('Brīvības iela 15, Ogre, LV-5001', $dto->address);
        $this->assertContains('Mūzika', $dto->categoryNames);
        $this->assertEquals('concert', $dto->entertainmentType);
        $this->assertFalse($dto->isFree);
        $this->assertEquals(25.0, $dto->priceMin);
        $this->assertEquals(35.0, $dto->priceMax);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/179325', $dto->ticketUrl);
        $this->assertEquals('https://www.okultura.lv/images/events/test/s1_179325.jpg', $dto->imageUrl);
        $this->assertStringNotContainsString('share-btn', $dto->description);
        $this->assertStringNotContainsString('event-details', $dto->description);
        $this->assertStringContainsString('Ogres novada Kultūras centra Lielajā zālē izskanēs', $dto->description);
    }

    public function test_detect_and_override_postponed_event(): void
    {
        $scraper = new OkulturaScraper();

        $html = <<<HTML
        <div class="event-list-item" id="event-15821">
            <div class="event-date">
                <div class="event-date-time">
                    <div class="event-day-month">12.11</div>
                    <div class="event-time">19.00</div>
                </div>
            </div>
            <div class="event-list-descr">
                <h2><a href="https://www.okultura.lv/lv/pasakumi/pie-laika-brivibas-dziesmas/">...PIE LAIKA | brīvības dziesmas</a></h2>
                <div class="event-more-descr">
                    <div class="event-details">
                        <ul class="contacts">
                            <li><label>Datums</label> 12.11</li>
                            <li><label>Norises vieta</label> Ogres novada Kultūras centra Lielā zāle</li>
                            <li><label>Adrese</label> Ogre, Brīvības iela 15</li>
                            <li><label>Biļešu cenas</label> € 25,00 - 35,00</li>
                            <li><a href="https://www.bilesuparadize.lv/lv/event/170768" class="btn">Pirkt biļeti</a></li>
                        </ul>
                    </div>
                    <h4><strong>Koncerts pārcelts uz 2027. gada 5. maiju plkst. 19.00</strong></h4>
                    <p>Iegādātās biļetes uz 12. novembra koncertu saglabā savu derīgumu jaunajā koncerta norises datumā.</p>
                </div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html, 'https://www.okultura.lv/lv/pasakumi');
        $node = $crawler->filter('.event-list-item')->first();

        $dto = $scraper->parseEventItem($node);

        $this->assertNotNull($dto);
        $this->assertEquals('...PIE LAIKA | brīvības dziesmas', $dto->title);
        // Date must be overridden to postponed date 2027-05-05 19:00:00
        $this->assertEquals('2027-05-05', $dto->startAt->toDateString());
        $this->assertEquals('19:00:00', $dto->startAt->toTimeString());
        $this->assertTrue($dto->rawData['is_postponed']);
        $this->assertEquals('https://www.bilesuparadize.lv/lv/event/170768', $dto->ticketUrl);
    }

    public function test_strict_kino_categorization_per_agents_md(): void
    {
        $scraper = new OkulturaScraper();

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $dm = $tomorrow->format('d.m');

        $html = <<<HTML
        <div class="event-list-item" id="event-15891">
            <div class="event-date">
                <div class="event-date-time">
                    <div class="event-day-month">{$dm}</div>
                    <div class="event-time">19.00</div>
                </div>
            </div>
            <div class="event-list-descr">
                <h2><a href="/lv/pasakumi/tumsa-robeza/">Spēlfilma TUMŠĀ ROBEŽA</a></h2>
                <div class="event-more-descr">
                    <div class="event-details">
                        <ul class="contacts">
                            <li><label>Norises vieta</label> Ciemupes Tautas nams</li>
                            <li><label>Biļešu cenas</label> € 3,00</li>
                        </ul>
                    </div>
                    <p>Ciemupes Tautas namā būs skatāma spēlfilma "Tumšā robeža".</p>
                </div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html, 'https://www.okultura.lv/lv/pasakumi');
        $node = $crawler->filter('.event-list-item')->first();

        $dto = $scraper->parseEventItem($node);

        $this->assertNotNull($dto);
        // AGENTS.md Rule 1: Cinema/Kino priority, must NEVER be Teātris
        $this->assertEquals(['Kino'], $dto->categoryNames);
        $this->assertEquals('movie', $dto->entertainmentType);
        $this->assertNotContains('Teātris', $dto->categoryNames);
        $this->assertEquals(3.0, $dto->priceMin);
        $this->assertEquals(3.0, $dto->priceMax);
    }

    public function test_free_invitation_tickets_pricing(): void
    {
        $scraper = new OkulturaScraper();

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $dm = $tomorrow->format('d.m');

        $html = <<<HTML
        <div class="event-list-item" id="event-15900">
            <div class="event-date">
                <div class="event-date-time">
                    <div class="event-day-month">{$dm}</div>
                    <div class="event-time">17.00</div>
                </div>
            </div>
            <div class="event-list-descr">
                <h2><a href="/lv/pasakumi/valsts-svetku-koncerts/">VALSTS SVĒTKU KONCERTS</a></h2>
                <div class="event-more-descr">
                    <div class="event-details">
                        <ul class="contacts">
                            <li><label>Norises vieta</label> Ogres novada Kultūras centra Lielā zāle</li>
                            <li><label>Biļešu cenas</label> Bezmaksas ielūgumi saņemami Biļešu paradīzes tīklā no 10. novembra plkst. 14.00.</li>
                        </ul>
                    </div>
                    <p>Svinīgs valsts svētku koncerts.</p>
                </div>
            </div>
        </div>
HTML;

        $crawler = new Crawler($html, 'https://www.okultura.lv/lv/pasakumi');
        $node = $crawler->filter('.event-list-item')->first();

        $dto = $scraper->parseEventItem($node);

        $this->assertNotNull($dto);
        $this->assertTrue($dto->isFree);
        $this->assertEquals(0.0, $dto->priceMin);
        $this->assertEquals(0.0, $dto->priceMax);
        $this->assertEquals(['Festivāli & Svētki'], $dto->categoryNames);
    }

    public function test_past_events_are_skipped(): void
    {
        $scraper = new OkulturaScraper();

        $yesterday = Carbon::yesterday('Europe/Riga');
        // If yesterday was in previous year or current year
        $dm = $yesterday->format('d.m');

        $html = <<<HTML
        <div class="event-list-item" id="event-past-1">
            <div class="event-date">
                <div class="event-date-time">
                    <div class="event-day-month">{$dm}</div>
                    <div class="event-time">10.00</div>
                </div>
            </div>
            <div class="event-list-descr">
                <h2><a href="/lv/pasakumi/pagajusais-pasakums/">Pagājušais pasākums</a></h2>
                <div class="event-more-descr">
                    <p>Vecs pasākums, kas jau noticis.</p>
                </div>
            </div>
        </div>
HTML;

        // If yesterday is in the current month, year rollover doesn't occur and startAt is in the past
        if ($yesterday->month === Carbon::today('Europe/Riga')->month && $yesterday->isPast()) {
            $crawler = new Crawler($html, 'https://www.okultura.lv/lv/pasakumi');
            $node = $crawler->filter('.event-list-item')->first();

            $dto = $scraper->parseEventItem($node);
            $this->assertNull($dto, 'Past events must be skipped per AGENTS.md');
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_full_scrape_with_mocked_http(): void
    {
        $source = Source::firstWhere('slug', 'okultura') ?: Source::create([
            'name' => 'Ogres novada Kultūras centrs',
            'slug' => 'okultura',
            'url' => 'https://www.okultura.lv/lv/pasakumi',
            'scraper_class' => OkulturaScraper::class,
            'is_active' => true,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Riga');
        $dm = $tomorrow->format('d.m');

        $html = <<<HTML
        <html>
        <body>
            <div class="event-list-item" id="event-101">
                <div class="event-date">
                    <div class="event-date-time">
                        <div class="event-day-month">{$dm}</div>
                        <div class="event-time">19.00</div>
                    </div>
                </div>
                <div class="event-list-descr">
                    <h2><a href="/lv/pasakumi/spelfilma-test-101/">Spēlfilma BADA SPĒLES</a></h2>
                    <div class="event-more-descr">
                        <div class="event-details">
                            <ul class="contacts">
                                <li><label>Norises vieta</label> Ogres novada Kultūras centra Lielā zāle</li>
                                <li><label>Biļešu cenas</label> € 5,00</li>
                            </ul>
                        </div>
                        <p>Kino seanss Lielajā zālē.</p>
                    </div>
                </div>
            </div>
            <div class="event-list-item" id="event-102">
                <div class="event-date">
                    <div class="event-date-time">
                        <div class="event-day-month">{$dm}</div>
                        <div class="event-time">18.00</div>
                    </div>
                </div>
                <div class="event-list-descr">
                    <h2><a href="/lv/pasakumi/meistarklase-102/">Meistarklase MANDALA UZ KANVAS</a></h2>
                    <div class="event-more-descr">
                        <div class="event-details">
                            <ul class="contacts">
                                <li><label>Norises vieta</label> Ogres novada Kultūras centra Radošā telpa</li>
                                <li><label>Biļešu cenas</label> € 45,00</li>
                            </ul>
                        </div>
                        <p>Radošā darbnīca un meistarklase.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
HTML;

        $mock = new MockHandler([
            new Response(200, [], $html),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $scraper = new OkulturaScraper();
        $scraper->setClient($client);

        $events = $scraper->scrape($source);

        $this->assertCount(2, $events);

        $movieDto = $events->first();
        $this->assertEquals('Spēlfilma BADA SPĒLES', $movieDto->title);
        $this->assertEquals(['Kino'], $movieDto->categoryNames);
        $this->assertEquals('movie', $movieDto->entertainmentType);

        $workshopDto = $events->last();
        $this->assertEquals('Meistarklase MANDALA UZ KANVAS', $workshopDto->title);
        $this->assertEquals(['Māksla un izstādes'], $workshopDto->categoryNames);
        $this->assertEquals('exhibition', $workshopDto->entertainmentType);
    }
}
