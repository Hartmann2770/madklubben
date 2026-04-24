---
title: Japan-Quiz til Madklub N° 67
date: 2026-04-24
status: approved (afventer implementeringsplan)
---

# Japan-Quiz til Madklub N° 67

## Formål

Live multi-device quiz til Madklubbens aften 25. april 2026. Tema: Japan. 20 multiple-choice spørgsmål. Spillere svarer fra deres telefoner, alle ser storskærmen i stuen. Simon styrer fra sin telefon. Resultatet arkiveres permanent og kan ses fra Madklubbens hjemmeside år frem.

## Filer

Alle filer ligger separat i `madklubben/` og rører ikke eksisterende `dinners.json`, `api.php`, eller `index.html`-logik (udover én knap, se nedenfor).

```
madklubben/
  quiz.html              alle views i én fil (player, screen, host, archive)
  quiz-api.php           backend state machine
  quiz-questions.json    20 spørgsmål + 5 tiebreakere, redigerbar i hånden
  quiz-state.json        live state, auto-oprettes
  quiz-archive-67.json   permanent arkiv, skrives ved quizzens afslutning
```

## Roller (URL-baseret)

| URL | Rolle |
|---|---|
| `madklubben.com/quiz.html` | Spiller (default, scannes fra QR) |
| `madklubben.com/quiz.html?role=screen` | Storskærm i stuen |
| `madklubben.com/quiz.html?role=host&key=XXX` | Simons telefon (host-kontroller) |
| `madklubben.com/quiz.html?archive=67` | Read-only arkiv-visning |

`key=XXX` er en delt secret hard-coded i `quiz.html`. Ikke rigtig sikkerhed, bare en barriere mod at en spiller ved et uheld klikker host-knapper.

## Spilleforløb

### Fase 1: Lobby
- Storskærm viser titel "JAPAN-QUIZ Madklub N° 67", japansk flag, stort QR-kode + URL'en `madklubben.com/quiz.html`
- Spillere scanner, ser navne-knapper for de 10 medlemmer (Hartmann, Heide, Gjelsted, Thyregod, Bisp, Cronstjerne, Frøding, Rifsdal, Larsen, Mekanikeren) plus "Andet"-knap til gæster
- Tilmeldte spillere vises live på storskærmen
- Simon klikker "Start quiz" på sin telefon, lobby lukker (ingen late-join)

### Fase 2: Spørgsmål (gentages 20 gange)

1. Simon klikker "Vis spørgsmål". Storskærm + telefoner viser spørgsmål + 4 svarmuligheder
2. Spillere stemmer. Kan ændre svar så længe afstemning er åben
3. Storskærm viser løbende: "3/8 har stemt — mangler: Heide, Bisp"
4. Simon klikker "Luk afstemning"
5. Simon klikker "Vis resultat". Rigtigt svar markeres grønt. Søjlediagram viser fordelingen pr. svarmulighed med navne under hver søjle. Stemmerne arkiveres i `votesHistory` på dette tidspunkt
6. Simon klikker "Næste". `currentQuestionIndex` bumpes, `votes` ryddes, klar til næste spørgsmål

### Fase 3: Slutskærm

Vinderskammel som lodret liste, kun top 3:

```
🥇 1. plads — Hartmann (15 point)
🥈 2. plads — Heide (13 point)
🥉 3. plads — Bisp (12 point)
```

Ingen visning af 4. plads og nedefter. Sidestatistik nederst: "Hurtigste finger: Cronstjerne (4.2 sek i snit blandt rigtige svar)".

Hvis to eller flere spillere har samme point på en podie-plads vises de som "delt 1. plads" osv., og en knap **"Tiebreaker for 1. plads"** dukker op ved siden af. Klik aktiverer Fase 4.

### Fase 4: Tiebreaker (kun ved trigger fra host)

