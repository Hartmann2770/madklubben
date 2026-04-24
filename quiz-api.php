<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

define('STATE_FILE', __DIR__ . '/quiz-state.json');
define('QUESTIONS_FILE', __DIR__ . '/quiz-questions.json');
define('ARCHIVE_FILE', __DIR__ . '/quiz-archive-67.json');
define('HOST_KEY', 'sumo2026');

define('MEMBERS', ['Hartmann','Heide','Gjelsted','Thyregod','Bisp','Cronstjerne','Frøding','Rifsdal','Larsen','Mekanikeren']);

function load_state() {
  if (!file_exists(STATE_FILE)) {
    return initial_state();
  }
  $fp = fopen(STATE_FILE, 'r');
  if (!$fp) return initial_state();
  flock($fp, LOCK_SH);
  $raw = stream_get_contents($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  $state = json_decode($raw, true);
  if (!is_array($state) || !isset($state['phase'])) {
    // Corrupt state file: rename for postmortem, return fresh
    @rename(STATE_FILE, STATE_FILE . '.corrupt.' . time());
    error_log('quiz-api: STATE_FILE corrupt or missing phase, reset');
    return initial_state();
  }
  return $state;
}

function save_state($state) {
  file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
    if (is_object($state['players'])) {
      $state['players'] = (array)$state['players'];
    }
    if (!isset($state['players'][$name])) {
      $state['players'][$name] = ['score' => 0, 'totalAnswerTime' => 0, 'correctCount' => 0];
    }
    save_state($state);
    respond_state();
    break;

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
      if (!isset($state['players'][$name])) continue;
      $answerTime = $vote['answeredAt'] - $state['questionShownAt'];
      $state['players'][$name]['totalAnswerTime'] += $answerTime;
      if ($vote['choice'] === $correctIndex) {
        $state['players'][$name]['score'] += 1;
        $state['players'][$name]['correctCount'] += 1;
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
    if (!isset($state['players'][$name])) {
      http_response_code(400);
      echo json_encode(['error' => 'unknown_player']);
      exit;
    }
    if (is_object($state['votes'])) {
      $state['votes'] = (array)$state['votes'];
    }
    $state['votes'][$name] = [
      'choice' => $choice,
      'answeredAt' => time(),
    ];
    save_state($state);
    respond_state();
    break;

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
    if (is_object($state['tiebreaker']['guesses'])) {
      $state['tiebreaker']['guesses'] = (array)$state['tiebreaker']['guesses'];
    }
    $state['tiebreaker']['guesses'][$name] = [
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
    $guesses = (array)$state['tiebreaker']['guesses'];
    foreach ($state['tiebreaker']['playerNames'] as $name) {
      if (!isset($guesses[$name])) continue;
      $g = $guesses[$name];
      $dist = abs($g['guess'] - $correct);
      if ($best === null || $dist < $best['dist'] || ($dist === $best['dist'] && $g['answeredAt'] < $best['answeredAt'])) {
        $best = ['name' => $name, 'dist' => $dist, 'answeredAt' => $g['answeredAt']];
      }
    }

    // Arkiver tiebreaker-runden
    if (!isset($state['tiebreakerHistory'])) $state['tiebreakerHistory'] = [];
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
      if (!isset($state['players'][$name]['tiebreakerWins'])) {
        $state['players'][$name]['tiebreakerWins'] = 0;
      }
      $state['players'][$name]['tiebreakerWins'] += 1;
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
    file_put_contents(ARCHIVE_FILE, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    $state['phase'] = 'archived';
    save_state($state);
    respond_state();
    break;

  case 'reset':
    require_host();
    if (file_exists(STATE_FILE)) unlink(STATE_FILE);
    echo json_encode(initial_state());
    exit;

  default:
    http_response_code(400);
    echo json_encode(['error' => 'unknown_action']);
}
