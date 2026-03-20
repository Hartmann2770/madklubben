<?php
// Madklubben – API
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$file = __DIR__ . '/dinners.json';

// ── Koder (kun synlige server-side) ─────────────────────────────────
define('ADMIN_CODE',  '19143057');
define('MEMBER_CODE', 'TMK04');

// ── Hjælpefunktioner ─────────────────────────────────────────────────
function getRole(): ?string {
    return $_SESSION['mk_role'] ?? null;
}
function requireRole(string $min): void {
    $role = getRole();
    if (!$role) {
        http_response_code(403);
        echo json_encode(['error' => 'Log ind for at fortsætte']);
        exit;
    }
    if ($min === 'admin' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Kræver admin-adgang']);
        exit;
    }
}

function sendMail(string $to, string $subject, string $body): bool {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_USER, 'Madklubben');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

// Medlemsregister
$members = [
    'Heide'       => 'heide@madklubben.com',
    'Hartmann'    => 'simonbirkhartmann@gmail.com',
    'Gjelsted'    => 'tuborgdrengen@hotmail.com',
    'Thyregod'    => 'thriller@mail.dk',
    'Bisp'        => 'stefan_bisp@yahoo.dk',
    'Cronstjerne' => 'lundeager@yahoo.dk',
    'Frøding'     => 'mark@froeding.dk',
    'Rifsdal'     => 'rifsdal@aol.com',
    'Larsen'      => 'ebola112@yahoo.com',
    'Mekanikeren' => 'patrickhjvod@gmail.com',
];

// Email recipients til generelle beskeder — alle medlemmer
$recipients = array_values($members);

