<?php
// Madklubben – iCal abonnementsfeed
// Returnerer alle kommende middage som en .ics-fil der kan abonneres på.

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="madklubben.ics"');
header('Cache-Control: no-cache, must-revalidate');

$file = __DIR__ . '/dinners.json';
$dinners = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($dinners)) $dinners = [];

// Sorter efter dato
usort($dinners, fn($a, $b) => strcmp($a['date'], $b['date']));

// Middagsnummer – forankret til #66 den 2026-01-31
$anchor_date = '2026-01-31';
$anchor_num  = 66;
$dates = array_column($dinners, 'date');
$anchor_idx = array_search($anchor_date, $dates);

function dinner_number(int $idx, int $anchor_idx, int $anchor_num): int {
    return $anchor_num + ($idx - $anchor_idx);
}

function ics_date(string $date, int $hour): string {
    return str_replace('-', '', $date) . 'T' . str_pad($hour, 2, '0', STR_PAD_LEFT) . '0000';
}

function fold_line(string $line): string {
    $out = '';
    while (strlen($line) > 75) {
        $out  .= substr($line, 0, 75) . "\r\n ";
        $line  = substr($line, 75);
    }
    return $out . $line;
}

$today = date('Y-m-d');

$events = '';
foreach ($dinners as $idx => $d) {
    if ($d['date'] < $today) continue; // kun fremtidige

    $num     = dinner_number($idx, $anchor_idx !== false ? $anchor_idx : 0, $anchor_num);
    $chefs   = implode(' & ', (array)($d['chefs'] ?? ['', '']));
    $summary = "Madklub #$num \u{2014} $chefs";
    $desc    = !empty($d['location']) ? 'Hos ' . $d['location'] : '';

    $events .= "BEGIN:VEVENT\r\n";
    $events .= "DTSTART:" . ics_date($d['date'], 18) . "\r\n";
    $events .= "DTEND:"   . ics_date($d['date'], 23) . "\r\n";
    $events .= fold_line("SUMMARY:$summary") . "\r\n";
    if ($desc) $events .= fold_line("DESCRIPTION:$desc") . "\r\n";
    $events .= "UID:madklub-{$d['date']}@madklubben.com\r\n";
    $events .= "END:VEVENT\r\n";
}

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Madklubben//Madklubben//DA\r\n";
echo "X-WR-CALNAME:Madklubben\r\n";
echo "X-WR-TIMEZONE:Europe/Copenhagen\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo $events;
echo "END:VCALENDAR\r\n";
