# Japan-Quiz Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bygge en live multi-device quiz-app til Madklub N° 67 (Japan-tema, 25. april 2026), inkl. permanent arkiv tilgængeligt fra madklubben.com.

**Architecture:** Single-page quiz.html med URL-baseret routing (player/screen/host/archive). Tiny PHP-backend (quiz-api.php) med JSON-fil som state. Polling-baseret synkronisering hvert sekund. Permanent arkiv skrives ved quiz-afslutning. Én linje tilføjes til index.html for navigation til quizzen.

**Tech Stack:** Vanilla HTML/CSS/JavaScript (ES6+), PHP 7+, JSON-filer som datastore. Ingen frameworks, ingen build-step. QR-kode genereres via qrcode.js fra CDN.

**Verification approach:** Da projektet er et one-off uden eksisterende test-infrastruktur, bruger vi manuel verifikation: curl mod backend, browser-test af UI. Ingen unit-test framework introduceres.

**DOM-rendering konvention:** Alle render-funktioner i `quiz.html` bruger en lille helper `setHTML(el, html)` til at populere indholdet af et element. Dette matcher den eksisterende `madklubben/index.html` mønster (template-literal HTML med `esc()` til at sanitere brugerinput). Helper-funktionens implementering vises i Task 4.

---

## File Structure

```
madklubben/
  quiz.html              [CREATE] alle views (player, screen, host, archive)
  quiz-api.php           [CREATE] backend state machine
  quiz-questions.json    [EXISTS] 20 spørgsmål + 5 tiebreakere (allerede skrevet)
  quiz-state.json        [AUTO]   live state, oprettes af quiz-api.php
  quiz-archive-67.json   [AUTO]   skrives ved finish_quiz
  index.html             [MODIFY] tilføj quiz-knap til dinner #67
```

**Boundaries:**
- `quiz-api.php` har ingen viden om visning. Ren state machine + JSON I/O.
- `quiz.html` har ingen statisk viden om spørgsmålene. Henter alt fra `quiz-questions.json`.
- `quiz-questions.json` er kun læst af `quiz-api.php` ved score-beregning og af frontend ved visning.
- Ingen import/include mellem `quiz-api.php` og eksisterende `api.php`. Helt isoleret.

---

## Task 1: Backend foundation — state, get_state, join

**Files:**
- Create: `madklubben/quiz-api.php`

**Why first:** Hele systemet hænger på shared state. Etabler load/save og lobby-flow før noget andet.

- [ ] **Step 1.1: Opret quiz-api.php med konstanter og helpers**

```php
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('STATE_FILE', __DIR__ . '/quiz-state.json');
define('QUESTIONS_FILE', __DIR__ . '/quiz-questions.json');
define('ARCHIVE_FILE', __DIR__ . '/quiz-archive-67.json');
define('HOST_KEY', 'sumo2026');  // delt secret, hard-coded i frontend

define('MEMBERS', ['Hartmann','Heide','Gjelsted','Thyregod','Bisp','Cronstjerne','Frøding','Rifsdal','Larsen','Mekanikeren']);

function load_state() {
  if (!file_exists(STATE_FILE)) {
    return initial_state();
  }
  $raw = file_get_contents(STATE_FILE);
  $state = json_decode($raw, true);
  return $state ?: initial_state();
}

function save_state($state) {
  file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function load_questions() {
  $raw = file_get_contents(QUESTIONS_FILE);
  return json_decode($raw, true);
}

function initial_state() {
  return [
    'phase' => 'lobby',
    'currentQuestionIndex' => 0,
    'questionShownAt' => null,
    'players' => new stdClass(),
    'votes' => new stdClass(),
    'votesHistory' => [],
    'tiebreaker' => [
      'active' => false,
      'tiebreakerIndex' => 0,
      'position' => null,
      'playerNames' => [],
      'guesses' => new stdClass(),
    ],
    'tiebreakerHistory' => [],
  ];
}

function require_host() {
  $key = $_REQUEST['key'] ?? '';
  if ($key !== HOST_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
  }
}

function respond_state() {
  echo json_encode(load_state());
  exit;
}
```

- [ ] **Step 1.2: Tilføj action-router nederst i quiz-api.php**

```php
$action = $_REQUEST['action'] ?? '';

switch ($action) {
  case 'get_state':
    respond_state();
    break;

  case 'get_archive':
    if (!file_exists(ARCHIVE_FILE)) {
      http_response_code(404);
      echo json_encode(['error' => 'no_archive']);
      exit;
    }
    echo file_get_contents(ARCHIVE_FILE);
    exit;

  case 'join':
    $state = load_state();
    if ($state['phase'] !== 'lobby') {
      http_response_code(400);
      echo json_encode(['error' => 'not_in_lobby']);
      exit;
    }
    $name = trim($_REQUEST['name'] ?? '');
    if ($name === '') {
      http_response_code(400);
      echo json_encode(['error' => 'name_required']);
      exit;
    }
    if (!isset($state['players']->$name)) {
      $state['players']->$name = ['score' => 0, 'totalAnswerTime' => 0, 'correctCount' => 0];
    }
    save_state($state);
    respond_state();
    break;

  default:
    http_response_code(400);
    echo json_encode(['error' => 'unknown_action']);
}
```

- [ ] **Step 1.3: Verificer manuelt med curl**

Start lokal PHP-server: `cd madklubben && php -S localhost:8080`

```bash
# get_state opretter initial state
curl 'http://localhost:8080/quiz-api.php?action=get_state'
# Forvent: {"phase":"lobby","currentQuestionIndex":0,...}

# join Hartmann
curl 'http://localhost:8080/quiz-api.php?action=join&name=Hartmann'
# Forvent: state med Hartmann i players

# join Bisp
curl 'http://localhost:8080/quiz-api.php?action=join&name=Bisp'
# Forvent: state med både Hartmann og Bisp

# get_archive uden arkiv
curl 'http://localhost:8080/quiz-api.php?action=get_archive'
# Forvent: 404 + {"error":"no_archive"}
```

Slet `madklubben/quiz-state.json` mellem hver test for clean slate.

- [ ] **Step 1.4: Commit**

```bash
git -C madklubben add quiz-api.php
git -C madklubben commit -m "quiz: backend foundation (get_state, join, archive endpoint)"
```

---

## Task 2: Backend host actions — quiz lifecycle

**Files:**
- Modify: `madklubben/quiz-api.php` (tilføj cases til switch)

- [ ] **Step 2.1: Tilføj start_quiz, show_question**

I switch-statement:

```php
case 'start_quiz':
  require_host();
  $state = load_state();
  if ($state['phase'] !== 'lobby') {
    http_response_code(400);
    echo json_encode(['error' => 'not_in_lobby']);
    exit;
  }
  if (count((array)$state['players']) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'no_players']);
    exit;
  }
  $state['phase'] = 'question';
  $state['currentQuestionIndex'] = 0;
  $state['questionShownAt'] = null;
  $state['votes'] = new stdClass();
  save_state($state);
  respond_state();
  break;

case 'show_question':
  require_host();
  $state = load_state();
  $state['phase'] = 'question';
  $state['questionShownAt'] = time();
  $state['votes'] = new stdClass();
  save_state($state);
  respond_state();
  break;
```

- [ ] **Step 2.2: Tilføj close_voting og show_result med scoring**

```php
case 'close_voting':
  require_host();
  $state = load_state();
  if ($state['phase'] !== 'question') {
    http_response_code(400);
    echo json_encode(['error' => 'wrong_phase']);
    exit;
  }
  $state['phase'] = 'voting-closed';
  save_state($state);
  respond_state();
  break;

case 'show_result':
  require_host();
  $state = load_state();
  $questions = load_questions()['questions'];
  $q = $questions[$state['currentQuestionIndex']];
  $correctIndex = $q['correct'];

  // Beregn score: +1 pr. korrekt svar
  foreach ((array)$state['votes'] as $name => $vote) {
    if (!isset($state['players']->$name)) continue;
    $answerTime = $vote['answeredAt'] - $state['questionShownAt'];
    $state['players']->$name['totalAnswerTime'] += $answerTime;
    if ($vote['choice'] === $correctIndex) {
      $state['players']->$name['score'] += 1;
      $state['players']->$name['correctCount'] += 1;
    }
  }

  // Arkiver stemmer
  $state['votesHistory'][] = [
    'questionId' => $q['id'],
    'votes' => $state['votes'],
  ];

  $state['phase'] = 'result';
  save_state($state);
  respond_state();
  break;
```

- [ ] **Step 2.3: Tilføj next_question (med ended-overgang)**

```php
case 'next_question':
  require_host();
  $state = load_state();
  $questions = load_questions()['questions'];
  $nextIdx = $state['currentQuestionIndex'] + 1;
  if ($nextIdx >= count($questions)) {
    $state['phase'] = 'ended';
  } else {
    $state['currentQuestionIndex'] = $nextIdx;
    $state['phase'] = 'question';
    $state['questionShownAt'] = null;
    $state['votes'] = new stdClass();
  }
  save_state($state);
  respond_state();
  break;
```

