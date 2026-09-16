# Event Categorization Rules & Guidelines

These rules are permanent domain logic for `sodiena.lv` event ingestion, parsing, and categorization.

## 1. Cinema / Kino Rules (Strict & Priority)
- **Cinema Venues (Always Kino, NEVER Teātris)**:
  - **K.Suns** (`K. Suns`, `K.Suns`, `K Suns`, `K.Suns kinoteātris`, `Kinogalerija K.Suns`)
  - **Kino Rio** (`Kino Rio`, `Kinoteātris Rio`, `Ventspils Kino Rio`)
  - **Forum Cinemas** (`Forum Cinemas`, `Kino Citadele`)
  - **Apollo Kino** (`Apollo Kino Akropole Rīga`, `Apollo Kino Plaza`, etc.)
  - **Cinamon** (`CINAMON KINOBALLE`, `Cinamon Alfa`)
  - **Splendid Palace** (`Kino Splendid Palace`)
  - **Kino Bize**, **Kino Lora**, **Kino Gaisma**, **Kino Auseklis**, **Kino Bāze**
  - Any venue containing `kinoteātris` or `kinozāle`.
- **Film & Movie Screenings (Always Kino, NEVER Teātris)**:
  - Any event whose title, description, or category contains `filma`, `filmas`, `filmu`, `filmā`, `kinoseanss`, `spēlfilma`, `dokumentālā filma`, `animācijas filma`, `īsfilma`, or `kinofestivāls` is **strictly Kino** (`kino`).
  - Kinoteātri (`kinoteātris`) contain the substring `teātr`, but MUST NEVER be classified as `Teātris` (`teatris`). The `teatris` category must always be stripped for cinema venues and film screenings.

## 2. Theater / Teātris Rules
- Venues: *Jaunais Rīgas teātris (JRT)*, *Dailes teātris*, *Latvijas Nacionālais teātris*, *Valmieras drāmas teātris*, *Liepājas teātris*, *M. Čehova Rīgas Krievu teātris*, *Dirty Deal Teatro*, *Ģertrūdes ielas teātris*, *Latvijas Leļļu teātris*, etc.
- Keywords: *izrāde*, *iestudējums*, *luga*, *komēdija*, *traģēdija*, *opera*, *operete*, *balets*, *cirks*, *stand-up*.

## 3. Unknown / Unmatched Categories Default to "Cits"
- If an event cannot be clearly mapped to a specific canonical category, it defaults to **Cits** (`citi`).
