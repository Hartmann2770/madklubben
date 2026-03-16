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

// Medlemsregister — udfyld manglende emailadresser
$members = [
    'Heide'       => 'UDFYLD@example.com',
    'Hartmann'    => 'simonbirkhartmann@gmail.com',
    'Gjelsted'    => 'tuborgdrengen@hotmail.com',
    'Thyregod'    => 'UDFYLD@example.com',
    'Bisp'        => 'UDFYLD@example.com',
    'Cronstjerne' => 'UDFYLD@example.com',
    'Frøding'     => 'UDFYLD@example.com',
    'Rifsdal'     => 'UDFYLD@example.com',
    'Larsen'      => 'UDFYLD@example.com',
    'Mekanikeren' => 'UDFYLD@example.com',
];

// Email recipients til generelle beskeder
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

    // ── RSVP Confirm (kræver IKKE admin-token) ────────────────────────
    if (isset($body['action']) && $body['action'] === 'rsvp_confirm') {
        $rsvpToken = trim($body['rsvpToken'] ?? '');
        $svarRaw   = ($body['svar'] ?? 'ja');
        $status    = ($svarRaw === 'nej') ? 'declined' : 'confirmed';

        if (!$rsvpToken) {
            http_response_code(400);
            echo json_encode(['error' => 'Manglende token']);
            exit;
        }

        $dinners = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        foreach ($dinners as &$dinner) {
            if (empty($dinner['rsvp'])) continue;
            foreach ($dinner['rsvp'] as $name => &$entry) {
                if ($entry['token'] === $rsvpToken) {
                    $entry['status'] = $status;
                    file_put_contents($file, json_encode($dinners, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    echo json_encode(['ok' => true, 'name' => $name, 'date' => $dinner['date'], 'svar' => $svarRaw]);
                    exit;
                }
            }
        }
        http_response_code(404);
        echo json_encode(['error' => 'Ugyldigt token']);
        exit;
    }

    // ── Token-validering (kræves for alle nedenstående actions) ────────
    if (!isset($body['token']) || $body['token'] !== $token) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // ── Send RSVP ─────────────────────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'send_rsvp') {
        $dinnerDate = trim($body['dinnerDate'] ?? '');
        if (!$dinnerDate) {
            http_response_code(400);
            echo json_encode(['error' => 'Manglende dinnerDate']);
            exit;
        }
        if ($dinnerDate < date('Y-m-d')) {
            http_response_code(400);
            echo json_encode(['error' => 'Kan ikke sende RSVP til en fortidig middag']);
            exit;
        }

        $dinners = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $found = false;
        foreach ($dinners as &$dinner) {
            if ($dinner['date'] !== $dinnerDate) continue;
            $found = true;

            if (!empty($dinner['rsvp'])) {
                http_response_code(400);
                echo json_encode(['error' => 'RSVP allerede sendt. Brug reset_rsvp for at nulstille.']);
                exit;
            }

            // Generer tokens
            $rsvpMap = [];
            foreach ($members as $name => $email) {
                $rsvpMap[$name] = ['token' => bin2hex(random_bytes(6)), 'status' => 'pending'];
            }
            $dinner['rsvp'] = $rsvpMap;

            // Gem straks
            file_put_contents($file, json_encode($dinners, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // Byg email-indhold
            $dateFormatted = (new DateTime($dinnerDate))->format('d/m/Y');
            $chefs         = implode(' & ', $dinner['chefs'] ?? []);
            $locationLine  = !empty($dinner['location']) ? "Sted: Hos {$dinner['location']}\n" : '';
            $dinnerNumber  = isset($body['dinnerNumber']) ? intval($body['dinnerNumber']) : '?';

            ini_set('sendmail_from', 'simon@madklubben.com');
            $headers  = "From: $from\r\n";
            $headers .= "Reply-To: simon@madklubben.com\r\n";
            $headers .= "Sender: simon@madklubben.com\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "MIME-Version: 1.0\r\n";

            $failed = [];
            $sent   = 0;
            foreach ($rsvpMap as $name => $entry) {
                $email = $members[$name] ?? null;
                if (!$email || strpos($email, 'UDFYLD') !== false) {
                    $failed[] = $name;
                    continue;
                }
                $confirmUrl = "https://madklubben.com/?rsvp={$entry['token']}";
                $declineUrl = "https://madklubben.com/?rsvp={$entry['token']}&svar=nej";
                $deadline   = (new DateTime($dinnerDate))->modify('-3 days')->format('d/m/Y');
                $subject    = "Invitation til Madklub #{$dinnerNumber} den $dateFormatted";
                $message    = "Hej $name,\n\nDu er inviteret til Madklub #{$dinnerNumber} den $dateFormatted.\nKokke: $chefs\n{$locationLine}\nBekræft din deltagelse her:\n$confirmUrl\n\nAfmeld dig her:\n$declineUrl\n\nSvar venligst senest den $deadline.\n\nSes der!\nMadklubben";

                if (mail($email, $subject, $message, $headers)) {
                    $sent++;
                } else {
                    $failed[] = $name;
                }
            }
            echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
            exit;
        }

        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => 'Middag ikke fundet']);
        }
        exit;
    }

    // ── Send RSVP Reminder (kun pending) ─────────────────────────────
    if (isset($body['action']) && $body['action'] === 'send_rsvp_reminder') {
        $dinnerDate = trim($body['dinnerDate'] ?? '');
        if (!$dinnerDate) {
            http_response_code(400);
            echo json_encode(['error' => 'Manglende dinnerDate']);
            exit;
        }
        $dinners = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        foreach ($dinners as $dinner) {
            if ($dinner['date'] !== $dinnerDate) continue;
            if (empty($dinner['rsvp'])) {
                http_response_code(400);
                echo json_encode(['error' => 'RSVP ikke sendt endnu']);
                exit;
            }
            $dateFormatted = (new DateTime($dinnerDate))->format('d/m/Y');
            $chefs         = implode(' & ', $dinner['chefs'] ?? []);
            $locationLine  = !empty($dinner['location']) ? "Sted: Hos {$dinner['location']}\n" : '';

            ini_set('sendmail_from', 'simon@madklubben.com');
            $headers  = "From: $from\r\n";
            $headers .= "Reply-To: simon@madklubben.com\r\n";
            $headers .= "Sender: simon@madklubben.com\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "MIME-Version: 1.0\r\n";

            $failed = [];
            $sent   = 0;
            foreach ($dinner['rsvp'] as $name => $entry) {
                if ($entry['status'] !== 'pending') continue;
                $email = $members[$name] ?? null;
                if (!$email || strpos($email, 'UDFYLD') !== false) {
                    $failed[] = $name;
                    continue;
                }
                $confirmUrl = "https://madklubben.com/?rsvp={$entry['token']}";
                $declineUrl = "https://madklubben.com/?rsvp={$entry['token']}&svar=nej";
                $deadline   = (new DateTime($dinnerDate))->modify('-3 days')->format('d/m/Y');
                $subject    = "Påmindelse: Svar på invitation til Madklubben den $dateFormatted";
                $message    = "Hej $name,\n\nVi mangler stadig dit svar på Madklubben den $dateFormatted.\nKokke: $chefs\n{$locationLine}\nBekræft din deltagelse her:\n$confirmUrl\n\nAfmeld dig her:\n$declineUrl\n\nSvar venligst senest den $deadline.\n\nSes der!\nMadklubben";
                if (mail($email, $subject, $message, $headers)) {
                    $sent++;
                } else {
                    $failed[] = $name;
                }
            }
            echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
            exit;
        }
        http_response_code(404);
        echo json_encode(['error' => 'Middag ikke fundet']);
        exit;
    }

    // ── Reset RSVP ────────────────────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'reset_rsvp') {
        $dinnerDate = trim($body['dinnerDate'] ?? '');
        if (!$dinnerDate) {
            http_response_code(400);
            echo json_encode(['error' => 'Manglende dinnerDate']);
            exit;
        }
        $dinners = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        foreach ($dinners as &$dinner) {
            if ($dinner['date'] === $dinnerDate) {
                $dinner['rsvp'] = null;
                file_put_contents($file, json_encode($dinners, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                echo json_encode(['ok' => true]);
                exit;
            }
        }
        http_response_code(404);
        echo json_encode(['error' => 'Middag ikke fundet']);
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

        ini_set('sendmail_from', 'simon@madklubben.com');
        $headers  = "From: $from\r\n";
        $headers .= "Reply-To: simon@madklubben.com\r\n";
        $headers .= "Sender: simon@madklubben.com\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        $failed = [];
        foreach ($recipients as $to) {
            if (!mail($to, $subject, $message, $headers)) {
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