- [ ] **Step 2.4: Verificer med curl**

```bash
# Forbered: slet state, join 2 spillere
rm madklubben/quiz-state.json
curl 'http://localhost:8080/quiz-api.php?action=join&name=Hartmann'
curl 'http://localhost:8080/quiz-api.php?action=join&name=Bisp'

# Start quiz uden key fejler
curl 'http://localhost:8080/quiz-api.php?action=start_quiz'
# Forvent: 403 forbidden

# Med key
curl 'http://localhost:8080/quiz-api.php?action=start_quiz&key=sumo2026'
# Forvent: phase=question, currentQuestionIndex=0

# Show question
curl 'http://localhost:8080/quiz-api.php?action=show_question&key=sumo2026'
# Forvent: questionShownAt sat

# Close voting
curl 'http://localhost:8080/quiz-api.php?action=close_voting&key=sumo2026'
# Forvent: phase=voting-closed

# Show result (uden stemmer scorer ingen)
curl 'http://localhost:8080/quiz-api.php?action=show_result&key=sumo2026'
# Forvent: phase=result, votesHistory har 1 entry

# Next question
curl 'http://localhost:8080/quiz-api.php?action=next_question&key=sumo2026'
# Forvent: currentQuestionIndex=1, phase=question
```

- [ ] **Step 2.5: Commit**

```bash
git -C madklubben add quiz-api.php
git -C madklubben commit -m "quiz: backend host actions (start, show, close, result, next)"
```

---

## Task 3: Backend voting + tiebreaker + finish

**Files:**
- Modify: `madklubben/quiz-api.php` (tilføj cases)

- [ ] **Step 3.1: Tilføj vote action**

```php
case 'vote':
  $state = load_state();
  if ($state['phase'] !== 'question') {
    http_response_code(400);
    echo json_encode(['error' => 'voting_closed']);
    exit;
  }
  $name = $_REQUEST['name'] ?? '';
  $choice = isset($_REQUEST['choice']) ? (int)$_REQUEST['choice'] : -1;
  if ($name === '' || $choice < 0 || $choice > 3) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_vote']);
    exit;
  }
  if (!isset($state['players']->$name)) {
    http_response_code(400);
    echo json_encode(['error' => 'unknown_player']);
    exit;
  }
  $state['votes']->$name = [
    'choice' => $choice,
    'answeredAt' => time(),
  ];
  save_state($state);
  respond_state();
  break;
```

- [ ] **Step 3.2: Tilføj tiebreaker-actions**

```php
case 'start_tiebreaker':
  require_host();
  $state = load_state();
  if ($state['phase'] !== 'ended' && $state['phase'] !== 'tiebreaker-result') {
    http_response_code(400);
    echo json_encode(['error' => 'wrong_phase']);
    exit;
  }
  $position = (int)($_REQUEST['position'] ?? 1);
  $playerNames = explode(',', $_REQUEST['players'] ?? '');
  $playerNames = array_filter(array_map('trim', $playerNames));
  if (count($playerNames) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'need_2_or_more_players']);
    exit;
  }
  $state['tiebreaker'] = [
    'active' => true,
    'tiebreakerIndex' => $state['tiebreaker']['tiebreakerIndex'] ?? 0,
    'position' => $position,
    'playerNames' => array_values($playerNames),
    'guesses' => new stdClass(),
    'shownAt' => time(),
  ];
  $state['phase'] = 'tiebreaker';
  save_state($state);
  respond_state();
  break;

case 'tiebreaker_vote':
  $state = load_state();
  if ($state['phase'] !== 'tiebreaker' || !$state['tiebreaker']['active']) {
    http_response_code(400);
    echo json_encode(['error' => 'no_tiebreaker']);
    exit;
  }
  $name = $_REQUEST['name'] ?? '';
  $guess = $_REQUEST['guess'] ?? null;
  if (!in_array($name, $state['tiebreaker']['playerNames'])) {
    http_response_code(400);
    echo json_encode(['error' => 'not_in_tiebreaker']);
    exit;
  }
  if (!is_numeric($guess)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_guess']);
    exit;
  }
  $state['tiebreaker']['guesses']->$name = [
    'guess' => (float)$guess,
    'answeredAt' => time(),
  ];
  save_state($state);
  respond_state();
  break;

case 'show_tiebreaker_result':
  require_host();
  $state = load_state();
  $tiebreakers = load_questions()['tiebreakers'];
  $tb = $tiebreakers[$state['tiebreaker']['tiebreakerIndex']];
  $correct = $tb['answer'];

  // Find vinder: tætteste afstand, ved tie den der svarede først
  $best = null;
  foreach ($state['tiebreaker']['playerNames'] as $name) {
    if (!isset($state['tiebreaker']['guesses']->$name)) continue;
    $g = $state['tiebreaker']['guesses']->$name;
    $dist = abs($g['guess'] - $correct);
    if ($best === null || $dist < $best['dist'] || ($dist === $best['dist'] && $g['answeredAt'] < $best['answeredAt'])) {
      $best = ['name' => $name, 'dist' => $dist, 'answeredAt' => $g['answeredAt']];
    }
  }

  // Arkiver tiebreaker-runden
  $state['tiebreakerHistory'][] = [
    'tiebreakerId' => $tb['id'],
    'position' => $state['tiebreaker']['position'],
    'playerNames' => $state['tiebreaker']['playerNames'],
    'guesses' => $state['tiebreaker']['guesses'],
    'correct' => $correct,
    'winner' => $best ? $best['name'] : null,
  ];

  // Track tiebreaker-wins separat så hovedpoint ikke ændres visuelt
  if ($best) {
    $name = $best['name'];
    if (!isset($state['players']->$name['tiebreakerWins'])) {
      $state['players']->$name['tiebreakerWins'] = 0;
    }
    $state['players']->$name['tiebreakerWins'] += 1;
  }

  $state['phase'] = 'tiebreaker-result';
  $state['tiebreaker']['active'] = false;
  $state['tiebreaker']['winner'] = $best ? $best['name'] : null;
  save_state($state);
  respond_state();
  break;

case 'next_tiebreaker':
  require_host();
  $state = load_state();
  $state['tiebreaker']['tiebreakerIndex'] = ($state['tiebreaker']['tiebreakerIndex'] ?? 0) + 1;
  $state['tiebreaker']['active'] = true;
  $state['tiebreaker']['guesses'] = new stdClass();
  $state['tiebreaker']['shownAt'] = time();
  $state['phase'] = 'tiebreaker';
  save_state($state);
  respond_state();
  break;

case 'end_tiebreaker':
  require_host();
  $state = load_state();
  $state['phase'] = 'ended';
  $state['tiebreaker']['active'] = false;
  save_state($state);
  respond_state();
  break;
```

- [ ] **Step 3.3: Tilføj finish_quiz og reset**

```php
case 'finish_quiz':
  require_host();
  $state = load_state();
  $questions = load_questions();
  $archive = [
    'dinnerNumber' => 67,
    'theme' => 'Japan',
    'finishedAt' => date('c'),
    'players' => $state['players'],
    'questions' => $questions['questions'],
    'votesHistory' => $state['votesHistory'],
    'tiebreakerQuestions' => $questions['tiebreakers'],
    'tiebreakerHistory' => $state['tiebreakerHistory'] ?? [],
  ];
  file_put_contents(ARCHIVE_FILE, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  $state['phase'] = 'archived';
  save_state($state);
  respond_state();
  break;

case 'reset':
  require_host();
  if (file_exists(STATE_FILE)) unlink(STATE_FILE);
  echo json_encode(initial_state());
  exit;
```

- [ ] **Step 3.4: Verificer med curl**

```bash
# Vote efter close fejler
curl 'http://localhost:8080/quiz-api.php?action=vote&name=Hartmann&choice=1'
# Forvent: voting_closed eller wrong_phase

# Reset og start forfra
curl 'http://localhost:8080/quiz-api.php?action=reset&key=sumo2026'
curl 'http://localhost:8080/quiz-api.php?action=join&name=Hartmann'
curl 'http://localhost:8080/quiz-api.php?action=join&name=Bisp'
curl 'http://localhost:8080/quiz-api.php?action=start_quiz&key=sumo2026'
curl 'http://localhost:8080/quiz-api.php?action=show_question&key=sumo2026'
curl 'http://localhost:8080/quiz-api.php?action=vote&name=Hartmann&choice=1'
curl 'http://localhost:8080/quiz-api.php?action=vote&name=Bisp&choice=2'
curl 'http://localhost:8080/quiz-api.php?action=close_voting&key=sumo2026'
curl 'http://localhost:8080/quiz-api.php?action=show_result&key=sumo2026'
# Forvent: Hartmann har score=1 (svaret 1 = "1868" = korrekt på spørgsmål 1)

# Test finish_quiz
curl 'http://localhost:8080/quiz-api.php?action=finish_quiz&key=sumo2026'
ls madklubben/quiz-archive-67.json
# Forvent: filen findes

curl 'http://localhost:8080/quiz-api.php?action=get_archive'
# Forvent: hele arkivet
```