// ── GET: return current dinner list ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo file_exists($file) ? file_get_contents($file) : '[]';
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // ── Multipart (fil-upload) ────────────────────────────────────────
    if (strpos($contentType, 'multipart/form-data') !== false) {
        requireRole('member');
        $action = $_POST['action'] ?? '';

        if ($action === 'upload_dinner_image') {
            $dinnerDate   = trim($_POST['dinnerDate'] ?? '');
            $dinnerNumber = intval($_POST['dinnerNumber'] ?? 0);
            $imgType      = trim($_POST['type'] ?? '');

            $allowedTypes = ['forret', 'hoved', 'dessert', 'gruppe'];
            if (!in_array($imgType, $allowedTypes) || !$dinnerDate || !$dinnerNumber) {
                http_response_code(400);
                echo json_encode(['error' => 'Ugyldige parametre']);
                exit;
            }
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Fil mangler eller fejl ved upload']);
                exit;
            }
            $uploadedFile = $_FILES['image'];
            $mime = mime_content_type($uploadedFile['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mime, $allowedMimes)) {
                http_response_code(400);
                echo json_encode(['error' => 'Kun billedfiler er tilladt (jpg, png, webp, gif)']);
                exit;
            }
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $ext = $extMap[$mime] ?? 'jpg';

            $dir = __DIR__ . "/billeder/madklub-{$dinnerNumber}";
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            foreach (['jpg', 'png', 'webp', 'gif'] as $oldExt) {
                $oldPath = "{$dir}/{$imgType}.{$oldExt}";
                if (file_exists($oldPath)) unlink($oldPath);
            }

            $filename = "{$imgType}.{$ext}";
            $dest     = "{$dir}/{$filename}";

            if (!move_uploaded_file($uploadedFile['tmp_name'], $dest)) {
                http_response_code(500);
                echo json_encode(['error' => 'Kunne ikke gemme filen på serveren']);
                exit;
            }

            $path = "billeder/madklub-{$dinnerNumber}/{$filename}";

            $dinners = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
            foreach ($dinners as &$d) {
                if ($d['date'] !== $dinnerDate) continue;
                if (!isset($d['detaljer'])) $d['detaljer'] = [];
                if ($imgType === 'gruppe') {
                    $d['detaljer']['gruppe'] = $path;
                } else {
                    if (!isset($d['detaljer'][$imgType])) $d['detaljer'][$imgType] = [];
                    $d['detaljer'][$imgType]['billede'] = $path;
                }
                break;
            }
            unset($d);
            file_put_contents($file, json_encode($dinners, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            echo json_encode(['ok' => true, 'path' => $path]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Ukendt action']);
        exit;
    }

    // ── JSON body ─────────────────────────────────────────────────────
    $body = json_decode(file_get_contents('php://input'), true);

    // ── Login ─────────────────────────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'login') {
        $code = trim($body['code'] ?? '');
        if ($code === ADMIN_CODE) {
            $_SESSION['mk_role'] = 'admin';
            echo json_encode(['ok' => true, 'role' => 'admin']);
        } elseif ($code === MEMBER_CODE) {
            $_SESSION['mk_role'] = 'member';
            echo json_encode(['ok' => true, 'role' => 'member']);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    // ── Logout ────────────────────────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── RSVP Confirm (kræver ikke login) ─────────────────────────────
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

    // ── Alle nedenstående kræver mindst member-login ──────────────────
    requireRole('member');

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

            $rsvpMap = [];
            foreach ($members as $name => $email) {
                $rsvpMap[$name] = ['token' => bin2hex(random_bytes(6)), 'status' => 'pending'];
            }
            $dinner['rsvp'] = $rsvpMap;
            file_put_contents($file, json_encode($dinners, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $dateFormatted = (new DateTime($dinnerDate))->format('d/m/Y');
            $chefs         = implode(' & ', $dinner['chefs'] ?? []);
            $locationLine  = !empty($dinner['location']) ? "Sted: Hos {$dinner['location']}\n" : '';
            $dinnerNumber  = isset($body['dinnerNumber']) ? intval($body['dinnerNumber']) : '?';

            $failed = [];
            $sent   = 0;
            foreach ($rsvpMap as $name => $entry) {
                $email = $members[$name] ?? null;
                if (!$email || strpos($email, 'UDFYLD') !== false) { $failed[] = $name; continue; }
                $confirmUrl = "https://madklubben.com/?rsvp={$entry['token']}";
                $declineUrl = "https://madklubben.com/?rsvp={$entry['token']}&svar=nej";
                $deadline   = (new DateTime($dinnerDate))->modify('-3 days')->format('d/m/Y');
                $subject    = "Invitation til Madklub #{$dinnerNumber} den $dateFormatted";
                $message    = "Hej $name,\n\nDu er inviteret til Madklub #{$dinnerNumber} den $dateFormatted.\nKokke: $chefs\n{$locationLine}\nBekræft din deltagelse her:\n$confirmUrl\n\nAfmeld dig her:\n$declineUrl\n\nSvar venligst senest den $deadline.\n\nSes der!\nMadklubben";
                if (sendMail($email, $subject, $message)) $sent++; else $failed[] = $name;
            }
            echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
            exit;
        }

        if (!$found) { http_response_code(404); echo json_encode(['error' => 'Middag ikke fundet']); }
        exit;
    }

    // ── Send RSVP Reminder ────────────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'send_rsvp_reminder') {
        $dinnerDate = trim($body['dinnerDate'] ?? '');
        if (!$dinnerDate) { http_response_code(400); echo json_encode(['error' => 'Manglende dinnerDate']); exit; }
        $dinners = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        foreach ($dinners as $dinner) {
            if ($dinner['date'] !== $dinnerDate) continue;
            if (empty($dinner['rsvp'])) { http_response_code(400); echo json_encode(['error' => 'RSVP ikke sendt endnu']); exit; }
            $dateFormatted = (new DateTime($dinnerDate))->format('d/m/Y');
            $chefs         = implode(' & ', $dinner['chefs'] ?? []);
            $locationLine  = !empty($dinner['location']) ? "Sted: Hos {$dinner['location']}\n" : '';
            $failed = []; $sent = 0;
            foreach ($dinner['rsvp'] as $name => $entry) {
                if ($entry['status'] !== 'pending') continue;
                $email = $members[$name] ?? null;
                if (!$email || strpos($email, 'UDFYLD') !== false) { $failed[] = $name; continue; }
                $confirmUrl = "https://madklubben.com/?rsvp={$entry['token']}";
                $declineUrl = "https://madklubben.com/?rsvp={$entry['token']}&svar=nej";
                $deadline   = (new DateTime($dinnerDate))->modify('-3 days')->format('d/m/Y');
                $subject    = "Påmindelse: Svar på invitation til Madklubben den $dateFormatted";
                $message    = "Hej $name,\n\nVi mangler stadig dit svar på Madklubben den $dateFormatted.\nKokke: $chefs\n{$locationLine}\nBekræft din deltagelse her:\n$confirmUrl\n\nAfmeld dig her:\n$declineUrl\n\nSvar venligst senest den $deadline.\n\nSes der!\nMadklubben";
                if (sendMail($email, $subject, $message)) $sent++; else $failed[] = $name;
            }
            echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
            exit;
        }
        http_response_code(404);
        echo json_encode(['error' => 'Middag ikke fundet']);
        exit;
    }

    // ── Save dinner detail (tekst) ────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'save_dinner_detail') {
        $dinnerDate = trim($body['dinnerDate'] ?? '');
        $detaljer   = $body['detaljer'] ?? [];
        if (!$dinnerDate) { http_response_code(400); echo json_encode(['error' => 'Manglende dinnerDate']); exit; }
        $dinners = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $found = false;
        foreach ($dinners as &$d) {
            if ($d['date'] !== $dinnerDate) continue;
            if (!isset($d['detaljer'])) $d['detaljer'] = [];
            foreach (['forret', 'hoved', 'dessert'] as $type) {
                if (isset($detaljer[$type]['tekst'])) {
                    if (!isset($d['detaljer'][$type])) $d['detaljer'][$type] = [];
                    $d['detaljer'][$type]['tekst'] = $detaljer[$type]['tekst'];
                }
            }
            if (array_key_exists('tema', $detaljer)) $d['detaljer']['tema'] = $detaljer['tema'];
            $found = true;
            break;
        }
        unset($d);
        if (!$found) { http_response_code(404); echo json_encode(['error' => 'Middag ikke fundet']); exit; }
        file_put_contents($file, json_encode($dinners, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Send email ────────────────────────────────────────────────────
    if (isset($body['action']) && $body['action'] === 'send_email') {
        $subject = trim($body['subject'] ?? '');
        $message = trim($body['message'] ?? '');
        if (!$subject || !$message) { http_response_code(400); echo json_encode(['error' => 'Emne og besked må ikke være tomme']); exit; }
        $failed = [];
        foreach ($recipients as $to) {
            if (!sendMail($to, $subject, $message)) $failed[] = $to;
        }
        echo empty($failed)
            ? json_encode(['ok' => true, 'sent' => count($recipients)])
            : json_encode(['ok' => false, 'failed' => $failed]);
        exit;
    }

    // ── Admin-only: nulstil RSVP ──────────────────────────────────────
    requireRole('admin');

    if (isset($body['action']) && $body['action'] === 'reset_rsvp') {
        $dinnerDate = trim($body['dinnerDate'] ?? '');
        if (!$dinnerDate) { http_response_code(400); echo json_encode(['error' => 'Manglende dinnerDate']); exit; }
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

    // ── Admin-only: gem middageliste ──────────────────────────────────
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
