# Sodiena.lv REST API v2 Dokumentācija

## Pārskats

Sodiena.lv REST API v2 (`/api/v2/*`) nodrošina augstas veiktspējas, deterministisku 1:1 datu sinhronizāciju ar `Piedzivo.lv` un citiem partneriem, balstoties uz relāciju datu modeli:
```
locations → events → event_occurrences → occurrence_sources
```

API v2 ievieš:
1. **Deterministisku keyset kursora pagināciju `(updated_at, id)`**, izmantojot indeksu `idx_events_sync_cursor`, kas novērš ierakstu izlaišanu vai dublēšanos reāllaika skrāpēšanas laikā.
2. **Kompaktu Tombstone protokolu**, kas skaidri atdala aktīvos, sapludinātos (`merged`), atceltos (`cancelled`), dzēstos (`deleted`) un arhivētos (`archived`) pasākumus.
3. **Automātisku 301 redirektu ķēžu saplacināšanu** līdz galīgajam kanoniskajam pasākumam (`merged_into_id`).
4. **Stingru `null` pret `[]` valodu semantiku** seansu līmenī (`null` manto no pasākuma, `[]` nozīmē eksplicīti bez valodas/subtitriem).
5. **Vienreizējo `bridge` režīmu** ar `legacy_event_ids` masīvu `Piedzivo.lv` datubāzes pārsiešanai bez datu zuduma.

---

## Autentifikācija un lokalizācija

* **Hederis:** `Authorization: Bearer <API_BEARER_TOKEN>`
* **Lokalizācija:** Query parametrs `?locale=lv` (vai `en`, `ru`) vai hederis `X-Locale: lv`.
  * Lokalizācija nosaka atgriezto tekstu valodu (`title`, `slug`, `description`, `location.name`, `categories[].name`).
  * Pasākuma oriģinālās norises valodas tiek nodotas objektā `language`.

---

## Galapunkti

| Metode | Ceļš | Apraksts |
| :--- | :--- | :--- |
| `GET` | `/api/v2/events/sync` | Galvenais sinhronizācijas galapunkts (`incremental`, `full`, `bridge`) |
| `GET` | `/api/v2/events/stats` | Viegls paritātes un diagnostikas galapunkts |
| `GET` | `/api/v2/events/{id}` | Viena kanoniskā pasākuma vai tā tombstone izguve |

---

## 1. `GET /api/v2/events/sync`

### Pieprasījuma parametri

| Parametrs | Tips | Noklusējums | Apraksts |
| :--- | :--- | :--- | :--- |
| `cursor` | `string` | `null` | URL-safe base64 kursors no iepriekšējā pieprasījuma `meta.next_cursor` vai `meta.resume_cursor`. |
| `since` | `string`/`int` | `null` | Sākuma laika zīmogs inkrementālajai sinhronizācijai (ISO 8601 vai UNIX timestamp). Ignorēts, ja padots `cursor`. |
| `mode` | `string` | auto | `'incremental'`, `'full'`, vai `'bridge'`. Ja padots `cursor` vai `since` → automātiski `'incremental'`. |
| `upcoming_only` | `boolean` | `true` (`full`) | Atlasa tikai pasākumus ar seansiem šodien vai nākotnē. |
| `recent_days` | `int` | `90` (`bridge`), `0` (`full`) | Papildus nākotnes pasākumiem iekļauj pēdējo `N` dienu (0..365) beigušos, atceltos, arhivētos un sapludinātos pasākumus. |
| `include_legacy_ids` | `boolean` | `false` (`true`, ja `mode=bridge`) | Iekļauj `legacy_event_ids` pie pasākuma un `legacy_event_id` pie katras norises. |
| `per_page` | `int` | `100` | Ierakstu skaits lapā (1..250). |
| `city` | `string` | `null` | Pilsētas filtrs (var atdalīt ar komatiem). |
| `locale` | `string` | `'lv'` | Valoda (`lv`, `en`, `ru`). |

### Sinhronizācijas statusu (`sync_status`) matrica

| Stāvoklis DB | `sync_status` | `sync_status_reason` | Payload saturs |
| :--- | :--- | :--- | :--- |
| `status = 'published'` un `deleted_at IS NULL` | `"active"` | `null` | Pilns pasākums + `occurrences[]` |
| `status = 'cancelled'` un `deleted_at IS NULL` | `"cancelled"` | `"event_cancelled"` | Pilns pasākums + `occurrences[]` |
| `status = 'archived'` un `deleted_at IS NULL` | `"archived"` | `null` | Pilns pasākums + `occurrences[]` |
| `status = 'merged'` ar derīgu mērķi | `"merged"` | `"merged"` / `"migrated"` | **Kompakts tombstone** + saplacināts `merged_into_id` |
| `status = 'merged'` ar dzēstu/trūkstošu mērķi | `"deleted"` | `"merged_target_deleted"` | **Kompakts tombstone** |
| `status = 'deleted'` vai `deleted_at IS NOT NULL` | `"deleted"` | `"soft_deleted"` | **Kompakts tombstone** |
| `status = 'draft'` un `published_at IS NOT NULL` | `"deleted"` | `"unpublished_draft"` | **Kompakts tombstone** |
| `status = 'draft'` un `published_at IS NULL` | *(netiek eksportēts)* | — | Nepublicēti melnraksti nekad neizplūst |

### Atbildes piemērs (`200 OK`)

