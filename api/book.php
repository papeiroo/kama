<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* POST api/book.php (JSON) → zapis rezerwacji + powiadomienie e-mail */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Metoda niedozwolona.'], 405);

try {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) json_out(['error' => 'Nieprawidłowe dane.'], 400);

    // Honeypot: boty wypełniają ukryte pole – udajemy sukces i nic nie zapisujemy
    if (trim((string)($in['website'] ?? '')) !== '') json_out(['ok' => true]);

    $date    = (string)($in['date'] ?? '');
    $time    = (string)($in['time'] ?? '');
    $name    = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    $phone   = trim((string)($in['phone'] ?? ''));
    $email   = trim((string)($in['email'] ?? ''));
    $service = trim((string)($in['service'] ?? ''));
    $pet     = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['pet'] ?? ''))), 0, 120);
    $breed   = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['breed'] ?? ''))), 0, 80);

    if (mb_strlen($breed) < 2)                                   json_out(['error' => 'Podaj rasę psa.'], 422);
    // Cena i czas wizyty liczone na serwerze z cennika salonu (rasa + usługa), nie z przeglądarki
    $q = quote($breed, (string)($in['size'] ?? ''), (string)($in['svc'] ?? 'full'), !empty($in['addon']), isset($in['coat']) && is_numeric($in['coat']) ? (int)$in['coat'] : -1);
    if (isset($q['error']))                                      json_out(['error' => $q['error']], 422);
    $size = $q['size']; $dur = (int)$q['min']; $price = (int)$q['price'];
    if ($q['breed']) $breed = $q['breed'];
    $service = $q['service'] . ' · ok. ' . dur_txt($dur) . ' · ' . $price . ' zł';
    $pet = mb_substr(trim($breed . ($pet !== '' ? ' · ' . $pet : '')), 0, 160); // w panelu: „rasa · imię, waga”
    if (empty($in['consent']))                                   json_out(['error' => 'Zaznacz zgodę na kontakt.'], 422);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120)          json_out(['error' => 'Podaj swoje imię.'], 422);
    $digits = preg_replace('/[\s\-()]/', '', $phone);
    if (!preg_match('/^(\+?48)?\d{9}$/', $digits))               json_out(['error' => 'Podaj poprawny numer telefonu.'], 422);
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160))
                                                                 json_out(['error' => 'Adres e-mail jest niepoprawny.'], 422);
    $service = mb_substr($service, 0, 160);

    if ($p = start_problem($date, $time, $dur, $size))           json_out(['error' => $p[0]], $p[1]);
    $today = new DateTime('today');

    // Ochrona przed spamem: max 3 rezerwacje na godzinę z jednego adresu IP i max 3 przyszłe na jeden numer
    $ip = client_ip();
    $st = db()->prepare('SELECT COUNT(*) FROM bookings WHERE ip = ? AND created_at > ?');
    $st->execute([$ip, (new DateTime('-1 hour'))->format('Y-m-d H:i:s')]);
    if ((int)$st->fetchColumn() >= 3)                            json_out(['error' => 'Zbyt wiele rezerwacji w krótkim czasie.'], 429);
    $st = db()->prepare("SELECT COUNT(*) FROM bookings WHERE phone = ? AND status <> 'cancelled' AND bdate >= ?");
    $st->execute([$digits, $today->format('Y-m-d')]);
    if ((int)$st->fetchColumn() >= 3)                            json_out(['error' => 'Masz już kilka zaplanowanych wizyt. Zadzwoń, żeby umówić kolejną.'], 429);

    // Zapis – unikalny slot_key chroni przed podwójną rezerwacją tej samej godziny
    // Tajny kod, dzięki któremu klient może sam odwołać lub przełożyć wizytę przez stronę
    ensure_manage_cols();
    $tok = bin2hex(random_bytes(16));
    $photo = null;
    if (is_string($in['photo'] ?? null) && $in['photo'] !== '') $photo = save_jpeg_dataurl($in['photo'], 'pets');
    try {
        db()->prepare('INSERT INTO bookings (bdate, btime, name, phone, email, service, pet, size, photo, dur, price, status, slot_key, source, ip, created_at, mtoken)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, \'www\', ?, ?, ?)')
            ->execute([$date, $time, $name, $digits, $email ?: null, $service ?: null, $pet ?: null, $size, $photo, $dur, $price, "$date $time", $ip, date('Y-m-d H:i:s'), hash('sha256', $tok)]);
        $ref = db()->lastInsertId() . '.' . $tok;
    } catch (PDOException $e) {
        if ($photo) delete_upload($photo);
        if ($e->getCode() === '23000') json_out(['error' => 'Ten termin został właśnie zajęty.'], 409);
        throw $e;
    }

    // Powiadomienie dla salonu (wielkość psa dopisana do opisu psa)
    $pet = trim(($pet ? $pet . ' · ' : '') . size_label($size) . ($photo ? ' · 📸 zdjęcie psa w panelu' : ''));
    $when = pl_date($date) . ', godz. ' . $time . '–' . m2hm(hm2m($time) + $dur);
    $tel  = phone_pretty($digits);
    $calLink = gcal_add_link($date, $time, "Grooming: " . ($pet ?: $name), "Właściciel: $name\nTelefon: $tel" . ($pet ? "\nPies: $pet" : '') . ($service ? "\nUsługa: $service" : '') . ($email ? "\nE-mail: $email" : ''));
    $body = "ZADZWOŃ DO KLIENTA\n"
          . "==================\n\n"
          . "Imię i nazwisko: $name\n"
          . "Numer telefonu:  $tel\n"
          . ($email ? "E-mail:          $email\n" : '')
          . "\n"
          . ($pet ? "Pies:            $pet\n" : '')
          . "Wybrany termin:  $when\n"
          . ($service ? "Usługa:          $service\n" : '')
          . "\n"
          . "Dodaj wizytę do Kalendarza Google (jedno kliknięcie):\n$calLink\n\n"
          . "Potwierdź lub odwołaj wizytę w panelu:\n" . cfg('site_url') . "/panel/\n";
    $hx = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tel9 = substr($digits, -9);
    $row = fn($k, $v) => '<tr><td style="padding:6px 14px 6px 0;color:#8a7a66;font-size:14px;white-space:nowrap">' . $k . '</td><td style="padding:6px 0;font-size:16px;color:#2b2218">' . $v . '</td></tr>';
    $html = '<!doctype html><html><body style="margin:0;background:#f6f0e4;font-family:Arial,Helvetica,sans-serif">'
      . '<div style="max-width:560px;margin:0 auto;padding:24px 16px">'
      . '<div style="background:#3a2e22;color:#f3dca6;border-radius:14px 14px 0 0;padding:18px 22px;font-size:20px;font-weight:bold;letter-spacing:.5px">📞 ZADZWOŃ DO KLIENTA</div>'
      . '<div style="background:#ffffff;border:1px solid #e8dcc6;border-top:0;border-radius:0 0 14px 14px;padding:22px">'
      . '<p style="margin:0 0 4px;color:#8a7a66;font-size:13px;text-transform:uppercase;letter-spacing:1px">Właściciel psa</p>'
      . '<p style="margin:0 0 14px;font-size:22px;font-weight:bold;color:#2b2218">' . $hx($name) . '</p>'
      . '<p style="margin:0 0 4px;color:#8a7a66;font-size:13px;text-transform:uppercase;letter-spacing:1px">Numer telefonu</p>'
      . '<p style="margin:0 0 18px"><a href="tel:+48' . $hx($tel9) . '" style="font-size:26px;font-weight:bold;color:#8a6528;text-decoration:none">' . $hx($tel) . '</a></p>'
      . '<a href="tel:+48' . $hx($tel9) . '" style="display:inline-block;background:#c9a35c;color:#2b2015;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;font-size:16px">Zadzwoń teraz</a>'
      . '<table style="margin-top:22px;border-collapse:collapse">'
      . ($pet ? $row('Pies', '<b>' . $hx($pet) . '</b>') : '')
      . $row('Termin', '<b>' . $hx($when) . '</b>')
      . ($service ? $row('Usługa', $hx($service)) : '')
      . ($email ? $row('E-mail', '<a href="mailto:' . $hx($email) . '" style="color:#8a6528">' . $hx($email) . '</a>') : '')
      . '</table>'
      . '<p style="margin:22px 0 0;font-size:14px"><a href="' . $hx($calLink) . '" style="color:#8a6528">➕ Dodaj wizytę do Kalendarza Google</a></p>'
      . '<p style="margin:8px 0 0;font-size:14px"><a href="' . $hx(cfg('site_url') . '/panel/') . '" style="color:#8a6528">Otwórz panel rezerwacji</a></p>'
      . '</div></div></body></html>';
    send_mail((string)cfg('notify_email'), "Zadzwoń do klienta: $name" . ($pet ? " ($pet)" : '') . ", $tel – $when", $body, $email ?: null, $html);

    // Potwierdzenie przyjęcia dla klienta (jeśli podała e-mail)
    if ($email) {
        $cb = "Dzień dobry,\n\ndziękujemy za rezerwację wizyty w salonie KamaGrooming.\n\n"
            . "Termin: $when\n" . ($pet ? "Pies: $pet\n" : '') . ($service ? "Usługa: $service\n" : '')
            . "Imię: $name\nTelefon: $tel\n"
            . "\nPotwierdzimy wizytę SMS-em lub telefonicznie.\nPrzed wizytą: nie karm psa 2 h wcześniej i zabierz go na spacer.\n\n"
            . "Zmiana terminu lub odwołanie (do " . manage_hours() . " h przed wizytą):\n" . cfg('site_url') . "/?wizyta=" . $ref . "#terminarz\n"
            . "Później – zadzwoń: " . cfg('phone') . ".\n\n"
            . "Do zobaczenia!\nKamaGrooming – salon pielęgnacji psów\n" . cfg('address') . "\n" . cfg('site_url') . "\n";
        send_mail($email, "Rezerwacja przyjęta: $when", $cb, (string)cfg('notify_email'));
    }

    json_out(['ok' => true, 'ref' => $ref]);
} catch (Throwable $e) {
    error_log('book: ' . $e->getMessage());
    json_out(['error' => 'Błąd serwera.'], 500);
}
