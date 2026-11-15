<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* POST api/manage.php (JSON) – klient zarządza swoją wizytą kodem „ref” zapisanym w przeglądarce / w linku z e-maila.
   action: get | cancel | move (+ date, time) */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Metoda niedozwolona.'], 405);

function view_of(array $r): array
{
    return ['date' => $r['bdate'], 'time' => $r['btime'], 'status' => $r['status'], 'can_change' => can_manage($r), 'hours' => manage_hours()];
}

try {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) json_out(['error' => 'Nieprawidłowe dane.'], 400);
    $r = booking_by_ref((string)($in['ref'] ?? ''));
    if (!$r) json_out(['error' => 'Nie znaleziono rezerwacji.'], 404);
    $action = (string)($in['action'] ?? 'get');
    if ($action === 'get') json_out(['ok' => true] + view_of($r));

    $tooLate = 'Do wizyty zostało mniej niż ' . manage_hours() . ' h – zmianę uzgodnij telefonicznie: ' . cfg('phone') . '.';
    if ($r['status'] === 'cancelled') json_out(['error' => 'Ta wizyta jest już odwołana.'], 409);
    if (!can_manage($r)) json_out(['error' => $tooLate], 409);

    $oldWhen = pl_date($r['bdate']) . ', godz. ' . $r['btime'];
    $tel = phone_pretty((string)$r['phone']); $tel9 = substr(preg_replace('/\D/', '', (string)$r['phone']), -9);
    $panel = cfg('site_url') . '/panel/';
    $now = date('Y-m-d H:i:s');

    if ($action === 'cancel') {
        db()->prepare("UPDATE bookings SET status = 'cancelled', slot_key = NULL, chg = 'cancel', chg_at = ?, chg_seen = 0 WHERE id = ?")
            ->execute([$now, $r['id']]);
        $body = "KLIENT ODWOŁAŁ WIZYTĘ\n=====================\n\n"
              . "Klient:    {$r['name']}\n" . (!empty($r['pet']) ? "Pies:      {$r['pet']}\n" : '') . "Telefon:   $tel\nTermin:    $oldWhen\n\n"
              . "Termin jest znów wolny na stronie.\nPanel: $panel\n";
        send_mail((string)cfg('notify_email'), "Odwołana wizyta: {$r['name']} ($oldWhen)", $body, $r['email'] ?: null,
            notice_html('❌ KLIENT ODWOŁAŁ WIZYTĘ', $r['name'] . (!empty($r['pet']) ? ' · 🐾 ' . $r['pet'] : ''), $tel, $tel9, [['Termin', '<s>' . htmlspecialchars($oldWhen) . '</s>']], 'Termin jest znów wolny na stronie.', $panel));
        if (!empty($r['email'])) send_mail((string)$r['email'], "Wizyta odwołana: $oldWhen",
            "Dzień dobry,\n\nTwoja wizyta ($oldWhen) została odwołana.\nZapraszamy ponownie: " . cfg('site_url') . "\n\nKamaGrooming\n", (string)cfg('notify_email'));
        $r['status'] = 'cancelled';
        json_out(['ok' => true] + view_of($r));
    }

    if ($action === 'move') {
        $date = (string)($in['date'] ?? ''); $time = (string)($in['time'] ?? '');
        if ($date === $r['bdate'] && $time === $r['btime']) json_out(['error' => 'To jest ten sam termin.'], 422);
        if ($p = slot_problem($date, $time)) json_out(['error' => $p[0]], $p[1]);
        try {
            db()->prepare("UPDATE bookings SET bdate = ?, btime = ?, slot_key = ?, status = 'pending', chg = 'move', chg_from = ?, chg_at = ?, chg_seen = 0 WHERE id = ?")
                ->execute([$date, $time, "$date $time", $r['bdate'] . ' ' . $r['btime'], $now, $r['id']]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') json_out(['error' => 'Ten termin został właśnie zajęty. Wybierz inny.'], 409);
            throw $e;
        }
        $newWhen = pl_date($date) . ', godz. ' . $time;
        $body = "KLIENT PRZEŁOŻYŁ WIZYTĘ – POTWIERDŹ NOWY TERMIN\n===============================================\n\n"
              . "Klient:       {$r['name']}\n" . (!empty($r['pet']) ? "Pies:         {$r['pet']}\n" : '') . "Telefon:      $tel\n"
              . "Było:         $oldWhen\nNowy termin:  $newWhen\n"
              . (!empty($r['service']) ? "Usługa:       {$r['service']}\n" : '')
              . "\nPotwierdź nowy termin SMS-em w panelu (albo odwołaj): $panel\n";
        send_mail((string)cfg('notify_email'), "Zmiana terminu: {$r['name']} → $newWhen", $body, $r['email'] ?: null,
            notice_html('🔁 KLIENT PRZEŁOŻYŁ WIZYTĘ', $r['name'] . (!empty($r['pet']) ? ' · 🐾 ' . $r['pet'] : ''), $tel, $tel9,
                [['Było', '<s>' . htmlspecialchars($oldWhen) . '</s>'], ['Nowy termin', '<b>' . htmlspecialchars($newWhen) . '</b>']]
                + (!empty($r['service']) ? [2 => ['Usługa', htmlspecialchars((string)$r['service'])]] : []),
                'Potwierdź nowy termin w panelu przyciskiem „Potwierdź z SMS” (albo odwołaj wizytę).', $panel));
        if (!empty($r['email'])) send_mail((string)$r['email'], "Zmiana terminu przyjęta: $newWhen",
            "Dzień dobry,\n\nprzyjęliśmy prośbę o zmianę terminu.\n\nByło: $oldWhen\nNowy termin: $newWhen\n\n"
            . "Potwierdzimy nowy termin SMS-em. W razie pytań: " . cfg('phone') . ".\n\nKamaGrooming\n" . cfg('site_url') . "\n", (string)cfg('notify_email'));
        $r['bdate'] = $date; $r['btime'] = $time; $r['status'] = 'pending';
        json_out(['ok' => true] + view_of($r));
    }
    json_out(['error' => 'Nieznana operacja.'], 400);
} catch (Throwable $e) {
    error_log('manage: ' . $e->getMessage());
    json_out(['error' => 'Błąd serwera.'], 500);
}