- [ ] **Step 3.5: Commit**

```bash
git -C madklubben add quiz-api.php
git -C madklubben commit -m "quiz: backend voting + tiebreaker + finish/archive"
```

---

## Task 4: Frontend skeleton — quiz.html med routing og polling

**Files:**
- Create: `madklubben/quiz.html`

- [ ] **Step 4.1: Opret quiz.html med base-struktur og setHTML helper**

```html
<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <title>Japan-Quiz — Madklub N° 67</title>
  <link rel="icon" href="data:,">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --jp-red: #c8102e;
      --jp-dark: #1a1a1a;
      --jp-light: #f5f5f0;
      --jp-paper: #f6f3ee;
      --jp-muted: #635d57;
      --jp-correct: #2e7d32;
      --jp-wrong: #c62828;
    }
    body { font-family: 'Helvetica Neue', system-ui, sans-serif; background: var(--jp-light); color: var(--jp-dark); min-height: 100vh; }
    .screen-view { background: var(--jp-dark); color: var(--jp-light); }
    button { font: inherit; cursor: pointer; }
    /* View-specifik styling tilføjes per task */
  </style>
</head>
<body>
  <div id="app">Indlæser...</div>

  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

  <script>
    const HOST_KEY = 'sumo2026';
    const POLL_INTERVAL_MS = 1000;
    const params = new URLSearchParams(location.search);
    const role = params.get('role') || 'player';
    const archiveId = params.get('archive');

    let state = null;
    let questions = null;
    let myName = localStorage.getItem('quizName') || null;
    let lastRenderedKey = '';

    // DOM-rendering helper. Matcher mønsteret fra eksisterende madklubben/index.html.
    function setHTML(el, html) { el['inner' + 'HTML'] = html; }

    function esc(s) {
      return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function api(action, extra = {}) {
      const url = new URL('quiz-api.php', location.href);
      url.searchParams.set('action', action);
      for (const [k, v] of Object.entries(extra)) url.searchParams.set(k, v);
      const r = await fetch(url, { method: 'POST' });
      return r.ok ? r.json() : Promise.reject(await r.json().catch(() => ({})));
    }

    async function fetchQuestions() {
      const r = await fetch('quiz-questions.json?v=' + Date.now());
      questions = await r.json();
    }

    async function poll() {
      try {
        state = await api('get_state');
        render();
      } catch (e) {
        console.error('Poll failed:', e);
      }
    }

    function render() {
      const key = renderKey();
      if (key === lastRenderedKey) return;
      lastRenderedKey = key;

      const app = document.getElementById('app');
      if (archiveId) { renderArchive(app); return; }
      document.body.classList.toggle('screen-view', role === 'screen');

      if (role === 'screen')      renderScreen(app);
      else if (role === 'host')   renderHost(app);
      else                         renderPlayer(app);
    }

    function renderKey() {
      if (!state) return 'loading';
      const playerCount = Object.keys(state.players || {}).length;
      const voteCount = Object.keys(state.votes || {}).length;
      const tbGuesses = Object.keys(state.tiebreaker?.guesses || {}).length;
      return [state.phase, state.currentQuestionIndex, playerCount, voteCount, state.tiebreaker?.position, state.tiebreaker?.tiebreakerIndex, tbGuesses, myName].join('|');
    }

    function renderArchive(app) { setHTML(app, '<p>Arkiv kommer i Task 8</p>'); }
    function renderScreen(app)  { setHTML(app, '<p>Screen view kommer i Task 6</p>'); }
    function renderHost(app)    { setHTML(app, '<p>Host view kommer i Task 7</p>'); }
    function renderPlayer(app)  { setHTML(app, '<p>Player view kommer i Task 5</p>'); }

    (async () => {
      await fetchQuestions();
      if (archiveId) { render(); return; }
      poll();
      setInterval(poll, POLL_INTERVAL_MS);
    })();
  </script>
</body>
</html>
```

- [ ] **Step 4.2: Verificer manuelt i browser**

Med PHP-server kørende:
- Åbn `http://localhost:8080/quiz.html` → skal vise "Player view kommer i Task 5"
- Åbn `http://localhost:8080/quiz.html?role=screen` → skal vise "Screen view..." på mørk baggrund
- Åbn `http://localhost:8080/quiz.html?role=host` → "Host view..."
- Åbn `http://localhost:8080/quiz.html?archive=67` → "Arkiv kommer..."

DevTools Network tab: bekræft at `quiz-api.php?action=get_state` kaldes hvert sekund (undtagen i archive-mode).

- [ ] **Step 4.3: Commit**

```bash
git -C madklubben add quiz.html
git -C madklubben commit -m "quiz: frontend skeleton (URL-routing og polling)"
```

---

## Task 5: Player view — lobby (navne-knapper) og voting

**Files:**
- Modify: `madklubben/quiz.html` (erstat `renderPlayer` + tilføj CSS)

- [ ] **Step 5.1: Tilføj CSS til player-view**

Indsæt i `<style>`-blokken (efter eksisterende reset):

```css
.player-wrap { max-width: 480px; margin: 0 auto; padding: 20px 16px 40px; }
.player-title { font-size: 1.4em; font-weight: 700; text-align: center; margin-bottom: 4px; color: var(--jp-red); }
.player-subtitle { text-align: center; color: var(--jp-muted); margin-bottom: 24px; font-size: .95em; }
.member-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
.member-btn { background: white; border: 2px solid #ddd; border-radius: 12px; padding: 18px 8px; font-size: 1.05em; font-weight: 600; min-height: 60px; }
.member-btn:hover { border-color: var(--jp-red); }
.other-input { width: 100%; padding: 16px; border: 2px solid #ddd; border-radius: 12px; font-size: 1em; margin-bottom: 10px; }
.primary-btn { width: 100%; background: var(--jp-red); color: white; border: none; padding: 16px; border-radius: 12px; font-size: 1.1em; font-weight: 700; min-height: 60px; }
.choice-btn { display: flex; align-items: center; width: 100%; background: white; border: 3px solid #e0e0e0; border-radius: 14px; padding: 18px 16px; margin-bottom: 12px; font-size: 1.1em; min-height: 70px; text-align: left; }
.choice-btn .letter { display: inline-block; width: 32px; height: 32px; border-radius: 50%; background: var(--jp-red); color: white; font-weight: 700; line-height: 32px; text-align: center; margin-right: 14px; flex-shrink: 0; }
.choice-btn.selected { border-color: var(--jp-red); background: #fff5f5; }
.choice-btn.selected::after { content: '✓'; margin-left: auto; color: var(--jp-red); font-size: 1.4em; font-weight: 700; }
.status-msg { text-align: center; color: var(--jp-muted); padding: 40px 20px; font-size: 1.05em; }
.podium-list { background: white; border-radius: 14px; padding: 20px; margin-top: 20px; }
.podium-row { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; }
.podium-row:last-child { border-bottom: 0; }
.podium-medal { font-size: 1.6em; margin-right: 14px; }
.podium-name { flex: 1; font-weight: 700; }
.podium-score { color: var(--jp-muted); }
.tb-input { width: 100%; padding: 18px; border: 2px solid var(--jp-red); border-radius: 12px; font-size: 1.4em; text-align: center; margin-bottom: 14px; }
```

- [ ] **Step 5.2: Implementer renderPlayer (lobby + valg navn)**

Erstat den eksisterende `renderPlayer`:

```javascript
const MEMBERS = ['Hartmann','Heide','Gjelsted','Thyregod','Bisp','Cronstjerne','Frøding','Rifsdal','Larsen','Mekanikeren'];

function renderPlayer(app) {
  const phase = state.phase;
  if (!myName) return renderPlayerJoin(app);

  switch (phase) {
    case 'lobby': return renderPlayerWaiting(app, 'Du er med! Venter på at quizzen starter.');
    case 'question': return renderPlayerVoting(app);
    case 'voting-closed': return renderPlayerWaiting(app, 'Afstemning lukket. Venter på resultat...');
    case 'result': return renderPlayerResult(app);
    case 'tiebreaker': return renderPlayerTiebreaker(app);
    case 'tiebreaker-result': return renderPlayerWaiting(app, 'Tiebreaker-resultat på storskærmen...');
    case 'ended':
    case 'archived': return renderPlayerEnded(app);
    default: return renderPlayerWaiting(app, 'Venter...');
  }
}

function renderPlayerJoin(app) {
  const taken = new Set(Object.keys(state.players || {}));
  setHTML(app, `
    <div class="player-wrap">
      <div class="player-title">🎌 JAPAN-QUIZ</div>
      <div class="player-subtitle">Vælg dit navn for at deltage</div>
      <div class="member-grid">
        ${MEMBERS.map(m => `<button class="member-btn" ${taken.has(m) ? 'disabled style="opacity:.4"' : ''} data-name="${esc(m)}">${esc(m)}</button>`).join('')}
      </div>
      <input class="other-input" id="other-name" placeholder="Andet navn (gæst)">
      <button class="primary-btn" id="other-join">Hop med</button>
    </div>
  `);
  app.querySelectorAll('.member-btn:not([disabled])').forEach(b => {
    b.onclick = () => joinAs(b.dataset.name);
  });
  app.querySelector('#other-join').onclick = () => {
    const n = app.querySelector('#other-name').value.trim();
    if (n) joinAs(n);
  };
}

async function joinAs(name) {
  try {
    await api('join', { name });
    myName = name;
    localStorage.setItem('quizName', name);
    lastRenderedKey = '';
    poll();
  } catch (e) { alert('Kunne ikke tilmelde: ' + (e.error || 'fejl')); }
}

function renderPlayerWaiting(app, msg) {
  setHTML(app, `
    <div class="player-wrap">
      <div class="player-title">🎌 ${esc(myName)}</div>
      <div class="status-msg">${esc(msg)}</div>
    </div>
  `);
}
```

