<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Location;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventCinemaLinksAndOrganizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_links_accessor_extracts_multiple_cinema_links(): void
    {
        $event = new Event([
            'title' => 'Dzīvnieku ferma',
            'slug' => 'dzivnieku-ferma-test12',
            'ticket_url' => 'https://www.apollokino.lv/event/304161/dzivnieku_ferma?theatreAreaID=1011',
            'raw_data' => [
                'cta' => [
                    'kind' => 'tickets',
                    'label' => 'Biļetes',
                    'links' => [
                        [
                            'id' => 'link-1',
                            'url' => 'https://www.apollokino.lv/event/304161/dzivnieku_ferma?theatreAreaID=1011&utm_source=afiro',
                            'title' => null,
                        ],
                        [
                            'id' => 'link-2',
                            'url' => 'https://www.forumcinemas.lv/event/304908/title/dzivnieku_ferma/?utm_source=afiro',
                            'title' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $links = $event->ticket_links;

        $this->assertCount(2, $links);
        $this->assertEquals('Apollo Kino', $links[0]['title']);
        $this->assertEquals('Apollo Kino seansi un biļetes', $links[0]['label']);
        $this->assertEquals('https://www.apollokino.lv/event/304161/dzivnieku_ferma?theatreAreaID=1011', $links[0]['url']);

        $this->assertEquals('Forum Cinemas', $links[1]['title']);
        $this->assertEquals('Forum Cinemas seansi un biļetes', $links[1]['label']);
        $this->assertEquals('https://www.forumcinemas.lv/event/304908/title/dzivnieku_ferma/', $links[1]['url']);
    }

    public function test_display_venue_formats_cinema_names_when_location_is_city(): void
    {
        $location = Location::create([
            'name' => 'Riga',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
        ]);

        $event = new Event([
            'title' => 'Dzīvnieku ferma',
            'slug' => 'dzivnieku-ferma-test34',
            'location_id' => $location->id,
            'raw_data' => [
                'cta' => [
                    'links' => [
                        ['url' => 'https://www.apollokino.lv/event/304161'],
                        ['url' => 'https://www.forumcinemas.lv/event/304908'],
                    ],
                ],
            ],
        ]);
        $event->setRelation('location', $location);

        $displayVenue = $event->display_venue;
        $this->assertEquals('Rīga: Apollo Kino, Forum Cinemas', $displayVenue);
    }

    public function test_organizer_accessors(): void
    {
        $eventWithOrg = new Event([
            'title' => 'Festivāls',
            'slug' => 'festivals-test',
            'raw_data' => [
                'organizer' => [
                    'name' => 'SIA Pasākumu Aģentūra',
                    'url' => 'https://pasakumi.lv',
                ],
            ],
        ]);

        $this->assertEquals('SIA Pasākumu Aģentūra', $eventWithOrg->organizer_display_name);
        $this->assertEquals('https://pasakumi.lv', $eventWithOrg->organizer_url);

        // Afiro aggregator organizer should be ignored
        $eventWithAfiroOrg = new Event([
            'title' => 'Dzīvnieku ferma',
            'slug' => 'dzivnieku-ferma-afiro',
            'raw_data' => [
                'organizer' => [
                    'name' => 'Afiro',
                    'url' => 'https://afiro.lv',
                ],
            ],
        ]);

        $this->assertNull($eventWithAfiroOrg->organizer_display_name);
        $this->assertNull($eventWithAfiroOrg->organizer_url);
    }
}