- Trækker næste ubrugte tiebreaker-spørgsmål fra `quiz-questions.json`. Tiebreakere er "tættest på"-spørgsmål med ét numerisk svar (fx "Hvor mange mennesker boede der i Japan pr. 1. januar 2026?")
- Kun de spillere der er i tie ser nummer-input på telefonen. De andres telefoner viser "Tiebreaker i gang..."
- Storskærm viser spørgsmålet + "Afventer svar fra Hartmann og Bisp..."
- Simon klikker "Vis svar". Hver spillers gæt vises, rigtigt tal afsløres, tætteste afstand vinder
- Hvis to spillere er præcis lige tæt på, vinder den der svarede først
- Hvis det stadig er lige (ekstremt usandsynligt), klikker Simon "Næste tiebreaker"
- Leaderboard opdateres, knappen forsvinder
- Simon kan trigge tiebreakere for flere podie-pladser efter behov, eller lade delte placeringer stå

### Fase 5: Afslutning og arkivering

- Simon klikker "Afslut quiz" på slutskærmen
- `quiz-api.php` skriver `quiz-archive-67.json` med hele forløbet (alle spørgsmål, alle stemmer, alle tiebreakere, final leaderboard)
- Knappen på madklub-detailviewet i `index.html` ændres til "🎌 Se quiz-resultater fra Madklub #67" og peger på `?archive=67`

## Datamodel

### `quiz-questions.json`

```json
{
  "questions": [
    {
      "id": 1,
      "category": "geografi",
      "question": "Hvilken by er Japans hovedstad?",
      "options": ["Osaka", "Kyoto", "Tokyo", "Sapporo"],
      "correct": 2
    }
  ],
  "tiebreakers": [
    {
      "id": "tb1",
      "question": "Hvor mange mennesker boede der i Japan pr. 1. januar 2026?",
      "answer": 124000000,
      "unit": "indbyggere"
    }
  ]
}
```

### `quiz-state.json`

```json
{
  "phase": "lobby | question | voting-closed | result | tiebreaker | ended",
  "currentQuestionIndex": 0,
  "questionShownAt": 1714000000,
  "players": {
    "Hartmann": { "score": 0, "totalAnswerTime": 0, "correctCount": 0 }
  },
  "votes": {
    "Hartmann": { "choice": 2, "answeredAt": 1714000005 }
  },
  "votesHistory": [
    {
      "questionId": 1,
      "votes": {
        "Hartmann": { "choice": 2, "answeredAt": 1714000005 },
        "Heide": { "choice": 1, "answeredAt": 1714000007 }
      }
    }
  ],
  "tiebreaker": {
    "active": false,
    "tiebreakerIndex": 0,
    "position": 1,
    "playerNames": ["Hartmann", "Bisp"],
    "guesses": {}
  }
}
```

### `quiz-archive-67.json`

Snapshot af `quiz-state.json` ved fasen `ended`, plus alle spørgsmål kopieret ind fra `quiz-questions.json` så arkivet er selvbærende selv hvis questions-filen senere ændres eller slettes.

## API (`quiz-api.php`)

| Action | Hvem | Effekt |
|---|---|---|
| `get_state` | alle | returnerer state-objektet |
| `get_archive` | alle | returnerer arkivet (kun hvis det findes) |
| `join` | spiller | tilføjer navn til `players` (kun i lobby-fase) |
| `vote` | spiller | sætter eller ændrer `votes[name]` (kun i question-fase) |
| `tiebreaker_vote` | spiller | sætter `tiebreaker.guesses[name]` (kun for spillere i tie under aktiv tiebreaker) |
| `start_quiz` | host | lobby → question, currentQuestionIndex = 0 |
| `show_question` | host | viser nuværende spørgsmål, sætter questionShownAt |
| `close_voting` | host | låser afstemning, beregner score, opdaterer player-stats |
| `show_result` | host | viser rigtigt svar, gemmer stemmer i votesHistory |
| `next_question` | host | currentQuestionIndex++ eller phase → ended hvis sidste |
| `start_tiebreaker` | host | aktiverer tiebreaker for given position med tiebreaker-spørgsmål nr. N |
| `show_tiebreaker_result` | host | afslører rigtigt svar, opdaterer leaderboard |
| `next_tiebreaker` | host | hvis ikke afgjort, næste tiebreaker-spørgsmål |
| `finish_quiz` | host | skriver `quiz-archive-67.json`, fryser state |