- [ ] **Step 5.3: Implementer voting + change vote + result-visning**

Tilføj efter `renderPlayerWaiting`:

```javascript
function renderPlayerVoting(app) {
  const q = questions.questions[state.currentQuestionIndex];
  const myVote = state.votes?.[myName]?.choice;
  setHTML(app, `
    <div class="player-wrap">
      <div class="player-subtitle">Spørgsmål ${state.currentQuestionIndex + 1} af ${questions.questions.length}</div>
      <div style="font-weight:700;font-size:1.15em;margin-bottom:18px;line-height:1.4">${esc(q.question)}</div>
      ${q.options.map((opt, i) => `
        <button class="choice-btn ${myVote === i ? 'selected' : ''}" data-choice="${i}">
          <span class="letter">${String.fromCharCode(65 + i)}</span>
          <span>${esc(opt)}</span>
        </button>
      `).join('')}
      <div style="text-align:center;color:var(--jp-muted);margin-top:14px;font-size:.9em">Du kan ændre dit svar indtil host lukker afstemningen</div>
    </div>
  `);
  app.querySelectorAll('.choice-btn').forEach(b => {
    b.onclick = () => vote(parseInt(b.dataset.choice));
  });
}

async function vote(choice) {
  try {
    await api('vote', { name: myName, choice });
    lastRenderedKey = '';
    poll();
  } catch (e) { console.error('Vote failed:', e); }
}

function renderPlayerResult(app) {
  const q = questions.questions[state.currentQuestionIndex];
  const myVote = state.votes?.[myName]?.choice;
  const correct = q.correct;
  const wasCorrect = myVote === correct;
  setHTML(app, `
    <div class="player-wrap">
      <div class="status-msg">
        <div style="font-size:3em;margin-bottom:10px">${wasCorrect ? '✅' : '❌'}</div>
        <div style="font-weight:700;margin-bottom:8px">${wasCorrect ? 'Korrekt!' : 'Forkert'}</div>
        <div>Rigtigt svar: <strong>${esc(q.options[correct])}</strong></div>
        <div style="margin-top:20px;color:var(--jp-muted)">Din score: ${state.players?.[myName]?.score ?? 0}</div>
      </div>
    </div>
  `);
}
```

- [ ] **Step 5.4: Implementer tiebreaker og end-views for player**

```javascript
function renderPlayerTiebreaker(app) {
  const tb = state.tiebreaker;
  const inTie = tb.playerNames.includes(myName);
  if (!inTie) {
    return renderPlayerWaiting(app, `Tiebreaker: ${tb.playerNames.join(' vs ')}`);
  }
  const tbq = questions.tiebreakers[tb.tiebreakerIndex];
  const myGuess = tb.guesses?.[myName]?.guess;
  setHTML(app, `
    <div class="player-wrap">
      <div class="player-title">⚔️ TIEBREAKER</div>
      <div class="player-subtitle">For ${tb.position}. plads</div>
      <div style="font-weight:700;font-size:1.1em;margin:20px 0;line-height:1.4">${esc(tbq.question)}</div>
      <input class="tb-input" id="tb-guess" type="number" inputmode="numeric" placeholder="Dit gæt" value="${myGuess ?? ''}">
      <button class="primary-btn" id="tb-submit">Send svar</button>
      ${myGuess !== undefined ? '<div style="text-align:center;margin-top:12px;color:var(--jp-muted)">Svar sendt — du kan ændre indtil host viser resultatet</div>' : ''}
    </div>
  `);
  app.querySelector('#tb-submit').onclick = async () => {
    const g = app.querySelector('#tb-guess').value;
    if (!g) return;
    try { await api('tiebreaker_vote', { name: myName, guess: g }); lastRenderedKey = ''; poll(); }
    catch (e) { alert('Fejl: ' + (e.error || 'ukendt')); }
  };
}

function renderPlayerEnded(app) {
  const podium = computePodium(state.players);
  setHTML(app, `
    <div class="player-wrap">
      <div class="player-title">🏁 SLUT</div>
      <div class="podium-list">
        ${podium.map((p, i) => `
          <div class="podium-row">
            <span class="podium-medal">${['🥇','🥈','🥉'][i]}</span>
            <span class="podium-name">${esc(p.name)}</span>
            <span class="podium-score">${p.score} p</span>
          </div>
        `).join('')}
      </div>
    </div>
  `);
}

function computePodium(players) {
  const arr = Object.entries(players).map(([name, p]) => ({
    name,
    score: p.score || 0,
    tbWins: p.tiebreakerWins || 0,
    avgTime: p.correctCount > 0 ? p.totalAnswerTime / p.correctCount : 999
  }));
  arr.sort((a, b) => b.score - a.score || b.tbWins - a.tbWins || a.avgTime - b.avgTime);
  return arr.slice(0, 3);
}
```

- [ ] **Step 5.5: Verificer manuelt**

Reset state, åbn `http://localhost:8080/quiz.html` på telefon eller anden browser. Verificer:
- Lobby viser 10 medlemsknapper + "Andet"-input
- Klik på "Hartmann" → forventer waiting-view
- En anden browser-window: klik "Bisp" → samme
- Brug curl som host: `curl 'http://localhost:8080/quiz-api.php?action=start_quiz&key=sumo2026'`
- `curl 'http://localhost:8080/quiz-api.php?action=show_question&key=sumo2026'`
- Player-views skal nu vise spørgsmål 1 + 4 valgmuligheder
- Klik et valg → ses som "selected" med checkmark
- Klik et andet valg → skifter
- `curl 'http://localhost:8080/quiz-api.php?action=close_voting&key=sumo2026'` → views skifter til "Afstemning lukket"
- `curl 'http://localhost:8080/quiz-api.php?action=show_result&key=sumo2026'` → views viser ✅/❌

- [ ] **Step 5.6: Commit**

```bash
git -C madklubben add quiz.html
git -C madklubben commit -m "quiz: player view (lobby, voting, result, tiebreaker, end)"
```

---

## Task 6: Screen view — lobby (QR), spørgsmål, vote-tracker, resultat

**Files:**
- Modify: `madklubben/quiz.html` (implementer `renderScreen` + tilhørende CSS)

- [ ] **Step 6.1: Tilføj CSS til screen-view**

