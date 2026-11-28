<?php

namespace Tests\Unit;

use App\Services\Tickets\TicketProviderResolver;
use PHPUnit\Framework\TestCase;

class TicketProviderResolverTest extends TestCase
{
    /**
     * Test Afiro source with known merchant URLs resolves to the merchant.
     */
    public function test_afiro_source_with_bilesu_paradize_url_resolves_to_bilesu_paradize(): void
    {
        $url = 'https://www.bilesuparadize.lv/lv/event/12345';
        $resolved = TicketProviderResolver::resolve($url, 'afiro-api', 'Afiro Pasākumu API');

        $this->assertEquals('Biļešu Paradīze', $resolved);
    }

    public function test_afiro_source_with_bilesu_serviss_url_resolves_to_bilesu_serviss(): void
    {
        $url = 'https://bilesuserviss.lv/lat/biletes/muzika/roks-un-pops/koncerts-123/';
        $resolved = TicketProviderResolver::resolve($url, 'afiro-api', 'Afiro Pasākumu API');

        $this->assertEquals('Biļešu Serviss', $resolved);
    }

    public function test_afiro_source_with_subdomain_and_parameters(): void
    {
        $url = 'https://tickets.bilesuparadize.lv/lv/performances/view/456?utm_source=afiro&utm_medium=referral';
        $resolved = TicketProviderResolver::resolve($url, 'afiro-api');

        $this->assertEquals('Biļešu Paradīze', $resolved);
    }

    /**
     * Test Afiro or aggregator source with unknown non-merchant URL returns null, never Afiro.
     */
    public function test_afiro_source_with_unknown_url_returns_null(): void
    {
        $url = 'https://kautkads-pasakums.lv/tickets';
        $resolved = TicketProviderResolver::resolve($url, 'afiro-api', 'Afiro Pasākumu API');

        $this->assertNull($resolved);
    }

    public function test_aggregator_source_with_technical_fallback_returns_null(): void
    {
        $url = 'https://random-venue.org/buy';
        $this->assertNull(TicketProviderResolver::resolve($url, 'kulturasdati-lv', 'Kultūras Dati'));
        $this->assertNull(TicketProviderResolver::resolve($url, null, 'Afiro Pasākumu API'));
        $this->assertNull(TicketProviderResolver::resolve($url, null, 'Event Scraper Feed'));
        $this->assertNull(TicketProviderResolver::resolve($url, null, 'Sodiena Aggregator'));
    }

    /**
     * Test direct seller with own name preserves its legitimate name.
     */
    public function test_direct_venue_or_seller_preserves_legitimate_name(): void
    {
        $url = 'https://splendidpalace.lv/lv/filmas/seansi/123';
        $resolved = TicketProviderResolver::resolve($url, 'splendid-palace', 'Splendid Palace');

        $this->assertEquals('Splendid Palace', $resolved);

        // Unknown URL domain, but direct legitimate seller venue
        $urlDirect = 'https://mazagilde.lv/pasakums/789';
        $resolvedDirect = TicketProviderResolver::resolve($urlDirect, 'mazagilde', 'RKTMC Mazā Ģilde');
        $this->assertEquals('RKTMC Mazā Ģilde', $resolvedDirect);
    }