Host-actions kræver `key=XXX` i request-parameter. Polling: alle klienter (player, screen) kalder `get_state` hvert sekund.

## Visuel stil

### Storskærm (`?role=screen`)
- Mørk baggrund (#1a1a1a), lys tekst (#f5f5f0)
- Rød accent (Japan-flag-rød #c8102e)
- Japansk flag som logo i lobby-skærmen
- Kanji-tegn som ornament i hjørnerne (fx 日本 i lobby, 問 ved spørgsmål)
- Stor typografi (læsbar fra sofaen)
- Sans-serif (Helvetica Neue / system stack)

### Telefon-views (player, host)
- Lys baggrund (papir-hvid #f5f5f0), mørk tekst
- Samme røde accent som storskærm
- Store touch-knapper (mindst 60px høje)
- Spillerens valgte svar fremhæves visuelt med fed ramme + checkmark, så det er tydeligt hvad man har valgt og at man kan ændre det

### Arkiv (`?archive=67`)
- Samme palette som storskærm men med lys baggrund så det er læsbart i en hverdagsbrowser
- Top: vinderskammel med top 3 (medaljer + navne + point)
- Sidestatistik: "Hurtigste finger"
- Sektion: alle 20 spørgsmål, hver med rigtigt svar markeret + tabel der viser hver spillers valg (grønt for korrekt, rødt for forkert)
- Sektion (hvis triggeret): tiebreakere med spørgsmål, hver spillers gæt og rigtigt svar

## Integration med eksisterende kode

Eneste ændring i eksisterende kode: ~10 linjer i `index.html`s render-funktion for dinner-detailview, hard-coded til Madklub #67:

```html
<a href="/quiz.html?role=screen" class="quiz-button">🎌 Japan-Quiz</a>
```

Når quizzen er afsluttet (dvs. `quiz-archive-67.json` findes på serveren), peger linket på `?archive=67` i stedet. Frontend afgør hvilken variant der vises ved en let `fetch`-test af arkiv-endpointet.

## Sikkerhed og auth

- Ingen login, ingen sessions
- Host-key er en delt secret hard-coded i `quiz.html` (skjult i ikke-host views). Forhindrer ved-et-uheld-klik, ikke målrettet sabotage
- Spillere har ingen autentifikation. Alle der scanner QR kan deltage

## Ikke i scope (YAGNI)

- Genoptagelse efter sletning (én quiz, én aften, færdig)
- Multiple samtidige quizzer (kun #67)
- Genbrug til fremtidige madklubber (kan generaliseres senere hvis ønsket)
- Lyd, animationer ud over basis-fade
- Mobile responsive ud over telefon-portrait og storskærm-landscape
- Internationalisering (kun dansk)
- Database (alt er JSON-filer på disk)

## Deploy

Upload til madklubben.com root via FileZilla:
- `quiz.html`
- `quiz-api.php`
- `quiz-questions.json`
- opdateret `index.html` (med quiz-knappen)

`quiz-state.json` og `quiz-archive-67.json` oprettes automatisk af `quiz-api.php` ved første kald.

## Test inden quiz-aften

- Åbn `?role=screen` på en computer
- Åbn `?role=host&key=XXX` på telefon
- Åbn default-view på 2 ekstra telefoner (spillere)
- Kør et lille mock-spil med 3 spørgsmål
- Verificer: lobby, vote-flow, vote-ændring, lukket afstemning, resultat-visning, podium med top 3, tiebreaker-trigger, arkiv-visning