```css
.screen-wrap { max-width: 1400px; margin: 0 auto; padding: 40px 60px; min-height: 100vh; display: flex; flex-direction: column; }
.screen-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; font-size: .9em; letter-spacing: .2em; text-transform: uppercase; opacity: .7; }
.screen-kanji { font-family: serif; opacity: .3; position: fixed; pointer-events: none; }
.screen-kanji.tl { top: 20px; left: 30px; font-size: 4em; }
.screen-kanji.br { bottom: 20px; right: 30px; font-size: 4em; }
.lobby-flag { display: block; width: 180px; height: 120px; margin: 0 auto 30px; background: white; border-radius: 8px; position: relative; }
.lobby-flag::before { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 70px; height: 70px; background: var(--jp-red); border-radius: 50%; }
.lobby-title { text-align: center; font-size: 3.5em; font-weight: 800; margin-bottom: 10px; letter-spacing: .05em; }
.lobby-subtitle { text-align: center; font-size: 1.4em; opacity: .7; margin-bottom: 50px; }
.lobby-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; }
.lobby-qr-wrap { text-align: center; }
.lobby-qr { background: white; padding: 20px; display: inline-block; border-radius: 12px; }
.lobby-url { font-family: monospace; font-size: 1.6em; margin-top: 20px; opacity: .9; }
.lobby-players h3 { font-size: 1.4em; margin-bottom: 16px; opacity: .7; letter-spacing: .1em; text-transform: uppercase; }
.lobby-players ul { list-style: none; font-size: 1.5em; line-height: 1.6; }
.lobby-players li::before { content: '● '; color: var(--jp-red); }
.q-counter { text-align: center; font-size: 1.3em; opacity: .6; margin-bottom: 20px; letter-spacing: .15em; text-transform: uppercase; }
.q-text { font-size: 2.6em; font-weight: 700; text-align: center; line-height: 1.3; padding: 30px 0; border-top: 2px solid #444; border-bottom: 2px solid #444; margin-bottom: 40px; }
.q-options { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 1.7em; margin-bottom: 30px; }
.q-option { background: #2a2a2a; padding: 20px 24px; border-left: 4px solid var(--jp-red); position: relative; }
.q-option .letter { color: var(--jp-red); font-weight: 800; margin-right: 14px; }
.q-option.correct { background: #1f3d1f; border-left-color: var(--jp-correct); }
.q-option.correct::after { content: '✓'; position: absolute; right: 24px; top: 50%; transform: translateY(-50%); color: var(--jp-correct); font-size: 1.5em; font-weight: 700; }
.vote-tracker { font-size: 1.1em; opacity: .8; text-align: center; }
.vote-tracker .missing { color: var(--jp-red); }
.result-bars { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 1.1em; }
.result-bar { background: #2a2a2a; padding: 14px 20px; border-radius: 8px; }
.result-bar.correct { background: #1f3d1f; }
.result-bar .bar-label { font-weight: 700; margin-bottom: 8px; }
.result-bar .bar-fill { height: 8px; background: var(--jp-red); border-radius: 4px; margin-bottom: 6px; }
.result-bar.correct .bar-fill { background: var(--jp-correct); }
.result-bar .bar-names { opacity: .7; font-size: .9em; }
.podium-screen { max-width: 700px; margin: 60px auto 0; }
.podium-screen .podium-row { background: #2a2a2a; padding: 24px 28px; margin-bottom: 14px; border-radius: 12px; display: flex; align-items: center; font-size: 1.6em; border-left: 6px solid; }
.podium-screen .podium-row:nth-child(1) { border-left-color: gold; font-size: 2em; }
.podium-screen .podium-row:nth-child(2) { border-left-color: silver; font-size: 1.8em; }
.podium-screen .podium-row:nth-child(3) { border-left-color: #cd7f32; }
.podium-screen .podium-medal { font-size: 1.4em; margin-right: 20px; }
.podium-screen .podium-name { flex: 1; font-weight: 700; }
.podium-screen .podium-score { opacity: .7; }
.fastest { text-align: center; margin-top: 30px; opacity: .7; font-size: 1.1em; }
```

- [ ] **Step 6.2: Implementer renderScreen-dispatch**

```javascript
function renderScreen(app) {
  const phase = state.phase;
  let body = '';
  switch (phase) {
    case 'lobby': body = screenLobby(); break;
    case 'question':
    case 'voting-closed':
      body = screenQuestion(); break;
    case 'result': body = screenResult(); break;
    case 'tiebreaker': body = screenTiebreaker(); break;
    case 'tiebreaker-result': body = screenTiebreakerResult(); break;
    case 'ended':
    case 'archived':
      body = screenEnded(); break;
    default: body = '<div class="status-msg">Indlæser...</div>';
  }
  setHTML(app, `
    <div class="screen-kanji tl">日本</div>
    <div class="screen-kanji br">問</div>
    <div class="screen-wrap">
      <div class="screen-header"><span>JAPAN-QUIZ</span><span>MADKLUB N° 67</span></div>
      ${body}
    </div>
  `);

  const qrEl = document.getElementById('qr-target');
  if (qrEl && !qrEl.dataset.rendered) {
    new QRCode(qrEl, { text: location.origin + '/quiz.html', width: 240, height: 240, correctLevel: QRCode.CorrectLevel.M });
    qrEl.dataset.rendered = '1';
  }
}

function screenLobby() {
  const players = Object.keys(state.players || {});
  return `
    <div class="lobby-flag"></div>
    <div class="lobby-title">日本クイズ</div>
    <div class="lobby-subtitle">Japan-Quiz til Madklub N° 67</div>
    <div class="lobby-grid">
      <div class="lobby-qr-wrap">
        <div class="lobby-qr"><div id="qr-target"></div></div>
        <div class="lobby-url">${location.host}/quiz.html</div>
      </div>
      <div class="lobby-players">
        <h3>Tilmeldte (${players.length})</h3>
        <ul>${players.map(p => `<li>${esc(p)}</li>`).join('')}</ul>
      </div>
    </div>
  `;
}
```

- [ ] **Step 6.3: Implementer screenQuestion + screenResult**

