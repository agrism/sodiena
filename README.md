# Šodiena.lv — REST API Dokumentācija

Šodiena.lv nodrošina publisku un aizsargātu REST API saskarni pasākumu atlasei, meklēšanai, filtrēšanai un datu integrācijai trešo pušu lietotnēs.

---

## Saturs

1. [Autentifikācija (Bearer Token)](#1-autentifikācija-bearer-token)
2. [Bāzes URL un Versijas](#2-bāzes-url-un-versijas)
3. [Valodu atbalsts (Lokalizācija)](#3-valodu-atbalsts-lokalizācija)
4. [API Gala Punkti (Endpoints)](#4-api-gala-punkti-endpoints)
   - [4.1. Pasākumu saraksts un filtrācija (`GET /api/v1/events`)](#41-pasākumu-saraksts-un-filtrācija-get-apiv1events)
   - [4.2. Viena pasākuma dati pēc ID (`GET /api/v1/events/{id}`)](#42-viena-pasākuma-dati-pēc-id-get-apiv1eventsid)
   - [4.3. 7 Promotētie / Izceltie pasākumi (`GET /api/v1/events/promoted`)](#43-7-promotētie--izceltie-pasākumi-get-apiv1eventspromoted)
5. [Kļūdu kodi un Atbildes](#5-kļūdu-kodi-un-atbildes)
6. [Koda Piemēri (cURL, JavaScript, PHP, Python)](#6-koda-piemēri)

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

## 4. API Gala Punkti (Endpoints)

### 4.1. Pasākumu saraksts un filtrācija (`GET /api/v1/events`)

Atgriež publicēto pasākumu sarakstu ar pagināciju un elastīgām meklēšanas/filtrēšanas iespējām.

#### Pieprasījuma parametri (Query Parameters):

| Parametrs | Tips | Apraksts / Pieejamās vērtības | Piemērs |
| :--- | :--- | :--- | :--- |
| `search` vai `q` | `string` | Meklēšana pēc atslēgvārda nosaukumā, aprakstā, vietā vai kategorijā | `search=koncerts` |
| `category` | `string` / `int` | Kategorijas unikālais `slug` vai ID | `category=koncerti`, `category=kino`, `category=teatris` |
| `city` | `string` | Pilsēta | `city=Rīga`, `city=Liepāja`, `city=Ventspils` |
| `period` | `string` | Laika periods: `today`, `tomorrow`, `this_week`, `weekend`, `this_month`, `all` | `period=weekend` |
| `date` | `string` | Konkrēts datums formātā `YYYY-MM-DD` | `date=2026-10-15` |
| `date_from` | `string` | Datuma diapazona sākums `YYYY-MM-DD` | `date_from=2026-10-01` |
| `date_to` | `string` | Datuma diapazona beigas `YYYY-MM-DD` | `date_to=2026-10-31` |
| `type` | `string` | Izklaides tips: `concert`, `movie`, `theatre`, `performance`, `show`, `festival`, `sports`, `exhibition`, `workshop`, `chill`, `active`, `family`, `party` | `type=concert` |
| `price` | `string` | Cenu filtrs: `free` (bezmaksas) vai `paid` (maksas) | `price=free` |
| `is_free` | `boolean` | Alternatīvs bezmaksas filtrs: `true` / `false` / `1` / `0` | `is_free=true` |
| `is_featured` | `boolean` | Tikai izceltie pasākumi: `true` / `false` / `1` / `0` | `is_featured=true` |
| `source` | `string` | Datu avota `slug` (piem., `bilesu-paradize`, `bilesu-serviss`, `bezrindas`, `afiro-api`) | `source=bilesu-paradize` |
| `sort` | `string` | Kārtošana: `date_asc` (noklusējums), `date_desc`, `views_desc` (vai `popular`), `newest` (vai `created_desc`), `price_asc`, `price_desc` | `sort=popular` |
| `locale` | `string` | Atbildes valoda: `lv`, `en`, `ru` (noklusējums: `lv`) | `locale=lv` |
| `per_page` vai `limit` | `int` | Elementu skaits vienā lapā (noklusējums: 20, min: 1, max: 100) | `per_page=20` |
| `page` | `int` | Lapas numurs (noklusējums: 1) | `page=1` |

#### Pieprasījuma piemērs:
```http
GET /api/v1/events?city=Rīga&period=weekend&type=concert&per_page=10 HTTP/1.1
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
      "views_count": 1420,
      "web_url": "https://sodiena.lv/pasakumi/grupas-instrumenti-lielkoncerts-abc123",
      "published_at": "2026-09-01T10:00:00+03:00",
      "created_at": "2026-09-01T09:30:00+03:00",
      "updated_at": "2026-09-20T14:15:00+03:00"
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

### 4.2. Viena pasākuma dati pēc ID (`GET /api/v1/events/{id}`)

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
    "views_count": 1420,
    "web_url": "https://sodiena.lv/pasakumi/grupas-instrumenti-lielkoncerts-abc123",
    "published_at": "2026-09-01T10:00:00+03:00",
    "created_at": "2026-09-01T09:30:00+03:00",
    "updated_at": "2026-09-20T14:15:00+03:00"
  }
}
```

---

### 4.3. 7 Promotētie / Izceltie pasākumi (`GET /api/v1/events/promoted`)

Šis gala punkts ir īpaši izveidots sākumlapas karuseļiem, baneriem un ieteikumiem, lai atlasītu **7 aktuālākos un svarīgākos pasākumus**.

#### Atlases loģika:
1. Atlasa tikai publicētus un nākotnes/šodienas pasākumus (`start_at >= šodiena` vai aktīvus vairāku dienu pasākumus).
2. **Prioritāte:** Vispirms iekļauj administratora izceltos pasākumus (`is_featured = true`).
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
      "is_featured": true
    }
  ]
}
```

---

## 5. Kļūdu kodi un Atbildes

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

---

## 6. Koda Piemēri

### cURL

```bash
# 1. Atlasīt 7 promotētos pasākumus
curl -X GET "https://sodiena.lv/api/v1/events/promoted" \
  -H "Authorization: Bearer sodiena_api_secret_token_change_me" \
  -H "Accept: application/json"

# 2. Atlasīt Rīgas koncertus šai nedēļas nogalei
curl -X GET "https://sodiena.lv/api/v1/events?city=R%C4%ABga&type=concert&period=weekend" \
  -H "Authorization: Bearer sodiena_api_secret_token_change_me" \
  -H "Accept: application/json"

# 3. Iegūt pasākuma datus pēc ID
curl -X GET "https://sodiena.lv/api/v1/events/105" \
  -H "Authorization: Bearer sodiena_api_secret_token_change_me" \
  -H "Accept: application/json"
```

---

### JavaScript / TypeScript (Fetch)

```javascript
const API_BASE = 'https://sodiena.lv/api/v1';
const BEARER_TOKEN = 'sodiena_api_secret_token_change_me';

async function fetchPromotedEvents() {
  const response = await fetch(`${API_BASE}/events/promoted`, {
    headers: {
      'Authorization': `Bearer ${BEARER_TOKEN}`,
      'Accept': 'application/json',
    },
  });

  if (!response.ok) {
    throw new Error(`Kļūda: ${response.status}`);
  }

  const result = await response.json();
  console.log('7 Promotētie pasākumi:', result.data);
  return result.data;
}

async function searchEvents(searchQuery, city = 'Rīga') {
  const params = new URLSearchParams({
    search: searchQuery,
    city: city,
    locale: 'lv',
  });

  const response = await fetch(`${API_BASE}/events?${params.toString()}`, {
    headers: {
      'Authorization': `Bearer ${BEARER_TOKEN}`,
      'Accept': 'application/json',
    },
  });

  const result = await response.json();
  return result;
}
```

---

### PHP (Laravel Http Client / Guzzle)

```php
use Illuminate\Support\Facades\Http;

$token = config('services.sodiena.api_token') ?? env('API_BEARER_TOKEN');

// 1. Iegūt 7 promotētos pasākumus
$response = Http::withToken($token)
    ->acceptJson()
    ->get('https://sodiena.lv/api/v1/events/promoted');

$promotedEvents = $response->json('data');

// 2. Filtrēt pasākumus
$response = Http::withToken($token)
    ->acceptJson()
    ->get('https://sodiena.lv/api/v1/events', [
        'city' => 'Rīga',
        'period' => 'this_week',
        'price' => 'free',
        'per_page' => 15,
    ]);

$events = $response->json('data');
$pagination = $response->json('meta');
```

---

### Python (Requests)

```python
import requests

API_URL = "https://sodiena.lv/api/v1/events/promoted"
HEADERS = {
    "Authorization": "Bearer sodiena_api_secret_token_change_me",
    "Accept": "application/json"
}

response = requests.get(API_URL, headers=HEADERS)

if response.status_code == 200:
    events = response.json().get("data", [])
    for event in events:
        print(f"[{event['formatted_date']}] {event['title']} @ {event['display_venue']}")
else:
    print(f"Kļūda: {response.status_code} - {response.text}")
```
