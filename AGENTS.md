# Sodiena.lv Agent Instructions & Domain Rules

## Event Categorization Rules
1. **Cinema / Kino (Strict & Priority)**:
   - **Venues**: `K. Suns` (or `K.Suns`, `K Suns`), `Kino Rio` (Ventspils Kino Rio), `Forum Cinemas`, `Apollo Kino`, `Cinamon`, `Splendid Palace`, `Kino Bize`, `Kino Lora`, `Kino Gaisma` are **ALWAYS KINO** and **NEVER TEĀTRIS**.
   - **Content**: Any event with `filma`, `filmas`, `filmu`, `filmā`, `kinoseanss`, `filmas seanss`, `spēlfilma`, `dokumentālā filma`, `animācijas filma`, `kinofestivāls` is **KINO** (`kino`).
   - `kinoteātris` contains substring `teātr`, but MUST NEVER be classified as `Teātris` (`teatris`).
2. **Theaters**:
   - Only actual theater venues (*JRT*, *Dailes*, *Nacionālais*, *Valmieras*, *Liepājas*, *Čehova*, *Dirty Deal*, *Ģertrūdes*, *Leļļu*) and actual theater productions (*izrāde*, *luga*, *iestudējums*, *opera*, *balets*).
3. **Fallback**:
   - If an event has no clear specific category -> default to **Cits** (`citi`).

## Event Maintenance & Scope Rules
1. **No Past Events Modification**:
   - Do NOT modify, resync, or mutate events that have already ended in the past (`end_at < today` or `start_at < today`).
   - All translation syncing, data repairs, and scraping updates must strictly target **current and upcoming events**.

