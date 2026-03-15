<?php
// Madklubben – API
// Handles dinner data (GET/POST) and email sending.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$file  = __DIR__ . '/dinners.json';
$token = 'TMK04';
$from  = 'Madklubben <simon@madklubben.com>';

// Email recipients — tilføj flere adresser her efterhånden
$recipients = [
    'simonbirkhartmann@gmail.com',
];

// ── GET: return current dinner list ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo file_exists($file) ? file_get_contents($file) : '[]';
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!isset($body['token']) || $body['token'] !== $token) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // ── Send email ────────────────────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'send_email') {
        $subject = trim($body['subject'] ?? '');
        $message = trim($body['message'] ?? '');

        if (!$subject || !$message) {
            http_response_code(400);
            echo json_encode(['error' => 'Emne og besked må ikke være tomme']);
            exit;
        }

        $headers  = "From: $from\r\n";
        $headers .= "Reply-To: simon@madklubben.com\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        $failed = [];
        foreach ($recipients as $to) {
            if (!mail($to, $subject, $message, $headers, '-f simon@madklubben.com')) {
                $failed[] = $to;
            }
        }

        if (empty($failed)) {
            echo json_encode(['ok' => true, 'sent' => count($recipients)]);
        } else {
            echo json_encode(['ok' => false, 'failed' => $failed]);
        }
        exit;
    }

    // ── Save dinners ──────────────────────────────────────────────────
    if (!isset($body['dinners']) || !is_array($body['dinners'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }

    file_put_contents($file, json_encode($body['dinners'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
