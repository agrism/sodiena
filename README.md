# Šodiena.lv — REST API Dokumentācija

Šodiena.lv nodrošina publisku un aizsargātu REST API saskarni pasākumu atlasei, meklēšanai, filtrēšanai un datu integrācijai trešo pušu lietotnēs.

---

## Saturs

1. [Autentifikācija (Bearer Token)](#1-autentifikācija-bearer-token)
2. [Bāzes URL un Versijas](#2-bāzes-url-un-versijas)
3. [Valodu atbalsts (Lokalizācija)](#3-valodu-atbalsts-lokalizācija)
4. [Datu Glabāšana Pie Sevis & Sinhronizācijas Arhitektūra (piedzivo.lv)](#4-datu-glabāšana-pie-sevis--sinhronizācijas-arhitektūra-piedzivolv)
5. [API Gala Punkti (Endpoints)](#5-api-gala-punkti-endpoints)
   - [5.1. Pasākumu sinhronizācija (`GET /api/v1/events/sync`) — Ieteicamais veids](#51-pasākumu-sinhronizācija-get-apiv1eventssync--ieteicamais-veids)
   - [5.2. Pasākumu saraksts un filtrācija (`GET /api/v1/events`)](#52-pasākumu-saraksts-un-filtrācija-get-apiv1events)
   - [5.3. Viena pasākuma dati pēc ID vai slug (`GET /api/v1/events/{id}`)](#53-viena-pasākuma-dati-pēc-id-vai-slug-get-apiv1eventsid)
   - [5.4. 7 Promotētie / Izceltie pasākumi (`GET /api/v1/events/promoted`)](#54-7-promotētie--izceltie-pasākumi-get-apiv1eventspromoted)
   - [5.5. Pasākuma izcelšanas uzstādīšana pēc ID (`POST /api/v1/events/{id}/promote`)](#55-pasākuma-izcelšanas-uzstādīšana-pēc-id-post-apiv1eventsidpromote)
6. [Kļūdu kodi un Atbildes](#6-kļūdu-kodi-un-atbildes)
7. [Koda Piemēri (Laravel Cron Sync komanda, cURL, JavaScript, PHP, Python)](#7-koda-piemēri)

---

## 1. Autentifikācija (Bearer Token)

Visi API pieprasījumi tiek aizsargāti ar **Bearer Token**.

Lai piekļūtu API, pievienojiet HTTP hederi:
```http
Authorization: Bearer <TAVS_BEARER_TOKENS>
```

### Konfigurācija `.env` failā
Servera pusē tokens tiek konfigurēts projekta `.env` failā:
```env
API_BEARER_TOKEN=sodiena_api_secret_token_change_me
```

> Ja Bearer tokens nav norādīts vai ir nederīgs, API atgriež statusu `401 Unauthorized`.

---

## 2. Bāzes URL un Versijas

* **Bāzes URL:** `https://sodiena.lv/api/v1` *(vai lokālajā vidē `http://localhost:8000/api/v1`)*
* **Neversijotie ceļi (aliases):** pieejami arī saīsinātie ceļi `/api/events`, `/api/events/promoted`, `/api/events/{id}`.

---

## 3. Valodu atbalsts (Lokalizācija)

API atbalsta pasākumu nosaukumu, aprakstu, kategoriju un pilsētu nosaukumu lokalizāciju.

Lai saņemtu datus vēlamajā valodā, pieprasījumam var pievienot parametru `locale` vai hederi `X-Locale`:
* `locale=lv` *(noklusējums)* — Latviešu valoda
* `locale=en` — Angļu valoda
* `locale=ru` — Krievu valoda

---

## 4. Datu Glabāšana Pie Sevis & Sinhronizācijas Arhitektūra (piedzivo.lv)

### Kāpēc ieteicams pasākumus glabāt pie sevis?

Ja pilsētu vietnēs (piemēram, `https://piedzivo.lv/pasakumi/aluksne`) pasākumi tiktu pieprasīti no Šodiena.lv API katru reizi, kad apmeklētājs atver lapu:
* **Tīkla aizture (Latency):** lapas ielāde kļūst par 100–300ms lēnāka, gaidot ārējo HTTP pieprasījumu.
* **Neuzticamība & Atkarība:** ja notiek tīkla traucējumi vai īslaicīgs Šodiena.lv servera restarts, vietne `piedzivo.lv` nevarētu attēlot saturu.
* **Ierobežota elastība:** glabājot datus savā lokālajā datubāzē, pasākumus var brīvi un zibenīgi atlasīt, filtrēt un sasaistīt ar vietējiem tūrisma objektiem, apskates vietām, naktsmītnēm un maršrutiem.

### Kā strādā periodiskā sinhronizācija?

Labākā prakse ir palaist automātisku fonu uzdevumu (piem., reizi stundā ar Laravel Scheduler / Cron):

1. **Pirmreizējā pilnā sinhronizācija (Initial Full Sync):**
   * Klients nosūta `GET /api/v1/events/sync` bez parametra `since`.
   * API atgriež visus gaidāmos publicētos pasākumus (vai visus pasākumus ar `upcoming_only=false`).
   * Klients saglabā pasākumus savā datubāzē un atbildē saņemto laiku `sync_timestamp` (piemēram, `2026-10-27T15:00:00+02:00`) saglabā kā `last_synced_at`.
2. **Inkrementālā sinhronizācija (Hourly Delta Sync):**
   * Reizi stundā klients izsauc `GET /api/v1/events/sync?since=2026-10-27T15:00:00+02:00`.
   * **Jaunie un labotie pasākumi (`data`):** API atgriež tikai tos pasākumus, kas izveidoti vai modificēti pēc šī laika (`updated_at >= since`). Klients veic to pievienošanu vai atjaunošanu (`updateOrCreate`).
   * **Dzēstie un atceltie pasākumi (`deleted_ids`):** API atgriež sarakstu ar pasākumu ID, kas kopš pēdējā laika ir dzēsti (soft-deleted) vai kuru statuss mainīts uz melnrakstu/atcelts (`status != published`). Klients tos izdzēš no savas datubāzes, novēršot neaktuālu pasākumu uzkrāšanos.
   * **Laika zīmoga atjaunošana:** klients saglabā jauno `sync_timestamp`, ko izmantot nākamajā stundā (servera laika izmantošana novērš lokālo pulksteņu nobīdes problēmas).
   * **Lapošana (`meta.has_more`):** ja izmaiņu skaits pārsniedz `per_page` (noklusējums 100, maks. 250), klients nolasa nākamo lapu ar `page=2`.

---

## 5. API Gala Punkti (Endpoints)

### 5.1. Pasākumu sinhronizācija (`GET /api/v1/events/sync`) — Ieteicamais veids

Gala punkts optimālai un ātrai datu sinhronizācijai starp Šodiena.lv un ārējām platformām (piem., `piedzivo.lv`).

#### Pieprasījuma parametri (Query Parameters):

| Parametrs | Tips | Apraksts / Pieejamās vērtības | Piemērs |
| :--- | :--- | :--- | :--- |
| `since` vai `updated_since` | `string` / `int` | Laika zīmogs ISO 8601 formātā vai UNIX timestamps. Ja nav norādīts, tiek veikta **pilnā sinhronizācija**. Ja norādīts, atgriež tikai izmaiņas kopš šī brīža. | `since=2026-10-27T14:00:00+02:00` vai `since=1761566400` |
| `city` | `string` | Pilsēta vai tās slūgs. Atbalsta gan pilnu nosaukumu (`Alūksne`, `Rīga`), gan URL slūgu (`aluksne`, `riga`), kā arī atdalīšanu ar komatu (`aluksne,smiltene`). | `city=aluksne` |
| `category` | `string` / `int` | Kategorijas unikālais `slug` vai ID | `category=koncerti` |
| `upcoming_only` | `boolean` | `true` (noklusējums pilnajai sinhronizācijai bez `since`) atgriež tikai šodienas un gaidāmos pasākumus. `false` (noklusējums delta sinhronizācijai ar `since`) sinhronizē jebkuras izmaiņas. | `upcoming_only=true` |
| `per_page` vai `limit` | `int` | Ierakstu skaits vienā lapā (noklusējums: 100, min: 1, max: 250) | `per_page=100` |
| `page` | `int` | Lapas numurs (noklusējums: 1) | `page=1` |
| `locale` | `string` | Atbildes valoda: `lv`, `en`, `ru` (noklusējums: `lv`) | `locale=lv` |

#### Pieprasījuma piemērs (Inkrementālā stundas sinhronizācija):
```http
GET /api/v1/events/sync?since=2026-10-27T14:00:00%2B02:00&per_page=100 HTTP/1.1
Host: sodiena.lv
Authorization: Bearer sodiena_api_secret_token_change_me
```

#### Atbildes struktūra (`200 OK`):
```json
{
  "success": true,
  "sync_timestamp": "2026-10-27T15:00:00+02:00",
  "data": [
    {
      "id": 105,
      "title": "Grupas 'Instrumenti' lielkoncerts",
      "slug": "grupas-instrumenti-lielkoncerts-abc123",
      "status": "published",
      "short_description": "Īss pasākuma kopsavilkums...",
      "description": "Pilns pasākuma apraksts...",
      "seo_description": "Grupas 'Instrumenti' lielkoncerts — Koncerti...",
      "start_at": "2026-10-15T19:00:00+03:00",
      "end_at": "2026-10-15T22:00:00+03:00",
      "all_day": false,
      "formatted_date": "15. okt. plkst. 19:00",
      "is_free": false,
      "price_min": 25.0,
      "price_max": 65.0,
      "currency": "EUR",
      "formatted_price": "€25 - €65",
      "entertainment_type": "concert",
      "localized_entertainment_type": "Koncerts",
      "image_url": "https://sodienat.hel1.your-objectstorage.com/events/images/concert.webp",
      "original_image_url": "https://source.com/img.jpg",
      "ticket_url": "https://www.bilesuparadize.lv/lv/event/12345",
      "source_url": "https://instrumenti.lv",
      "ticket_links": [
        {
          "url": "https://www.bilesuparadize.lv/lv/event/12345",
          "title": "Biļešu Paradīze",
          "label": "Pirkt biļetes (Biļešu Paradīze)",
          "platform": "bilesuparadize"
        }
      ],
      "display_venue": "Arēna Rīga",
      "organizer": {
        "name": "Instrumenti Management",
        "url": "https://instrumenti.lv"
      },
      "location": {
        "id": 12,
        "name": "Arēna Rīga",
        "slug": "arena-riga-riga",
        "city": "Rīga",
        "city_slug": "riga",
        "region": "Rīgas reģions",
        "address": "Skanstes iela 21",
        "latitude": 56.9681,
        "longitude": 24.1205,
        "place_type": "venue"
      },
      "categories": [
        {
          "id": 1,
          "name": "Koncerti",
          "slug": "koncerti",
          "icon": "music",
          "color": "rose"
        }
      ],
      "source_slug": "bilesu-paradize",
      "is_featured": true,
      "is_promoted": true,
      "views_count": 1420,
      "web_url": "https://sodiena.lv/pasakumi/grupas-instrumenti-lielkoncerts-abc123",
      "published_at": "2026-09-01T10:00:00+03:00",
      "created_at": "2026-09-01T09:30:00+03:00",
      "updated_at": "2026-10-27T14:25:00+03:00",
      "is_deleted": false,
      "deleted_at": null
    }
  ],
  "deleted_ids": [42, 89],
  "meta": {
    "total_updated": 1,
    "total_deleted": 2,
    "current_page": 1,
    "last_page": 1,
    "per_page": 100,
    "has_more": false
  },
  "links": {
    "first": "https://sodiena.lv/api/v1/events/sync?since=2026-10-27T14:00:00%2B02:00&page=1",
    "last": "https://sodiena.lv/api/v1/events/sync?since=2026-10-27T14:00:00%2B02:00&page=1",
    "prev": null,
    "next": null
  }
}
```

---

### 5.2. Pasākumu saraksts un filtrācija (`GET /api/v1/events`)

Atgriež publicēto pasākumu sarakstu ar pagināciju un elastīgām meklēšanas/filtrēšanas iespējām.

#### Pieprasījuma parametri (Query Parameters):

| Parametrs | Tips | Apraksts / Pieejamās vērtības | Piemērs |
| :--- | :--- | :--- | :--- |
| `search` vai `q` | `string` | Meklēšana pēc atslēgvārda nosaukumā, aprakstā, vietā vai kategorijā | `search=koncerts` |
| `category` | `string` / `int` | Kategorijas unikālais `slug` vai ID | `category=koncerti`, `category=kino`, `category=teatris` |
| `city` | `string` | Pilsēta vai URL slūgs (piem., `Alūksne` vai `aluksne`, `Rīga` vai `riga`, atbalsta vairākas `aluksne,riga`) | `city=aluksne` |
| `updated_since` vai `since` | `string` / `int` | Filtrēt pasākumus, kas atjaunoti kopš konkrēta laika (ISO 8601 vai UNIX timestamp) | `updated_since=2026-10-27T14:00:00+02:00` |
| `period` | `string` | Laika periods: `today`, `tomorrow`, `this_week`, `weekend`, `this_month`, `all` | `period=weekend` |
| `date` | `string` | Konkrēts datums formātā `YYYY-MM-DD` | `date=2026-10-15` |
| `date_from` | `string` | Datuma diapazona sākums `YYYY-MM-DD` | `date_from=2026-10-01` |
| `date_to` | `string` | Datuma diapazona beigas `YYYY-MM-DD` | `date_to=2026-10-31` |
| `type` | `string` | Izklaides tips: `concert`, `movie`, `theatre`, `performance`, `show`, `festival`, `sports`, `exhibition`, `workshop`, `chill`, `active`, `family`, `party` | `type=concert` |
| `price` | `string` | Cenu filtrs: `free` (bezmaksas) vai `paid` (maksas) | `price=free` |
| `is_free` | `boolean` | Alternatīvs bezmaksas filtrs: `true` / `false` / `1` / `0` | `is_free=true` |
| `is_featured` | `boolean` | Tikai izceltie pasākumi: `true` / `false` / `1` / `0` | `is_featured=true` |
| `is_promoted` | `boolean` | Filtrēt pēc promovētiem pasākumiem: `true` / `false` / `1` / `0` | `is_promoted=true` |
| `source` | `string` | Datu avota `slug` (piem., `bilesu-paradize`, `bilesu-serviss`, `bezrindas`, `afiro-api`) | `source=bilesu-paradize` |
| `sort` | `string` | Kārtošana: `date_asc` (noklusējums), `date_desc`, `views_desc` (vai `popular`), `newest` (vai `created_desc`), `updated_desc`, `updated_asc`, `price_asc`, `price_desc` | `sort=updated_desc` |
| `locale` | `string` | Atbildes valoda: `lv`, `en`, `ru` (noklusējums: `lv`) | `locale=lv` |
| `per_page` vai `limit` | `int` | Elementu skaits vienā lapā (noklusējums: 20, min: 1, max: 250) | `per_page=100` |
| `page` | `int` | Lapas numurs (noklusējums: 1) | `page=1` |

#### Pieprasījuma piemērs:
```http
GET /api/v1/events?city=aluksne&period=all&per_page=20 HTTP/1.1
Host: sodiena.lv
Authorization: Bearer sodiena_api_secret_token_change_me
```

#### Atbildes struktūra (`200 OK`):
```json
{
  "data": [
    {
      "id": 105,
      "title": "Grupas 'Instrumenti' lielkoncerts",
      "slug": "grupas-instrumenti-lielkoncerts-abc123",
      "status": "published",
      "short_description": "Īss pasākuma kopsavilkums un galvenā informācija...",
      "description": "Pilns pasākuma apraksts ar visām detaļām...",
      "seo_description": "Grupas 'Instrumenti' lielkoncerts — Koncerti (Arēna Rīga, 15. okt. plkst. 19:00). Informācija un biļetes vietnē Šodiena.",
      "start_at": "2026-10-15T19:00:00+03:00",
      "end_at": "2026-10-15T22:00:00+03:00",
      "all_day": false,
      "formatted_date": "15. okt. plkst. 19:00",
      "is_free": false,
      "price_min": 25.0,
      "price_max": 65.0,
      "currency": "EUR",
      "formatted_price": "€25 - €65",
      "entertainment_type": "concert",
      "localized_entertainment_type": "Koncerts",
      "image_url": "https://sodienat.hel1.your-objectstorage.com/events/images/concert.webp",
      "original_image_url": "https://source.com/img.jpg",
      "ticket_url": "https://www.bilesuparadize.lv/lv/event/12345",
      "source_url": "https://instrumenti.lv",
      "ticket_links": [
        {
          "url": "https://www.bilesuparadize.lv/lv/event/12345",
          "title": "Biļešu Paradīze",
          "label": "Pirkt biļetes (Biļešu Paradīze)",
          "platform": "bilesuparadize"
        }
      ],
      "display_venue": "Arēna Rīga",
      "organizer": {
        "name": "Instrumenti Management",
        "url": "https://instrumenti.lv"
      },
      "location": {
        "id": 12,
        "name": "Arēna Rīga",
        "slug": "arena-riga-riga",
        "city": "Rīga",
        "city_slug": "riga",
        "region": "Rīgas reģions",
        "address": "Skanstes iela 21",
        "latitude": 56.9681,
        "longitude": 24.1205,
        "place_type": "venue"
      },
      "categories": [
        {
          "id": 1,
          "name": "Koncerti",
          "slug": "koncerti",
          "icon": "music",
          "color": "rose"
        }
      ],
      "source_slug": "bilesu-paradize",
      "is_featured": true,
      "is_promoted": true,
      "views_count": 1420,
      "web_url": "https://sodiena.lv/pasakumi/grupas-instrumenti-lielkoncerts-abc123",
      "published_at": "2026-09-01T10:00:00+03:00",
      "created_at": "2026-09-01T09:30:00+03:00",
      "updated_at": "2026-09-20T14:15:00+03:00",
      "is_deleted": false,
      "deleted_at": null
    }
  ],
  "links": {
    "first": "https://sodiena.lv/api/v1/events?page=1",
    "last": "https://sodiena.lv/api/v1/events?page=8",
    "prev": null,
    "next": "https://sodiena.lv/api/v1/events?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 8,
    "per_page": 10,
    "to": 10,
    "total": 78
  }
}
```

---

### 5.3. Viena pasākuma dati pēc ID vai slug (`GET /api/v1/events/{id}`)

Atgriež pilnu viena konkrēta pasākuma informāciju pēc tā datubāzes ID (vai `slug`).

#### Pieprasījuma parametri:
* **Ceļa parametrs (Path parameter):**
  * `id` *(obligāts)* — Pasākuma ID skaitlis (vai unikālais `slug`).
* **Query parametri:**
  * `locale` *(neobligāts)* — `lv`, `en`, `ru` (noklusējums: `lv`).

#### Pieprasījuma piemērs:
```http
GET /api/v1/events/105 HTTP/1.1
Host: sodiena.lv
Authorization: Bearer sodiena_api_secret_token_change_me
```

#### Atbildes struktūra (`200 OK`):
```json
{
  "data": {
    "id": 105,
    "title": "Grupas 'Instrumenti' lielkoncerts",
    "slug": "grupas-instrumenti-lielkoncerts-abc123",
    "short_description": "Īss pasākuma kopsavilkums un galvenā informācija...",
    "description": "Pilns apraksts...",
    "seo_description": "Grupas 'Instrumenti' lielkoncerts — Koncerti (Arēna Rīga, 15. okt. plkst. 19:00). Informācija un biļetes vietnē Šodiena.",
    "start_at": "2026-10-15T19:00:00+03:00",
    "end_at": "2026-10-15T22:00:00+03:00",
    "all_day": false,
    "formatted_date": "15. okt. plkst. 19:00",
    "is_free": false,
    "price_min": 25.0,
    "price_max": 65.0,
    "currency": "EUR",
    "formatted_price": "€25 - €65",
    "entertainment_type": "concert",
    "localized_entertainment_type": "Koncerts",
    "image_url": "https://sodienat.hel1.your-objectstorage.com/events/images/concert.webp",
    "original_image_url": "https://source.com/img.jpg",
    "ticket_url": "https://www.bilesuparadize.lv/lv/event/12345",
    "source_url": "https://instrumenti.lv",
    "ticket_links": [
      {
        "url": "https://www.bilesuparadize.lv/lv/event/12345",
        "title": "Biļešu Paradīze",
        "label": "Pirkt biļetes (Biļešu Paradīze)",
        "platform": "bilesuparadize"
      }
    ],
    "display_venue": "Arēna Rīga",
    "organizer": {
      "name": "Instrumenti Management",
      "url": "https://instrumenti.lv"
    },
    "location": {
      "id": 12,
      "name": "Arēna Rīga",
      "slug": "arena-riga-riga",
      "city": "Rīga",
      "region": "Rīgas reģions",
      "address": "Skanstes iela 21",
      "latitude": 56.9681,
      "longitude": 24.1205,
      "place_type": "venue"
    },
    "categories": [
      {
        "id": 1,
        "name": "Koncerti",
        "slug": "koncerti",
        "icon": "music",
        "color": "rose"
      }
    ],
    "source_slug": "bilesu-paradize",
    "is_featured": true,
    "is_promoted": true,
    "views_count": 1420,
    "web_url": "https://sodiena.lv/pasakumi/grupas-instrumenti-lielkoncerts-abc123",
    "published_at": "2026-09-01T10:00:00+03:00",
    "created_at": "2026-09-01T09:30:00+03:00",
    "updated_at": "2026-09-20T14:15:00+03:00"
  }
}
```

---

### 5.4. 7 Promotētie / Izceltie pasākumi (`GET /api/v1/events/promoted`)

Šis gala punkts ir īpaši izveidots sākumlapas karuseļiem, baneriem un ieteikumiem, lai atlasītu **7 aktuālākos un svarīgākos pasākumus**.

#### Atlases loģika:
1. Atlasa tikai publicētus un nākotnes/šodienas pasākumus (`start_at >= šodiena` vai aktīvus vairāku dienu pasākumus).
2. **Prioritāte:** Vispirms iekļauj administratora izceltos un promovētos pasākumus (`is_promoted = true` vai `is_featured = true`).
3. **Papildināšana:** Ja izcelto pasākumu ir mazāk par 7, atlikušās vietas tiek automātiski aizpildītas ar populārākajiem gaidāmajiem pasākumiem (kārtoti pēc `views_count DESC, start_at ASC`), tādējādi **garantējot tieši 7 pasākumu atgriešanu**.

#### Pieprasījuma parametri (Query Parameters):
* `limit` *(neobligāts)* — Skaits (noklusējums: `7`, min: `1`, max: `20`).
* `locale` *(neobligāts)* — `lv`, `en`, `ru` (noklusējums: `lv`).

#### Pieprasījuma piemērs:
```http
GET /api/v1/events/promoted HTTP/1.1
Host: sodiena.lv
Authorization: Bearer sodiena_api_secret_token_change_me
```

#### Atbildes struktūra (`200 OK`):
```json
{
  "data": [
    {
      "id": 105,
      "title": "Grupas 'Instrumenti' lielkoncerts",
      "slug": "grupas-instrumenti-lielkoncerts-abc123",
      "start_at": "2026-10-15T19:00:00+03:00",
      "formatted_date": "15. okt. plkst. 19:00",
      "is_free": false,
      "formatted_price": "€25 - €65",
      "image_url": "https://sodienat.hel1.your-objectstorage.com/events/images/concert.webp",
      "display_venue": "Arēna Rīga",
      "ticket_url": "https://www.bilesuparadize.lv/lv/event/12345",
      "web_url": "https://sodiena.lv/pasakumi/grupas-instrumenti-lielkoncerts-abc123",
      "is_featured": true,
      "is_promoted": true
    }
  ]
}
```

---

### 5.5. Pasākuma izcelšanas uzstādīšana pēc ID (`POST /api/v1/events/{id}/promote`)

Ļauj ar Bearer tokenu uzlikt vai noņemt pasākumam `is_promoted` statusu pēc pasākuma ID vai `slug`.

#### Pieprasījuma metodes un ceļi:
* `POST /api/v1/events/{id}/promote` *(atbalsta arī `PATCH`)*
* `POST /api/v1/events/{id}/unpromote` *(tiešs noņemšanas ceļš)*
* `PATCH /api/v1/events/{id}` ar ķermeni `{"is_promoted": true}`

#### Ceļa parametri (Path Parameters):
* `id` *(obligāts)* — Pasākuma ID vai unikālais `slug`.

#### Pieprasījuma ķermenis (Request Body, neobligāts):
```json
{
  "is_promoted": true
}
```
*Piezīme:* Ja ķermenis netiek padots, izsaucot `/promote`, pasākumam pēc noklusējuma tiek uzlikts `is_promoted = true`. Lai noņemtu izcelšanu, sūtiet `{"is_promoted": false}` vai izsauciet `/unpromote`.

#### Pieprasījuma piemērs:
```http
POST /api/v1/events/105/promote HTTP/1.1
Host: sodiena.lv
Authorization: Bearer sodiena_api_secret_token_change_me
Content-Type: application/json

{
  "is_promoted": true
}
```

#### Atbildes struktūra (`200 OK`):
```json
{
  "success": true,
  "message": "Event marked as promoted.",
  "is_promoted": true,
  "data": {
    "id": 105,
    "title": "Grupas 'Instrumenti' lielkoncerts",
    "is_promoted": true
  }
}
```

---

## 6. Kļūdu kodi un Atbildes

API izmanto standarta HTTP statusa kodus:

### `401 Unauthorized`
Tiek atgriezts, ja `Authorization: Bearer <token>` hederis nav norādīts vai norādītais tokens nesakrīt ar `.env` konfigurāciju.
```json
{
  "success": false,
  "message": "Unauthenticated. Invalid or missing Bearer token."
}
```

### `404 Not Found`
Tiek atgriezts, ja pieprasītais pasākuma ID neeksistē vai pasākums vēl nav publicēts.
```json
{
  "success": false,
  "message": "Event not found or not published."
}
```

### `422 Unprocessable Content`
Tiek atgriezts, ja padotais laika zīmogs (piem., `since`) nav derīgā formātā.
```json
{
  "success": false,
  "message": "Invalid timestamp format for \"since\". Please use ISO 8601 (e.g. 2026-10-27T14:00:00Z) or a UNIX timestamp."
}
```

---

## 7. Koda Piemēri

### 7.1. Gatava Laravel Sinhronizācijas Komanda (`piedzivo.lv`)

Šo komandu var iekļaut savā Laravel projektā (piem., vietnē `piedzivo.lv`), lai pasākumi glabātos vietējā datubāzē un automātiski reizi stundā tiktu atjaunoti fonā.

#### Komandas fails: `app/Console/Commands/SyncSodienaEventsCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Event; // Piedzivo.lv vietējais pasākumu modelis
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSodienaEventsCommand extends Command
{
    protected $signature = 'sync:sodiena-events {--full : Veikt pilnu sinhronizāciju, ignorējot pēdējo laika zīmogu}';
    protected $description = 'Sinhronizē jaunos, labotos un dzēstos pasākumus no Sodiena.lv API';

    public function handle(): int
    {
        $apiBase = config('services.sodiena.base_url', 'https://sodiena.lv/api/v1');
        $token = config('services.sodiena.api_token') ?? env('SODIENA_API_TOKEN');

        if (empty($token)) {
            $this->error('Trūkst SODIENA_API_TOKEN konfigurācijas!');
            return self::FAILURE;
        }

        $isFull = $this->option('full');
        $cacheKey = 'sodiena_events_last_synced_at';
        $since = $isFull ? null : Cache::get($cacheKey);

        $this->info($since ? "Sinhronizē izmaiņas kopš: {$since}" : 'Uzsāk pilno pasākumu sinhronizāciju...');

        $page = 1;
        $totalSynced = 0;
        $newTimestamp = null;

        do {
            $params = [
                'per_page' => 100,
                'page' => $page,
            ];

            if (!empty($since)) {
                $params['since'] = $since;
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get("{$apiBase}/events/sync", $params);

            if (!$response->successful()) {
                $this->error("API pieprasījuma kļūda (HTTP {$response->status()}): " . $response->body());
                Log::error('Sodiena.lv sync failed', ['status' => $response->status(), 'body' => $response->body()]);
                return self::FAILURE;
            }

            $data = $response->json();
            $newTimestamp = $data['sync_timestamp'] ?? $newTimestamp;
            $events = $data['data'] ?? [];
            $deletedIds = $data['deleted_ids'] ?? [];

            // 1. Apstrādā dzēstos un atceltos pasākumus
            if (!empty($deletedIds)) {
                $deletedCount = Event::whereIn('sodiena_id', $deletedIds)->delete();
                $this->warn("Dzēsti/atcelti {$deletedCount} pasākumi.");
            }

            // 2. Pievieno un atjauno modificētos pasākumus
            foreach ($events as $item) {
                Event::updateOrCreate(
                    ['sodiena_id' => $item['id']],
                    [
                        'title' => $item['title'],
                        'slug' => $item['slug'],
                        'status' => $item['status'],
                        'short_description' => $item['short_description'],
                        'description' => $item['description'],
                        'start_at' => $item['start_at'],
                        'end_at' => $item['end_at'],
                        'all_day' => $item['all_day'],
                        'is_free' => $item['is_free'],
                        'price_min' => $item['price_min'],
                        'price_max' => $item['price_max'],
                        'currency' => $item['currency'] ?? 'EUR',
                        'image_url' => $item['image_url'],
                        'ticket_url' => $item['ticket_url'],
                        'display_venue' => $item['display_venue'],
                        'city' => $item['location']['city'] ?? null,
                        'city_slug' => $item['location']['city_slug'] ?? null,
                        'is_promoted' => $item['is_promoted'],
                        'is_featured' => $item['is_featured'],
                        'sodiena_updated_at' => $item['updated_at'],
                    ]
                );
                $totalSynced++;
            }

            $hasMore = $data['meta']['has_more'] ?? false;
            $page++;

        } while ($hasMore);

        // Saglabā jaunāko servera laika zīmogu nākamajai stundai
        if ($newTimestamp) {
            Cache::forever($cacheKey, $newTimestamp);
        }

        $this->info("Sinhronizācija veiksmīga! Atjaunoti/pievienoti {$totalSynced} pasākumi. Nākamā atzīme: {$newTimestamp}");
        return self::SUCCESS;
    }
}
```

#### Plānotāja reģistrācija (`routes/console.php` vai `bootstrap/app.php`):
```php
use Illuminate\Support\Facades\Schedule;

// Reizi stundā fonā pārbauda jaunos un labotos pasākumus
Schedule::command('sync:sodiena-events')->hourly();
```

#### Lokālā pilsētas kontroliera piemērs (`piedzivo.lv`):
Tagad, kad dati glabājas lokāli, atverot `https://piedzivo.lv/pasakumi/aluksne`, pieprasījums izpildās lokālajā datubāzē dažās milisekundēs:
```php
// Piemērs vietnē Piedzivo.lv (kontrolierī):
public function cityEvents(string $citySlug)
{
    $events = Event::where('city_slug', $citySlug)
        ->where('status', 'published')
        ->where(function ($q) {
            $q->where('start_at', '>=', now())
              ->orWhere('end_at', '>=', now());
        })
        ->orderBy('is_promoted', 'desc')
        ->orderBy('start_at', 'asc')
        ->paginate(20);

    return view('city.events', compact('events', 'citySlug'));
}
```

---

### 7.2. cURL

```bash
# 1. Inkrementālā stundas sinhronizācija ar laika zīmogu
curl -X GET "https://sodiena.lv/api/v1/events/sync?since=2026-10-27T14:00:00%2B02:00&per_page=100" \
  -H "Authorization: Bearer sodiena_api_secret_token_change_me" \
  -H "Accept: application/json"

# 2. Sinhronizēt tikai Alūksnes pasākumus pēc slūga
curl -X GET "https://sodiena.lv/api/v1/events/sync?city=aluksne" \
  -H "Authorization: Bearer sodiena_api_secret_token_change_me" \
  -H "Accept: application/json"

# 3. Iegūt 7 promotētos pasākumus
curl -X GET "https://sodiena.lv/api/v1/events/promoted" \
  -H "Authorization: Bearer sodiena_api_secret_token_change_me" \
  -H "Accept: application/json"

# 4. Iegūt konkrēta pasākuma datus pēc ID
curl -X GET "https://sodiena.lv/api/v1/events/105" \
  -H "Authorization: Bearer sodiena_api_secret_token_change_me" \
  -H "Accept: application/json"
```

---

### 7.3. JavaScript / TypeScript (Node.js & Fetch)

```javascript
const API_BASE = 'https://sodiena.lv/api/v1';
const BEARER_TOKEN = 'sodiena_api_secret_token_change_me';

/**
 * Inkrementālā pasākumu sinhronizācija
 * @param {string|null} lastSyncedAt ISO 8601 laika zīmogs (piem. '2026-10-27T14:00:00Z')
 */
async function syncEvents(lastSyncedAt = null) {
  const url = new URL(`${API_BASE}/events/sync`);
  url.searchParams.set('per_page', '100');
  if (lastSyncedAt) {
    url.searchParams.set('since', lastSyncedAt);
  }

  const response = await fetch(url.toString(), {
    headers: {
      'Authorization': `Bearer ${BEARER_TOKEN}`,
      'Accept': 'application/json',
    },
  });

  if (!response.ok) {
    throw new Error(`Sinhronizācijas kļūda: ${response.status}`);
  }

  const { data, deleted_ids, sync_timestamp, meta } = await response.json();

  console.log(`Saņemti ${data.length} jauni/modificēti pasākumi`);
  console.log(`Dzēsti/atcelti ID:`, deleted_ids);
  console.log(`Nākamais laika zīmogs: ${sync_timestamp}`);

  return { events: data, deletedIds: deleted_ids, nextSyncAt: sync_timestamp };
}
```

---

### 7.4. PHP (Laravel Http Client)

```php
use Illuminate\Support\Facades\Http;

$token = config('services.sodiena.api_token') ?? env('API_BEARER_TOKEN');

// Sinhronizēt izmaiņas kopš pēdējās stundas
$response = Http::withToken($token)
    ->acceptJson()
    ->get('https://sodiena.lv/api/v1/events/sync', [
        'since' => '2026-10-27T14:00:00+02:00',
        'city' => 'aluksne', // Pēc izvēles: tikai konkrēta pilsēta
        'per_page' => 100,
    ]);

$result = $response->json();
$updatedEvents = $result['data'];
$deletedIds = $result['deleted_ids'];
$nextTimestamp = $result['sync_timestamp'];
```

---

### 7.5. Python (Requests)

```python
import requests

API_URL = "https://sodiena.lv/api/v1/events/sync"
HEADERS = {
    "Authorization": "Bearer sodiena_api_secret_token_change_me",
    "Accept": "application/json"
}

params = {
    "since": "2026-10-27T14:00:00+02:00",
    "per_page": 100
}

response = requests.get(API_URL, headers=HEADERS, params=params)

if response.status_code == 200:
    payload = response.json()
    print(f"Servera laiks: {payload['sync_timestamp']}")
    print(f"Atjaunotie pasākumi: {len(payload['data'])}")
    print(f"Dzēstie pasākumu ID: {payload['deleted_ids']}")
else:
    print(f"Kļūda: {response.status_code} - {response.text}")
```