/* E-mail HTML dla salonu w tym samym stylu co „Zadzwoń do klienta” */
function notice_html(string $title, string $name, string $tel, string $tel9, array $rows, string $note, string $panel): string
{
    $hx = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tr = '';
    foreach ($rows as $r) $tr .= '<tr><td style="padding:6px 14px 6px 0;color:#8a7a66;font-size:14px;white-space:nowrap">' . $r[0] . '</td><td style="padding:6px 0;font-size:16px;color:#2b2218">' . $r[1] . '</td></tr>';
    return '<!doctype html><html><body style="margin:0;background:#f6f0e4;font-family:Arial,Helvetica,sans-serif">'
      . '<div style="max-width:560px;margin:0 auto;padding:24px 16px">'
      . '<div style="background:#3a2e22;color:#f3dca6;border-radius:14px 14px 0 0;padding:18px 22px;font-size:20px;font-weight:bold;letter-spacing:.5px">' . $hx($title) . '</div>'
      . '<div style="background:#ffffff;border:1px solid #e8dcc6;border-top:0;border-radius:0 0 14px 14px;padding:22px">'
      . '<p style="margin:0 0 4px;color:#8a7a66;font-size:13px;text-transform:uppercase;letter-spacing:1px">Właściciel psa</p>'
      . '<p style="margin:0 0 14px;font-size:22px;font-weight:bold;color:#2b2218">' . $hx($name) . '</p>'
      . '<p style="margin:0 0 18px"><a href="tel:+48' . $hx($tel9) . '" style="font-size:22px;font-weight:bold;color:#8a6528;text-decoration:none">' . $hx($tel) . '</a></p>'
      . '<table style="border-collapse:collapse">' . $tr . '</table>'
      . '<p style="margin:18px 0 0;font-size:15px;color:#2b2218">' . $hx($note) . '</p>'
      . '<p style="margin:18px 0 0"><a href="' . $hx($panel) . '" style="display:inline-block;background:#c9a35c;color:#2b2015;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;font-size:16px">Otwórz panel</a></p>'
      . '</div></div></body></html>';
}