```json
{
  "success": true,
  "schema_version": 2,
  "mode": "incremental",
  "sync_timestamp": "2026-11-27T11:00:00+02:00",
  "data": [
    {
      "id": 14520,
      "sync_status": "active",
      "sync_status_reason": null,
      "merged_into_id": null,
      "merged_at": null,
      "deleted_at": null,
      "content_hash": "8f434346648f6b96df89dda901c5176b10a6d83961dd3c1ac88b59b2dc327aa4",
      "legacy_event_ids": [14520, 14521, 14529],
      "title": "Kāpa: Otrā daļa",
      "slug": "kapa-otra-dala-a1b2c3",
      "short_description": "Īss apraksts...",
      "description": "Pilns filmas apraksts...",
      "seo_description": "Kāpa: Otrā daļa — Kino...",
      "next_start_at": "2026-11-27T18:30:00+02:00",
      "last_end_at": "2026-11-30T21:15:00+02:00",
      "occurrences_count": 2,
      "is_free": false,
      "price_min": 8.5,
      "price_max": 14.9,
      "currency": "EUR",
      "entertainment_type": "kino",
      "image_url": "https://sodienat.hel1.your-objectstorage.com/events/images/dune2.webp",
      "original_image_url": "https://apollokino.lv/images/dune2.jpg",
      "source_url": "https://www.apollokino.lv/event/1234",
      "web_url": "https://sodiena.lv/events/kapa-otra-dala-a1b2c3",
      "is_featured": false,
      "is_promoted": false,
      "views_count": 312,
      "primary_source": {
        "id": 5,
        "slug": "apollo-kino",
        "name": "Apollo Kino",
        "type": "venue"
      },
      "organizer": null,
      "location": {
        "id": 42,
        "name": "Apollo Kino Akropole Rīga",
        "slug": "apollo-kino-akropole-riga",
        "city": "Rīga",
        "city_slug": "riga",
        "region": "Rīga",
        "address": "Latgales iela 257",
        "latitude": 56.9248,
        "longitude": 24.1753,
        "place_type": "cinema"
      },
      "categories": [
        {
          "id": 3,
          "name": "Kino",
          "slug": "kino"
        }
      ],
      "raw_source_categories": ["Zinātniskā fantastika", "Piedzīvojumi"],
      "language": {
        "mode": "spoken",
        "spoken": ["en"],
        "subtitles": ["lv", "ru"],
        "source": "explicit"
      },
      "occurrences": [
        {
          "id": 90101,
          "status": "scheduled",
          "merged_into_id": null,
          "legacy_event_id": 14520,
          "start_at": "2026-11-27T18:30:00+02:00",
          "end_at": "2026-11-27T21:15:00+02:00",
          "all_day": false,
          "is_free": false,
          "price_min": 12.9,
          "price_max": 14.9,
          "currency": "EUR",
          "ticket_url": "https://www.apollokino.lv/websales/show/534975",
          "ticket_offers": [
            {
              "source_slug": "apollo-kino",
              "provider_name": "Apollo Kino",
              "url": "https://www.apollokino.lv/websales/show/534975",
              "price_min": 12.9,
              "price_max": 14.9,
              "currency": "EUR"
            }
          ],
          "presentation_format": "IMAX 2D",
          "auditorium": "1. IMAX zāle",
          "language": {
            "spoken": ["en"],
            "subtitles": ["lv", "ru"],
            "source": "explicit"
          },
          "cancelled_at": null,
          "updated_at": "2026-11-27T10:15:00+02:00"
        }
      ],
      "published_at": "2026-11-20T09:00:00+02:00",
      "created_at": "2026-11-20T08:55:00+02:00",
      "updated_at": "2026-11-27T10:30:00+02:00"
    },
    {
      "id": 14529,
      "sync_status": "merged",
      "sync_status_reason": "merged",
      "merged_into_id": 14520,
      "merged_at": "2026-11-27T10:40:00+02:00",
      "deleted_at": null,
      "updated_at": "2026-11-27T10:40:00+02:00"
    },
    {
      "id": 14100,
      "sync_status": "deleted",
      "sync_status_reason": "soft_deleted",
      "merged_into_id": null,
      "merged_at": null,
      "deleted_at": "2026-11-27T10:45:00+02:00",
      "updated_at": "2026-11-27T10:45:00+02:00"
    }
  ],
  "meta": {
    "per_page": 100,
    "returned_count": 3,
    "has_more": false,
    "next_cursor": null,
    "resume_cursor": "eyJ1IjoiMjAyNi0xMS0yN1QxMDo0NTowMCswMjowMCIsImkiOjE0MTAwfQ",
    "counts_in_page": {
      "active": 1,
      "merged": 1,
      "cancelled": 0,
      "deleted": 1,
      "archived": 0
    }
  }
}
```

---

## 2. `GET /api/v2/events/stats`

Viegls paritātes pārbaudes galapunkts:

```json
{
  "success": true,
  "schema_version": 2,
  "generated_at": "2026-11-27T11:00:00+02:00",
  "events": {
    "active_upcoming": 3412,
    "active_total": 8920,
    "cancelled_upcoming": 18,
    "merged_total": 10450,
    "deleted_total": 262
  },
  "occurrences": {
    "scheduled_upcoming": 11284,
    "postponed_upcoming": 6,
    "cancelled_upcoming": 43,
    "total_active_or_future": 11333
  },
  "reconciliation": {
    "enabled_sources_count": 4,
    "last_24h_cancelled_occurrences": 12,
    "last_24h_circuit_breaker_trips": 0
  }
}
```

---

## 3. `GET /api/v2/events/{id}`

* Atgriež vienu pasākumu vai tombstone pēc ID.
* Atbalsta `include_legacy_ids=true` un `locale=lv|en|ru`.
* Ja pasākums ir `merged` vai `deleted`, atgriež `200 OK` ar kompakto tombstone payload un saplacinātu `merged_into_id`.
* Ja pasākums ir nepublicēts melnraksts (`draft` ar `published_at = null`), atgriež `404 Not Found`.