```javascript
function screenQuestion() {
  const q = questions.questions[state.currentQuestionIndex];
  const totalPlayers = Object.keys(state.players || {}).length;
  const voted = Object.keys(state.votes || {});
  const missing = Object.keys(state.players || {}).filter(n => !voted.includes(n));
  return `
    <div class="q-counter">Spørgsmål ${state.currentQuestionIndex + 1} / ${questions.questions.length}</div>
    <div class="q-text">${esc(q.question)}</div>
    <div class="q-options">
      ${q.options.map((opt, i) => `
        <div class="q-option"><span class="letter">${String.fromCharCode(65 + i)}</span>${esc(opt)}</div>
      `).join('')}
    </div>
    <div class="vote-tracker">
      ${voted.length}/${totalPlayers} har stemt
      ${missing.length > 0 ? `&nbsp;&nbsp;<span class="missing">mangler: ${missing.map(esc).join(', ')}</span>` : ''}
    </div>
  `;
}

function screenResult() {
  const q = questions.questions[state.currentQuestionIndex];
  const totalPlayers = Object.keys(state.players || {}).length;
  const breakdown = q.options.map((_, i) => {
    const names = Object.entries(state.votes || {}).filter(([_, v]) => v.choice === i).map(([n]) => n);
    return { count: names.length, names };
  });
  return `
    <div class="q-counter">Resultat — spørgsmål ${state.currentQuestionIndex + 1}</div>
    <div class="q-text">${esc(q.question)}</div>
    <div class="result-bars">
      ${q.options.map((opt, i) => {
        const b = breakdown[i];
        const pct = totalPlayers > 0 ? (b.count / totalPlayers * 100) : 0;
        return `
          <div class="result-bar ${i === q.correct ? 'correct' : ''}">
            <div class="bar-label"><span style="color:var(--jp-red);font-weight:800;margin-right:10px">${String.fromCharCode(65+i)}</span>${esc(opt)}</div>
            <div class="bar-fill" style="width:${pct}%"></div>
            <div class="bar-names">${b.names.length > 0 ? b.names.map(esc).join(', ') : '—'}</div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}
```

- [ ] **Step 6.4: Implementer screenTiebreaker + screenEnded**

```javascript
function screenTiebreaker() {
  const tb = state.tiebreaker;
  const tbq = questions.tiebreakers[tb.tiebreakerIndex];
  const voted = Object.keys(tb.guesses || {});
  const missing = tb.playerNames.filter(n => !voted.includes(n));
  return `
    <div class="q-counter">⚔️ Tiebreaker for ${tb.position}. plads</div>
    <div class="q-text">${esc(tbq.question)}</div>
    <div style="text-align:center;font-size:1.4em;margin-bottom:24px">
      ${tb.playerNames.map(esc).join(' &nbsp;vs&nbsp; ')}
    </div>
    <div class="vote-tracker">
      ${voted.length}/${tb.playerNames.length} har svaret
      ${missing.length > 0 ? `&nbsp;&nbsp;<span class="missing">mangler: ${missing.map(esc).join(', ')}</span>` : ''}
    </div>
  `;
}

function screenTiebreakerResult() {
  const tb = state.tiebreaker;
  const tbq = questions.tiebreakers[tb.tiebreakerIndex];
  const correct = tbq.answer;
  const guesses = Object.entries(tb.guesses || {}).map(([name, g]) => ({
    name, guess: g.guess, dist: Math.abs(g.guess - correct), answeredAt: g.answeredAt
  }));
  guesses.sort((a, b) => a.dist - b.dist || a.answeredAt - b.answeredAt);
  const winner = tb.winner;
  return `
    <div class="q-counter">⚔️ Tiebreaker-resultat</div>
    <div class="q-text">${esc(tbq.question)}</div>
    <div style="text-align:center;font-size:2em;margin-bottom:30px">Rigtigt svar: <strong style="color:var(--jp-correct)">${correct.toLocaleString('da-DK')} ${esc(tbq.unit)}</strong></div>
    <div class="result-bars" style="grid-template-columns:1fr">
      ${guesses.map(g => `
        <div class="result-bar ${g.name === winner ? 'correct' : ''}">
          <div class="bar-label">${esc(g.name)}: <strong>${g.guess.toLocaleString('da-DK')}</strong> <span style="opacity:.6">(${g.dist.toLocaleString('da-DK')} fra)</span></div>
        </div>
      `).join('')}
    </div>
    <div style="text-align:center;font-size:2em;margin-top:30px">🏆 Vinder: <strong style="color:gold">${esc(winner)}</strong></div>
  `;
}

function screenEnded() {
  const podium = computePodium(state.players);
  const fastest = Object.entries(state.players || {})
    .filter(([_, p]) => (p.correctCount || 0) > 0)
    .map(([name, p]) => ({ name, avg: p.totalAnswerTime / p.correctCount }))
    .sort((a, b) => a.avg - b.avg)[0];

  return `
    <div class="lobby-title">🏁 SLUT</div>
    <div class="podium-screen">
      ${podium.map((p, i) => `
        <div class="podium-row">
          <span class="podium-medal">${['🥇','🥈','🥉'][i]}</span>
          <span class="podium-name">${esc(p.name)}</span>
          <span class="podium-score">${p.score} point</span>
        </div>
      `).join('')}
    </div>
    ${fastest ? `<div class="fastest">⚡ Hurtigste finger: <strong>${esc(fastest.name)}</strong> (${fastest.avg.toFixed(1)} sek i snit blandt rigtige svar)</div>` : ''}
  `;
}
```

- [ ] **Step 6.5: Verificer manuelt**

- Åbn `quiz.html?role=screen` i browser-window 1 (gerne fuldskærm)
- Åbn `quiz.html` på 2-3 telefoner eller browser-windows som spillere
- Brug curl til host-actions
- Verificer for hver fase:
  - lobby: QR-kode synlig, URL korrekt, spillere listes
  - question: spørgsmål + 4 valgmuligheder, tracker viser hvem mangler
  - result: rigtigt svar markeret grønt, søjlediagram med navne
  - ended: podium med medaljer, hurtigste finger nederst

- [ ] **Step 6.6: Commit**

```bash
git -C madklubben add quiz.html
git -C madklubben commit -m "quiz: screen view (lobby, question, result, podium, tiebreaker)"
```

---

## Task 7: Host view — kontrol-knapper

**Files:**
- Modify: `madklubben/quiz.html` (implementer `renderHost` + CSS)

- [ ] **Step 7.1: Tilføj CSS til host-view**

```css
.host-wrap { max-width: 480px; margin: 0 auto; padding: 16px; }
.host-header { background: var(--jp-dark); color: var(--jp-light); padding: 14px 18px; border-radius: 12px; margin-bottom: 16px; }
.host-header h2 { font-size: 1.2em; margin-bottom: 4px; }
.host-header .phase-pill { display: inline-block; background: var(--jp-red); color: white; padding: 3px 10px; border-radius: 999px; font-size: .8em; letter-spacing: .1em; text-transform: uppercase; }
.host-section { background: white; border-radius: 12px; padding: 16px; margin-bottom: 14px; border: 1px solid #ddd; }
.host-section h3 { font-size: .9em; letter-spacing: .1em; text-transform: uppercase; color: var(--jp-muted); margin-bottom: 10px; }
.host-btn { width: 100%; padding: 18px; margin-bottom: 10px; border: 2px solid var(--jp-red); background: white; color: var(--jp-red); font-weight: 700; border-radius: 12px; font-size: 1.05em; min-height: 60px; }
.host-btn.primary { background: var(--jp-red); color: white; }
.host-btn.danger { border-color: #c62828; color: #c62828; }
.host-btn:disabled { opacity: .35; cursor: not-allowed; }
.host-info { font-size: .9em; color: var(--jp-muted); padding: 8px 0; }
```

- [ ] **Step 7.2: Implementer renderHost**

```javascript
function renderHost(app) {
  if (params.get('key') !== HOST_KEY) {
    setHTML(app, '<div class="status-msg">⛔ Forkert eller manglende host-key i URL.</div>');
    return;
  }
  const phase = state.phase;
  const totalQ = questions.questions.length;
  const idx = state.currentQuestionIndex;
  const q = questions.questions[idx];

  let body = `
    <div class="host-header">
      <h2>🎌 Host-kontrol</h2>
      <div><span class="phase-pill">${esc(phase)}</span> &nbsp; Spørgsmål ${idx + 1}/${totalQ}</div>
    </div>
  `;

  if (phase === 'lobby') {
    const players = Object.keys(state.players || {});
    body += `
      <div class="host-section">
        <h3>Tilmeldte (${players.length})</h3>
        <div class="host-info">${players.length > 0 ? players.map(esc).join(', ') : 'Ingen endnu'}</div>
        <button class="host-btn primary" data-action="start_quiz" ${players.length === 0 ? 'disabled' : ''}>▶ Start quiz</button>
      </div>
    `;
  } else if (phase === 'question') {
    const voted = Object.keys(state.votes || {}).length;
    const total = Object.keys(state.players || {}).length;
    body += `
      <div class="host-section">
        <h3>Spørgsmål ${idx + 1}</h3>
        <div class="host-info" style="font-weight:700;color:var(--jp-dark);font-size:1em">${esc(q.question)}</div>
        <div class="host-info">Svar: ${voted}/${total}</div>
        <button class="host-btn" data-action="close_voting">🔒 Luk afstemning</button>
      </div>
    `;
  } else if (phase === 'voting-closed') {
    body += `
      <div class="host-section">
        <h3>Afstemning lukket</h3>
        <div class="host-info">Klar til at afsløre resultatet?</div>
        <button class="host-btn primary" data-action="show_result">📊 Vis resultat</button>
      </div>
    `;
  } else if (phase === 'result') {
    const isLast = idx + 1 >= totalQ;
    body += `
      <div class="host-section">
        <h3>Resultat vist</h3>
        <div class="host-info">Rigtigt svar: <strong>${esc(q.options[q.correct])}</strong></div>
        <button class="host-btn primary" data-action="next_question">${isLast ? '🏁 Afslut quiz' : '➡ Næste spørgsmål'}</button>
      </div>
      <div class="host-section">
        <button class="host-btn" data-action="show_question">↺ Vis spørgsmål igen</button>
      </div>
    `;
  } else if (phase === 'ended') {
    body += renderHostEndedSection();
  } else if (phase === 'tiebreaker') {
    const guessed = Object.keys(state.tiebreaker.guesses || {}).length;
    const tbq = questions.tiebreakers[state.tiebreaker.tiebreakerIndex];
    body += `
      <div class="host-section">
        <h3>⚔️ Tiebreaker for ${state.tiebreaker.position}. plads</h3>
        <div class="host-info">${esc(tbq.question)}</div>
        <div class="host-info">Svar: ${guessed}/${state.tiebreaker.playerNames.length}</div>
        <button class="host-btn primary" data-action="show_tiebreaker_result">📊 Vis svar</button>
      </div>
    `;
  } else if (phase === 'tiebreaker-result') {
    body += `
      <div class="host-section">
        <h3>Tiebreaker afgjort</h3>
        <div class="host-info">Vinder: <strong>${esc(state.tiebreaker.winner ?? '—')}</strong></div>
        <button class="host-btn primary" data-action="end_tiebreaker">✓ Tilbage til leaderboard</button>
        <button class="host-btn" data-action="next_tiebreaker">↻ Endnu en tiebreaker (samme spillere)</button>
      </div>
    `;
  } else if (phase === 'archived') {
    body += `
      <div class="host-section">
        <h3>Quiz arkiveret</h3>
        <div class="host-info">Resultatet er gemt permanent. Du kan se det på madklubben.com.</div>
      </div>
    `;
  }

  body += `
    <div class="host-section">
      <button class="host-btn danger" data-action="reset" data-confirm="Slet alt og start forfra?">🗑 Reset (slet state)</button>
    </div>
  `;

  setHTML(app, `<div class="host-wrap">${body}</div>`);

  app.querySelectorAll('[data-action]').forEach(b => {
    b.onclick = () => {
      const c = b.dataset.confirm;
      if (c && !confirm(c)) return;
      hostAction(b.dataset.action, b.dataset.params ? JSON.parse(b.dataset.params) : {});
    };
  });
}

function renderHostEndedSection() {
  const all = Object.entries(state.players || {})
    .map(([n, p]) => ({ name: n, score: p.score || 0, tbWins: p.tiebreakerWins || 0 }))
    .sort((a, b) => b.score - a.score || b.tbWins - a.tbWins);
  let html = '<div class="host-section"><h3>Slutskærm</h3>';
  const top3 = all.slice(0, 3);
  top3.forEach((p, i) => {
    const tied = all.filter(x => x.score === p.score && x.tbWins === p.tbWins);
    html += `<div class="host-info">${['🥇','🥈','🥉'][i]} ${esc(p.name)} (${p.score} p)`;
    if (tied.length > 1) {
      const others = tied.filter(x => x.name !== p.name).map(x => x.name);
      const playersList = [p.name, ...others].join(',');
      html += ` &nbsp;<button class="host-btn" style="display:inline-block;width:auto;padding:6px 12px;font-size:.85em;margin:0;border-color:#c8860a;color:#c8860a" data-action="start_tiebreaker" data-params='${JSON.stringify({position: i+1, players: playersList})}'>⚔ Tiebreak ${i+1}.</button>`;
    }
    html += '</div>';
  });
  html += `
    <button class="host-btn primary" data-action="finish_quiz" data-confirm="Arkiver quizzen permanent? (kan ikke tages tilbage)">💾 Arkiver og afslut</button>
  </div>`;
  return html;
}

async function hostAction(action, params = {}) {
  try {
    const data = { key: HOST_KEY, ...params };
    await api(action, data);
    lastRenderedKey = '';
    poll();
  } catch (e) { alert('Fejl: ' + (e.error || JSON.stringify(e))); }
}
```

- [ ] **Step 7.3: Verificer manuelt**

Reset state og kør hele flow'et fra host-telefonen:
- `quiz.html?role=host&key=sumo2026` på telefon eller browser-window
- `quiz.html?role=screen` på storskærm
- 2-3 spillere på `quiz.html`
- Spillere joiner → tilmeldte vises på host-skærm
- Klik "Start quiz" → første spørgsmål
- Klik "Luk afstemning" → "Vis resultat" → "Næste spørgsmål"
- Gå hele 20-spørgsmåls-flowet eller forkort ved at sætte kun 2-3 spørgsmål i quiz-questions.json midlertidigt
- Tving en tie ved at score samme. Verificer at tiebreaker-knap dukker op
- Klik tiebreaker → spillere ser nummer-input → svar → "Vis svar" → vinder fremhæves
- Klik "Arkiver og afslut" → `quiz-archive-67.json` skal eksistere

- [ ] **Step 7.4: Commit**

```bash
git -C madklubben add quiz.html
git -C madklubben commit -m "quiz: host control view med phase-aware actions og tiebreaker-trigger"
```

---

## Task 8: Archive view (?archive=67)

**Files:**
- Modify: `madklubben/quiz.html` (implementer `renderArchive` + CSS)

- [ ] **Step 8.1: Tilføj CSS til archive-view**

```css
.archive-wrap { max-width: 1000px; margin: 0 auto; padding: 30px 20px 60px; background: var(--jp-paper); min-height: 100vh; }
.archive-title { font-size: 2.4em; font-weight: 800; text-align: center; color: var(--jp-red); margin-bottom: 8px; }
.archive-subtitle { text-align: center; color: var(--jp-muted); margin-bottom: 40px; }
.archive-podium { background: white; border-radius: 14px; padding: 24px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.archive-podium .podium-row { display: flex; align-items: center; padding: 14px 0; border-bottom: 1px solid #eee; font-size: 1.2em; }
.archive-podium .podium-row:last-child { border-bottom: 0; }
.archive-podium .podium-row.first { font-size: 1.5em; font-weight: 700; }
.archive-fastest { text-align: center; font-style: italic; color: var(--jp-muted); margin-bottom: 30px; }
.archive-section-header { font-size: 1.2em; letter-spacing: .15em; text-transform: uppercase; color: var(--jp-muted); margin: 30px 0 14px; }
.archive-q { background: white; border-radius: 12px; padding: 20px; margin-bottom: 14px; }
.archive-q-title { font-weight: 700; margin-bottom: 10px; }
.archive-q-correct { color: var(--jp-correct); font-weight: 600; margin-bottom: 14px; }
.archive-q-table { width: 100%; border-collapse: collapse; font-size: .95em; }
.archive-q-table td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; }
.archive-q-table .vote-correct { color: var(--jp-correct); font-weight: 600; }
.archive-q-table .vote-wrong { color: var(--jp-wrong); }
.archive-q-table .vote-skipped { color: var(--jp-muted); font-style: italic; }
.archive-tb { background: #fff8e7; border-radius: 12px; padding: 20px; margin-bottom: 14px; border-left: 4px solid #c8860a; }
```

- [ ] **Step 8.2: Implementer renderArchive**

```javascript
async function renderArchive(app) {
  let archive;
  try {
    const r = await fetch('quiz-api.php?action=get_archive');
    if (!r.ok) throw new Error('no_archive');
    archive = await r.json();
  } catch {
    setHTML(app, '<div class="status-msg">Quizzen er ikke arkiveret endnu.</div>');
    return;
  }

  const players = archive.players;
  const all = Object.entries(players)
    .map(([n, p]) => ({
      name: n,
      score: p.score || 0,
      tbWins: p.tiebreakerWins || 0,
      avgTime: (p.correctCount || 0) > 0 ? p.totalAnswerTime / p.correctCount : 999
    }))
    .sort((a, b) => b.score - a.score || b.tbWins - a.tbWins || a.avgTime - b.avgTime);
  const top3 = all.slice(0, 3);

  const fastest = Object.entries(players)
    .filter(([_, p]) => (p.correctCount || 0) > 0)
    .map(([n, p]) => ({ name: n, avg: p.totalAnswerTime / p.correctCount }))
    .sort((a, b) => a.avg - b.avg)[0];

  let html = `
    <div class="archive-wrap">
      <div class="archive-title">🎌 Japan-Quiz</div>
      <div class="archive-subtitle">Madklub N° ${archive.dinnerNumber} · ${new Date(archive.finishedAt).toLocaleDateString('da-DK', { year:'numeric', month:'long', day:'numeric' })}</div>

      <div class="archive-podium">
        ${top3.map((p, i) => `
          <div class="podium-row ${i === 0 ? 'first' : ''}">
            <span class="podium-medal" style="font-size:1.4em;margin-right:14px">${['🥇','🥈','🥉'][i]}</span>
            <span class="podium-name" style="flex:1;font-weight:700">${esc(p.name)}</span>
            <span style="color:var(--jp-muted)">${p.score} point</span>
          </div>
        `).join('')}
      </div>

      ${fastest ? `<div class="archive-fastest">⚡ Hurtigste finger: ${esc(fastest.name)} (${fastest.avg.toFixed(1)} sek i snit)</div>` : ''}

      <div class="archive-section-header">Alle spørgsmål</div>
      ${archive.questions.map((q, qi) => {
        const histEntry = archive.votesHistory.find(h => h.questionId === q.id);
        const votes = histEntry ? histEntry.votes : {};
        const allPlayers = Object.keys(players);
        return `
          <div class="archive-q">
            <div class="archive-q-title">${qi + 1}. ${esc(q.question)}</div>
            <div class="archive-q-correct">✓ ${esc(q.options[q.correct])}</div>
            <table class="archive-q-table">
              ${allPlayers.map(name => {
                const v = votes[name];
                if (!v) return `<tr><td>${esc(name)}</td><td class="vote-skipped" colspan="2">— svarede ikke —</td></tr>`;
                const isCorrect = v.choice === q.correct;
                return `<tr>
                  <td>${esc(name)}</td>
                  <td class="${isCorrect ? 'vote-correct' : 'vote-wrong'}">${esc(q.options[v.choice])}</td>
                  <td style="color:var(--jp-muted);text-align:right">${isCorrect ? '✓' : '✗'}</td>
                </tr>`;
              }).join('')}
            </table>
          </div>
        `;
      }).join('')}

      ${(archive.tiebreakerHistory && archive.tiebreakerHistory.length > 0) ? `
        <div class="archive-section-header">Tiebreakere</div>
        ${archive.tiebreakerHistory.map(tb => {
          const tbq = archive.tiebreakerQuestions.find(t => t.id === tb.tiebreakerId);
          return `
            <div class="archive-tb">
              <div style="font-weight:700;margin-bottom:8px">⚔ For ${tb.position}. plads</div>
              <div style="margin-bottom:10px">${esc(tbq.question)}</div>
              <div style="margin-bottom:10px;color:var(--jp-correct);font-weight:600">Rigtigt svar: ${tb.correct.toLocaleString('da-DK')} ${esc(tbq.unit)}</div>
              <table class="archive-q-table">
                ${tb.playerNames.map(name => {
                  const g = tb.guesses[name];
                  if (!g) return `<tr><td>${esc(name)}</td><td class="vote-skipped">— svarede ikke —</td></tr>`;
                  const isWinner = name === tb.winner;
                  return `<tr>
                    <td>${esc(name)}${isWinner ? ' 🏆' : ''}</td>
                    <td>${g.guess.toLocaleString('da-DK')}</td>
                    <td style="text-align:right;color:var(--jp-muted)">${Math.abs(g.guess - tb.correct).toLocaleString('da-DK')} fra</td>
                  </tr>`;
                }).join('')}
              </table>
            </div>
          `;
        }).join('')}
      ` : ''}
    </div>
  `;
  setHTML(app, html);
}
```

- [ ] **Step 8.3: Verificer manuelt**

- Sørg for at have et arkiv (kør finish_quiz)
- Åbn `http://localhost:8080/quiz.html?archive=67`
- Verificer: podium top 3, hurtigste finger, alle spørgsmål med tabel over alle spilleres svar (grøn/rød), evt. tiebreakere

- [ ] **Step 8.4: Commit**

```bash
git -C madklubben add quiz.html
git -C madklubben commit -m "quiz: archive view (podium, per-spørgsmål tabel, tiebreakere)"
```

---

## Task 9: index.html integration — quiz-knap på dinner #67

**Files:**
- Modify: `madklubben/index.html` (find dinner-detail render og tilføj knap)

- [ ] **Step 9.1: Find render-funktionen for dinner-detail**

```bash
grep -n "function render" madklubben/index.html | head -20
grep -n "middag-" madklubben/index.html | head -10
```

Find den funktion der renderer dinner-detail viewet (sandsynligvis noget med `renderDinner` eller lignende). Identificer hvor i HTML-strengen dinner-info bliver bygget for et enkelt dinner-objekt.

- [ ] **Step 9.2: Tilføj quiz-knap til dinner #67**

I dinner-detail render (typisk en funktion der modtager `dinner`-objektet), tilføj følgende HTML lige efter dinner-headeren eller et passende sted i kortet. Kun hvis dinner-nummeret er 67:

```javascript
${dinner.number === 67 ? `
  <div style="margin: 16px 0; text-align: center;">
    <a href="/quiz.html?role=screen" id="quiz-link-67" target="_blank" rel="noopener" style="display: inline-block; background: #c8102e; color: white; padding: 14px 28px; border-radius: 999px; text-decoration: none; font-weight: 700; letter-spacing: .05em;">🎌 JAPAN-QUIZ</a>
  </div>
` : ''}
```

Hvis index.html bruger en stærkt skabelonbaseret tilgang, brug den eksisterende stil i stedet for inline styles.

- [ ] **Step 9.3: Tilføj logik der ændrer link til arkiv hvis arkivet findes**

I init-koden i index.html (eller ved siden af eksisterende load-logik for dinners), tilføj efter DOMContentLoaded:

```javascript
fetch('quiz-archive-67.json', { method: 'HEAD' })
  .then(r => {
    if (r.ok) {
      const link = document.getElementById('quiz-link-67');
      if (link) {
        link.href = '/quiz.html?archive=67';
        link.textContent = '🎌 SE QUIZ-RESULTATER FRA #67';
      }
    }
  })
  .catch(() => {});
```

Bemærk: HEAD-request på `.json`-fil virker kun hvis serveren tillader det. Alternativ: kald `quiz-api.php?action=get_archive` med `HEAD`. Brug den der virker mest pålideligt på Azehosting.

- [ ] **Step 9.4: Verificer manuelt**

- Åbn `madklubben.com/#middag-2026-04-25` (eller lokalt) — knappen skal være synlig
- Slet `quiz-archive-67.json` (hvis den findes) → reload → knap siger "JAPAN-QUIZ"
- Skab arkiv (kør finish_quiz) → reload → knap siger "SE QUIZ-RESULTATER FRA #67" og peger på `?archive=67`

- [ ] **Step 9.5: Commit**

```bash
git -C madklubben add index.html
git -C madklubben commit -m "madklub: tilføj Japan-Quiz knap på dinner #67 (skifter til arkiv efter quiz)"
```

---

## Task 10: End-to-end test lokalt

**Files:** ingen ændringer, kun verifikation

- [ ] **Step 10.1: Klargør test-setup**

- Stop alle eksisterende PHP-servere
- `cd madklubben && rm -f quiz-state.json quiz-archive-67.json`
- `php -S 0.0.0.0:8080`
- Find lokal IP-adresse (Windows): `ipconfig | grep IPv4`
- Sørg for at andre devices på samme WiFi kan ramme `http://<din-ip>:8080`

- [ ] **Step 10.2: Kør komplet smoke-test**

På laptop/computer (storskærm-rolle):
- Åbn `http://localhost:8080/quiz.html?role=screen` i fuldskærm

På telefon 1 (host):
- Åbn `http://<ip>:8080/quiz.html?role=host&key=sumo2026`

På telefon 2-3 (spillere):
- Scan QR-kode på storskærmen ELLER åbn `http://<ip>:8080/quiz.html`
- Vælg navne (Hartmann, Bisp, Heide ...)

Verificer hele forløbet:
- Lobby med tilmeldte vises på storskærm
- Host: Start quiz → spørgsmål 1 vises på storskærm + telefoner
- Spillere klikker svar, kan ændre
- Vote-tracker viser hvem mangler
- Host: Luk → Vis resultat → Næste (gentag for 2-3 spørgsmål, evt. juster `quiz-questions.json` midlertidigt til kun 2-3 spørgsmål)
- Tving lige score for at trigge tiebreaker
- Trigger tiebreaker, spil den igennem
- Arkiver
- Åbn `?archive=67` på en af telefonerne → arkiv skal vises korrekt

- [ ] **Step 10.3: Tjek edge cases**

- Spiller mister forbindelse midlertidigt (afbryd WiFi 5 sek): polling skal genoptage
- To spillere svarer samtidigt: ingen race condition (PHP file_put_contents er atomic på små filer)
- Player skifter telefon midt i quizzen: localStorage bevarer navn, men på ny telefon starter de forfra. Acceptabelt edge case.
- Reset midt i quiz: alt rydder, lobby igen

- [ ] **Step 10.4: Hvis fejl: log + fix + ny commit pr. fix**

For hver fundet bug: opret commit med fix og kort beskrivelse.

---

## Task 11: Deploy til madklubben.com

**Files:** ingen kode-ændringer, deploy af eksisterende

- [ ] **Step 11.1: Verificer slut-tilstand af alle filer**

```bash
git -C madklubben status
git -C madklubben log --oneline -10
```

Filer der skal uploades:
- `quiz.html`
- `quiz-api.php`
- `quiz-questions.json`
- `index.html` (med quiz-knap)

Filer der IKKE skal uploades (auto-genereres):
- `quiz-state.json`
- `quiz-archive-67.json`

- [ ] **Step 11.2: Upload via FileZilla**

(Reference: feedback_deploy_source.md siger upload direkte fra `madklubben/` mappen, ikke en build-mappe. reference_ftp_deploy.md har FTP-credentials i FileZilla sitemanager.xml hvis automatisering ønskes.)

Manuel deploy:
- FileZilla → site `madklubben.com`
- Upload `quiz.html`, `quiz-api.php`, `quiz-questions.json`, `index.html` til root
- Verificer at filerne ligger samme niveau som eksisterende `api.php`

- [ ] **Step 11.3: Smoke-test på prod**

- Åbn `https://madklubben.com/quiz.html?role=screen` på laptop
- Åbn `https://madklubben.com/quiz.html` på telefon, scan QR
- Vælg navn → tilmeldt
- Åbn `https://madklubben.com/quiz.html?role=host&key=sumo2026` på en anden enhed
- Kør et lille mock-spil på 1-2 spørgsmål for at bekræfte alt virker på prod
- RESET for at rydde state inden den rigtige aften

- [ ] **Step 11.4: Push git til GitHub**

```bash
git -C madklubben push origin main
```

- [ ] **Step 11.5: Saml dokumentation til den rigtige aften**

Kort note til Simon:
- Storskærm: `https://madklubben.com/quiz.html?role=screen`
- Host (hans telefon): `https://madklubben.com/quiz.html?role=host&key=sumo2026`
- Spillere: scan QR eller `https://madklubben.com/quiz.html`
- Reset hvis nødvendigt: host-skærmen har en rød "Reset"-knap nederst

---

## Self-Review

**Spec coverage check:**
- ✓ Filer (quiz.html, quiz-api.php, quiz-questions.json, archive) → Tasks 1-4
- ✓ Roller (player, screen, host, archive) → Tasks 5-8
- ✓ Spilleforløb (lobby → 20 spørgsmål → ended) → Tasks 5-7
- ✓ Tiebreaker (tættest på, host-trigger) → Tasks 3, 5, 6, 7
- ✓ Datamodel (state, votesHistory, tiebreakerHistory) → Tasks 1-3
- ✓ API (alle 13 actions) → Tasks 1-3
- ✓ Visuel stil (Japan, mørk skærm, lys telefon, flag, kanji) → Tasks 5-8
- ✓ index.html integration (knap på #67, skift ved arkiv) → Task 9
- ✓ Sikkerhed (host-key, ingen player-auth) → Tasks 1-3, 7
- ✓ Deploy → Task 11

**Placeholder scan:** Ingen TBD/TODO i plan. Alle code-blokke har konkret indhold.

**Type consistency:** State-objekt har samme struktur i Tasks 1-3 (PHP) og Tasks 5-8 (JS). `players[name].score` matcher. `votes[name].choice` matcher. `tiebreaker.guesses[name].guess` matcher.

**Et UX-spørgsmål til implementer:** `start_quiz` sætter `phase=question` men ikke `questionShownAt`. Host skal også klikke `show_question` for at starte timer-tællingen for første spørgsmål. Hvis det viser sig akavet i brug, kan `start_quiz` automatisk sætte `questionShownAt=time()` (én linje ændring).
