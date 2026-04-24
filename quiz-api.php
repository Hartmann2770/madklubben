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

  default:
    http_response_code(400);
    echo json_encode(['error' => 'unknown_action']);
}