    /**
     * Test all major Latvian ticket platforms resolve accurately.
     */
    public function test_all_major_ticket_platforms_resolution(): void
    {
        $platforms = [
            'https://www.bilesuparadize.lv/lv/event/1' => 'Biļešu Paradīze',
            'https://bilesuserviss.lv/lat/biletes/1' => 'Biļešu Serviss',
            'https://www.bezrindas.lv/lv/pasakumi/1' => 'BezRindas',
            'https://aula.lv/pasakumi/1' => 'Aula',
            'https://ekase.lv/lv/biletes/1' => 'eKase',
            'https://ticketshop.lv/events/1' => 'Ticketshop',
            'https://mobilly.lv/biletes/1' => 'Mobilly',
            'https://passportix.eu/events/1' => 'Passportix',
            'https://fienta.com/lv/1' => 'Fienta',
            'https://www.eventbrite.com/e/123' => 'Eventbrite',
            'https://www.livenation.lv/show/1' => 'Live Nation',
            'https://ticketbest.eu/event/1' => 'TicketBest',
            'https://ticketly.eu/event/1' => 'Ticketly',
        ];

        foreach ($platforms as $url => $expectedName) {
            $this->assertEquals(
                $expectedName,
                TicketProviderResolver::resolve($url, 'afiro-api', 'Afiro Pasākumu API'),
                "Failed resolving {$url}"
            );
        }
    }

    /**
     * Test cinema booking platforms.
     */
    public function test_cinema_platforms_resolution(): void
    {
        $cinemas = [
            'https://www.apollokino.lv/event/1' => 'Apollo Kino',
            'https://forumcinemas.lv/event/1' => 'Forum Cinemas',
            'https://www.cinamonkino.com/alfa/event/1' => 'Cinamon Kino',
            'https://splendidpalace.lv/event/1' => 'Splendid Palace',
            'https://kinobize.lv/seansi/1' => 'Kino Bize',
            'https://kinogaisma.lv/filma/1' => 'Kino Gaisma',
            'https://kinolora.lv/seansi/1' => 'Kino Lora',
        ];

        foreach ($cinemas as $url => $expectedName) {
            $this->assertEquals(
                $expectedName,
                TicketProviderResolver::resolve($url, 'afiro-api'),
                "Failed resolving cinema {$url}"
            );
        }
    }

    /**
     * Test button labels formatting.
     */
    public function test_button_label_generation(): void
    {
        // Standard ticket merchant
        $url1 = 'https://www.bilesuparadize.lv/lv/event/1';
        $this->assertEquals('Pirkt biļetes (Biļešu Paradīze)', TicketProviderResolver::getLabel($url1));

        // Cinema platform
        $url2 = 'https://www.apollokino.lv/event/1';
        $this->assertEquals('Apollo Kino seansi un biļetes', TicketProviderResolver::getLabel($url2));

        // Unknown/aggregator where resolve is null -> simple "Pirkt biļetes" without brackets
        $url3 = 'https://kautkas.lv/bilete';
        $this->assertEquals('Pirkt biļetes', TicketProviderResolver::getLabel($url3));

        // Empty URL
        $this->assertEquals('Pirkt biļetes', TicketProviderResolver::getLabel(null));
    }

    /**
     * Test platform slug generation.
     */
    public function test_platform_slug_generation(): void
    {
        $this->assertEquals('bilesuparadize', TicketProviderResolver::getPlatformSlug('https://www.bilesuparadize.lv/lv/event/1'));
        $this->assertEquals('apollo', TicketProviderResolver::getPlatformSlug('https://www.apollokino.lv/'));
        $this->assertEquals('tickets', TicketProviderResolver::getPlatformSlug('https://unknown-domain.lv/item'));
    }

    /**
     * Test isAggregator detection.
     */
    public function test_is_aggregator_detection(): void
    {
        $this->assertTrue(TicketProviderResolver::isAggregator('afiro-api'));
        $this->assertTrue(TicketProviderResolver::isAggregator('kulturasdati'));
        $this->assertTrue(TicketProviderResolver::isAggregator(null, 'Afiro Pasākumu API'));
        $this->assertTrue(TicketProviderResolver::isAggregator(null, 'Event API Scraper'));

        $this->assertFalse(TicketProviderResolver::isAggregator('apollo-kino', 'Apollo Kino'));
        $this->assertFalse(TicketProviderResolver::isAggregator('bilesu-paradize', 'Biļešu Paradīze'));
        $this->assertFalse(TicketProviderResolver::isAggregator('dailes-teatris', 'Dailes teātris'));
    }
}
