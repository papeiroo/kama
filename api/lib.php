<?php
declare(strict_types=1);

function cfg_file(): string { return is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php'; }
function is_configured(): bool { $c = cfg(); return is_file(__DIR__ . '/config.php') && (($c['db']['dsn'] ?? '') !== '' || ($c['db']['name'] ?? 'NAZWA_BAZY') !== 'NAZWA_BAZY'); }

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $c = require cfg_file();
        date_default_timezone_set($c['timezone'] ?? 'Europe/Warsaw');
    }
    if ($key === null) return $c;
    /* ustawienia zmieniane w panelu (Ustawienia / Treści strony) mają pierwszeństwo przed config.php */
    if (in_array($key, ['notify_email', 'phone', 'address', 'google_ical_url', 'slot_minutes', 'days_ahead', 'min_notice_minutes', 'manage_hours', 'big_from', 'big_max', 'from_email', 'remind_mail', 'overrun'], true)) {
        $o = cfg_overlay();
        if (array_key_exists($key, $o) && $o[$key] !== '' && $o[$key] !== null) return $o[$key];
    }
    return $c[$key] ?? null;
}
function cfg_overlay(): array
{
    static $o = null; if ($o !== null) return $o;
    $o = [];
    try {
        $j = json_decode((string)(setting('c_cfg') ?? ''), true); if (is_array($j)) $o = $j;
        $s = site_get(); $ct = $s['contact'] ?? [];
        if (!empty($ct['phone'])) $o['phone'] = $ct['phone'];
        if (!empty($ct['email']) && filter_var($ct['email'], FILTER_VALIDATE_EMAIL)) $o['from_email'] = $ct['email']; // nadawca maili = skrzynka salonu
        $addr = trim(implode(', ', array_filter([$ct['street'] ?? '', $ct['city'] ?? ''])));
        if ($addr !== '') $o['address'] = $addr;
    } catch (Throwable $e) {}
    return $o;
}

/* ---------- TREŚCI STRONY (panel → „Treści strony”) ---------- */
function site_default(): array
{
    static $d = null; if ($d !== null) return $d;
    $d = json_decode((string)@file_get_contents(__DIR__ . '/site-default.json'), true);
    if (!is_array($d)) $d = ['contact' => [], 'seo' => [], 'img' => [], 't' => [], 'l' => [], 'hide' => []];
    return $d;
}
function site_get(): array
{
    $d = site_default();
    try {
        $o = json_decode((string)(setting('c_site') ?? ''), true);
        if (is_array($o)) {
            foreach (['contact', 'seo', 'img', 't', 'l'] as $k) if (isset($o[$k]) && is_array($o[$k])) $d[$k] = array_merge($d[$k] ?? [], $o[$k]);
            if (isset($o['hide']) && is_array($o['hide'])) $d['hide'] = array_values($o['hide']);
        }
    } catch (Throwable $e) {}
    return $d;
}
/* tylko to, co różni się od domyślnych – żeby zmiany w szablonie strony nie były blokowane przez stare zapisy */
function site_save(array $s): void
{
    $d = site_default(); $o = [];
    foreach (['contact', 'seo', 'img', 't', 'l'] as $k) foreach (($s[$k] ?? []) as $kk => $v) if (!array_key_exists($kk, $d[$k] ?? []) || $d[$k][$kk] !== $v) $o[$k][$kk] = $v;
    $o['hide'] = array_values($s['hide'] ?? []);
    setting('c_site', json_encode($o, JSON_UNESCAPED_UNICODE));
}

require_once __DIR__ . '/gcal.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg('db');
    $dsn = $d['dsn'] ?? ('mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4');
    $pdo = new PDO($dsn, $d['user'] ?? null, $d['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function is_sqlite(): bool
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function valid_date(string $s): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

/* Godziny wizyt: standardowe tygodniowe (panel → „Godziny pracy”, inaczej config.php)
   oraz wyjątki na konkretne dni (panel → Kalendarz → dzień → „Godziny w tym dniu”). */
function weekly_hours(): array
{
    static $w = null; if ($w !== null) return $w;
    $w = cfg('hours');
    try { $v = setting('c_hours'); if ($v !== null && is_array($j = json_decode($v, true))) { $w = []; foreach ($j as $k => $t) $w[(int)$k] = array_values($t); } } catch (Throwable $e) {}
    return $w;
}
function day_hours_map(): array
{
    static $m = null; if ($m !== null) return $m;
    $m = [];
    try { $v = setting('c_dayhours'); if ($v !== null && is_array($j = json_decode($v, true))) $m = $j; } catch (Throwable $e) {}
    return $m;
}
function hours_for(string $date): array
{
    $m = day_hours_map();
    if (array_key_exists($date, $m)) return array_values($m[$date]);
    $dow = (int)(new DateTime($date))->format('w');
    return weekly_hours()[$dow] ?? [];
}
/* Porządkuje listę godzin z formularza: HH:MM, bez duplikatów, rosnąco, max 12 */
function clean_times($list): array
{
    $out = [];
    foreach ((array)$list as $t) { $t = trim((string)$t); if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) $out[$t] = true; }
    $out = array_keys($out); sort($out);
    return array_slice($out, 0, 12);
}

function setting(string $k, ?string $v = null): ?string
{
    if ($v === null) {
        $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$k]);
        $r = $st->fetchColumn();
        return $r === false ? null : (string)$r;
    }
    $sql = is_sqlite()
        ? 'INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
        : 'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)';
    db()->prepare($sql)->execute([$k, $v]);
    return $v;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function pl_date(string $date): string
{
    $m = ['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
    $dn = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
    $d = new DateTime($date);
    return $dn[(int)$d->format('w')] . ', ' . (int)$d->format('j') . ' ' . $m[(int)$d->format('n') - 1];
}

function phone_pretty(string $digits): string
{
    $d = preg_replace('/\D/', '', $digits);
    $local = substr($d, -9);
    return '+48 ' . substr($local, 0, 3) . ' ' . substr($local, 3, 3) . ' ' . substr($local, 6, 3);
}

/* Link „Dodaj do Kalendarza Google” z gotowym wydarzeniem wizyty */
function gcal_add_link(string $date, string $time, string $title, string $details): string
{
    $tz = new DateTimeZone((string)(cfg('timezone') ?? 'Europe/Warsaw'));
    $s = new DateTime("$date $time", $tz); $e = (clone $s)->modify('+' . (int)(cfg('slot_minutes') ?? 120) . ' minutes');
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode($title)
        . '&dates=' . $s->format('Ymd\THis') . '/' . $e->format('Ymd\THis')
        . '&ctz=' . rawurlencode($tz->getName())
        . '&details=' . rawurlencode($details);
}

function send_mail(string $to, string $subject, string $body, ?string $replyTo = null, ?string $html = null): bool
{
    $from = cfg('from_email');
    $name = '=?UTF-8?B?' . base64_encode((string)cfg('from_name')) . '?=';
    $headers = ['From: ' . $name . ' <' . $from . '>', 'MIME-Version: 1.0'];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
    if ($html !== null) {
        // wersja HTML (czytelna w poczcie) + zwykły tekst dla prostych programów
        $b = 'kg' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body))
             . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($html))
             . "--$b--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $msg = chunk_split(base64_encode($body));
    }
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (getenv('JW_TEST_MAIL')) { file_put_contents(getenv('JW_TEST_MAIL'), "TO: $to\nSUBJ: $subject\n$body\n" . ($html ? "HTML:\n$html\n" : '') . "---\n", FILE_APPEND); return true; }
    return @mail($to, $subj, $msg, implode("\r\n", $headers), '-f' . $from);
}

/* Removes personal data of old bookings (GDPR retention). */
function purge_old(): void
{
    $months = (int)(cfg('keep_months') ?? 12);
    $cut = (new DateTime("-$months months"))->format('Y-m-d');
    try { $st = db()->prepare('SELECT photo FROM bookings WHERE bdate < ? AND photo IS NOT NULL'); $st->execute([$cut]); foreach ($st as $r) delete_upload((string)$r['photo']); } catch (Throwable $e) {}
    db()->prepare('DELETE FROM bookings WHERE bdate < ?')->execute([$cut]);
    db()->prepare('DELETE FROM blocks WHERE bdate < ?')->execute([(new DateTime('-1 month'))->format('Y-m-d')]);
    $m = day_hours_map(); $cut2 = (new DateTime('-1 month'))->format('Y-m-d'); $n = count($m);
    foreach (array_keys($m) as $d) if ($d < $cut2) unset($m[$d]);
    if (count($m) !== $n) setting('c_dayhours', json_encode($m));
}

/* ---------- TREŚCI EDYTOWANE W PANELU (cennik, galeria, certyfikaty) ---------- */
function content_defaults(): array
{
    /* Domyślne treści (do czasu pierwszej edycji w panelu). Ceny są przykładowe – ustaw prawdziwe w panelu → Cennik. */
    $gal = json_decode((string)@file_get_contents(__DIR__ . '/gallery-default.json'), true);
    return [
        'prices' => [
            ['name' => 'Strzyżenie i stylizacja', 'desc' => 'kąpiel, strzyżenie, uszy i pazury', 'set' => 'od 120', 'refill' => 'od 200'],
            ['name' => 'Kąpiel pielęgnacyjna', 'desc' => 'kąpiel spa, suszenie, rozczesanie', 'set' => 'od 70', 'refill' => 'od 120'],
            ['name' => 'Kąpiel ozonowa', 'desc' => 'ozonoterapia dla zdrowej skóry', 'set' => 'od 90', 'refill' => 'od 150'],
            ['name' => 'Wyczesywanie podszerstka', 'desc' => 'kąpiel + usunięcie martwego włosa', 'set' => 'od 100', 'refill' => 'od 180'],
            ['name' => 'Strzyżenie higieniczne', 'desc' => 'łapki, okolice oczu i intymne', 'set' => 'od 50', 'refill' => 'od 70'],
        ],
        'extras' => [
            ['name' => 'Obcinanie pazurów', 'price' => '20'],
            ['name' => 'Czyszczenie uszu i oczu', 'price' => '20'],
            ['name' => 'Rozczesywanie kołtunów', 'price' => 'od 20'],
            ['name' => 'Wizyta adaptacyjna szczeniaka', 'price' => '50'],
        ],
        'note' => 'Ceny w złotych. Ostateczna cena zależy od wielkości psa, rodzaju i stanu sierści – dokładną wycenę podamy przy potwierdzeniu wizyty.',
        'gallery' => is_array($gal) ? $gal : [],
        'certs' => [],
        'reviews' => [
            ['name' => 'Wiktoria W.', 'stars' => 5, 'source' => 'Facebook', 'link' => 'https://www.facebook.com/profile.php?id=61572040829555&sk=reviews', 'text' => 'Z całego serca polecam! To była nasza pierwsza wizyta i na pewno nie ostatnia. Przemiła, ciepła i bardzo profesjonalna Pani, która ma świetne podejście do piesków. Strzyżenie wykonane z ogromną starannością. Dodatkowo na pierwszej wizycie dostaliśmy urocze prezenciki, co było bardzo miłym akcentem. Widać, że to miejsce tworzone jest z pasją i miłością do zwierząt. Gorąco polecam każdemu!', 'img' => ''],
            ['name' => 'Ilona P.', 'stars' => 5, 'source' => 'Facebook', 'link' => 'https://www.facebook.com/profile.php?id=61572040829555&sk=reviews', 'text' => 'Moja Lusi wygląda ślicznie. Profesjonalne podejście do klienta. Polecamy to miejsce! Ps. dziękujemy za prezenciki', 'img' => ''],
            ['name' => 'Patrycja R.', 'stars' => 5, 'source' => 'Facebook', 'link' => 'https://www.facebook.com/profile.php?id=61572040829555&sk=reviews', 'text' => 'Polecam! Mój pies wrócił zadbany, pachnący i zadowolony.', 'img' => ''],
            ['name' => 'Zuzanna P.', 'stars' => 5, 'source' => 'Facebook', 'link' => 'https://www.facebook.com/profile.php?id=61572040829555&sk=reviews', 'text' => 'Pani przemiła, uśmiechnięta i wykonała świetną robotę! Byłyśmy pierwszy raz i na pewno wrócimy ❤️', 'img' => ''],
            ['name' => 'Natalia D.', 'stars' => 5, 'source' => 'Facebook', 'link' => 'https://www.facebook.com/profile.php?id=61572040829555&sk=reviews', 'text' => 'Jestem bardzo zadowolona z wizyty u Pani, polecamy ☺️', 'img' => ''],
            ['name' => 'Daniel L.', 'stars' => 5, 'source' => 'GoWork', 'link' => 'https://www.gowork.pl/opinie_czytaj,27415815', 'text' => 'Pierwsze profesjonalne strzyżenie 8-miesięcznej „żabki” shih tzu i wyszło super. Miła, pozytywna Pani – polecam! 🐕', 'img' => ''],
        ],
    ];
}

function content_get(): array
{
    $d = content_defaults();
    try {
        foreach (['prices', 'extras', 'note', 'gallery', 'certs', 'reviews'] as $k) {
            $v = setting('c_' . $k);
            if ($v !== null) { $j = json_decode($v, true); if ($j !== null) $d[$k] = $j; }
        }
    } catch (Throwable $e) {}
    return $d;
}

function content_set(string $k, $v): void { setting('c_' . $k, json_encode($v, JSON_UNESCAPED_UNICODE)); }

/* Zapis obrazka JPEG przysłanego z panelu jako data:image/jpeg;base64,... */
function save_jpeg_dataurl(string $data, string $dir): ?string
{
    if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=\s]+)$#', $data, $m)) return null;
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 1000 || strlen($bin) > 4 * 1024 * 1024) return null;
    if (substr($bin, 0, 3) !== "\xFF\xD8\xFF") return null;
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 3000 || $info[1] > 3000) return null;
    $abs = dirname(__DIR__) . '/uploads/' . $dir;
    if (!is_dir($abs)) @mkdir($abs, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
    if (file_put_contents("$abs/$name", $bin) === false) return null;
    return "uploads/$dir/$name";
}

function delete_upload(string $rel): void
{
    if (!preg_match('#^uploads/(gallery|certs|avatars|site|pets)/[0-9a-f-]+\.jpg$#', $rel)) return;
    $f = dirname(__DIR__) . '/' . $rel;
    if (is_file($f)) @unlink($f);
}


/* ---------- ZMIANY WIZYT PRZEZ KLIENTÓW (odwołanie / przełożenie przez stronę) ---------- */
/* Dodaje do tabeli rezerwacji kolumny potrzebne do zarządzania wizytą (raz, automatycznie). */
function ensure_manage_cols(): void
{
    static $done = false; if ($done) return; $done = true;
    if (setting('schema_manage') === '7') return;
    $cols = [
        'mtoken'   => 'VARCHAR(64) NULL',   // skrót (sha256) tajnego kodu klienta
        'chg'      => 'VARCHAR(10) NULL',   // cancel | move – ostatnia zmiana zrobiona przez klienta
        'chg_from' => 'VARCHAR(20) NULL',   // poprzedni termin przy przełożeniu
        'chg_at'   => 'VARCHAR(20) NULL',
        'chg_seen' => 'INT NOT NULL DEFAULT 1',
        'rem_sms'  => 'INT NOT NULL DEFAULT 0',  // przypomnienie SMS wysłane z panelu
        'rem_mail' => 'INT NOT NULL DEFAULT 0',  // przypomnienie e-mail wysłane automatycznie
        'pet'      => 'VARCHAR(160) NULL',  // imię i rasa / waga psa
        'size'     => 'VARCHAR(10) NULL',   // small | medium | big
        'photo'    => 'VARCHAR(80) NULL',   // zdjęcie psa (uploads/pets – widoczne tylko w panelu)
        'dur'      => 'INT NULL',           // czas wizyty w minutach (wg rasy i usługi)
        'price'    => 'INT NULL',           // orientacyjna cena w zł
    ];
    foreach ($cols as $c => $def) {
        try { db()->query("SELECT $c FROM bookings LIMIT 1"); }
        catch (Throwable $e) { db()->exec("ALTER TABLE bookings ADD COLUMN $c $def"); }
    }
    try { db()->query('SELECT bend FROM blocks LIMIT 1'); } catch (Throwable $e) { db()->exec('ALTER TABLE blocks ADD COLUMN bend VARCHAR(5) NULL'); }
    /* „medium” ma 6 znaków – stara kolumna VARCHAR(5) nie mieściła średnich psów */
    if (!is_sqlite()) { try { db()->exec('ALTER TABLE bookings MODIFY size VARCHAR(10) NULL'); } catch (Throwable $e) { error_log('size col: ' . $e->getMessage()); } }
    setting('schema_manage', '7');
}

/* ---------- RASY, USŁUGI, CENY I CZAS WIZYTY (panel → „Rasy i ceny”) ---------- */
function breeds_default(): array
{
    static $d = null; if ($d !== null) return $d;
    $d = json_decode((string)@file_get_contents(__DIR__ . '/breeds-default.json'), true) ?: ['breeds' => [], 'sizes' => [], 'services' => [], 'addon' => null];
    return $d;
}
function breeds_get(): array
{
    static $c = null; if ($c !== null) return $c;
    $c = breeds_default();
    try { $o = json_decode((string)(setting('c_breeds') ?? ''), true); if (is_array($o)) foreach (['breeds', 'sizes', 'services', 'addon'] as $k) if (array_key_exists($k, $o)) $c[$k] = $o[$k]; } catch (Throwable $e) { $o = null; }
    /* jednorazowo: mały mieszaniec z wariantami sierści (krótko / średnio / długowłosy) zamiast „Kundelek do 7 kg” */
    if (is_array($o ?? null) && isset($o['breeds']) && setting('c_mix1') !== '1') {
        $has = false; foreach ($c['breeds'] as $b) if (!empty($b['v'])) $has = true;
        if (!$has) {
            $mix = null; foreach (breeds_default()['breeds'] as $b) if (!empty($b['v'])) $mix = $b;
            if ($mix) { $c['breeds'] = array_values(array_filter($c['breeds'], fn($b) => norm_txt((string)$b['n']) !== norm_txt('Kundelek do 7 kg')));
                $c['breeds'][] = $mix; usort($c['breeds'], fn($x, $y) => strcoll(mb_strtolower($x['n']), mb_strtolower($y['n'])));
                try { $o['breeds'] = $c['breeds']; setting('c_breeds', json_encode($o, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {} }
        }
        try { setting('c_mix1', '1'); } catch (Throwable $e) {}
    }
    /* jednorazowo: „Kundelek 8–18 kg” → „Mieszaniec / kundelek – średni” (spójnie z małym mieszańcem) */
    if (is_array($o ?? null) && isset($o['breeds']) && setting('c_mix2') !== '1') {
        $ch = false;
        foreach ($c['breeds'] as &$b) if (norm_txt((string)$b['n']) === norm_txt('Kundelek 8–18 kg')) { $b['n'] = 'Mieszaniec / kundelek – średni'; $b['a'] = 'kundelek kundel mieszaniec mieszanka sredni 8-18 kg'; $ch = true; }
        unset($b);
        if ($ch) { usort($c['breeds'], fn($x, $y) => strcoll(mb_strtolower($x['n']), mb_strtolower($y['n']))); try { $o['breeds'] = $c['breeds']; setting('c_breeds', json_encode($o, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {} }
        try { setting('c_mix2', '1'); } catch (Throwable $e) {}
    }
    /* jednorazowo (wytyczne Kamy): SPA ozonowe i dodatek SPA – bez dużych psów; bez „trymowania” w opisie */
    if (is_array($o ?? null) && isset($o['services']) && setting('c_spa1') !== '1') {
        foreach ($c['services'] as &$sv) {
            if (($sv['k'] ?? '') === 'spa' && empty($sv['no'])) $sv['no'] = ['big'];
            if (($sv['k'] ?? '') === 'full' && isset($sv['d'])) $sv['d'] = str_replace(['strzyżenie lub trymowanie', 'strzyżenie / trymowanie'], 'strzyżenie', (string)$sv['d']);
        }
        unset($sv);
        if (is_array($c['addon'] ?? null) && empty($c['addon']['no'])) $c['addon']['no'] = ['big'];
        try { $o['services'] = $c['services']; $o['addon'] = $c['addon']; setting('c_breeds', json_encode($o, JSON_UNESCAPED_UNICODE)); setting('c_spa1', '1'); } catch (Throwable $e) {}
    }
    /* jednorazowo: jedna pozycja „Mieszaniec / kundelek” na liście – pod spodem wybór mały / średni / duży */
    if (is_array($o ?? null) && isset($o['breeds']) && setting('c_mix3') !== '1') {
        $G = 'Mieszaniec / kundelek'; $hasBig = false;
        foreach ($c['breeds'] as &$b) if (str_starts_with(norm_txt((string)$b['n']), norm_txt($G))) { $b['g'] = $G; if ($b['s'] === 'big') $hasBig = true; }
        unset($b);
        if (!$hasBig) $c['breeds'][] = ['n' => $G . ' – duży', 's' => 'big', 'p' => (int)($c['sizes']['big']['p'] ?? 220), 'm' => (int)($c['sizes']['big']['m'] ?? 210), 'a' => 'kundelek kundel mieszaniec mieszanka duzy', 'g' => $G];
        usort($c['breeds'], fn($x, $y) => strcoll(mb_strtolower($x['n']), mb_strtolower($y['n'])));
        try { $o['breeds'] = $c['breeds']; setting('c_breeds', json_encode($o, JSON_UNESCAPED_UNICODE)); setting('c_mix3', '1'); } catch (Throwable $e) {}
    }
    return $c;
}
function norm_txt(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $s));
}
/* Rasa z listy salonu (dokładna nazwa) – inaczej null = „inna rasa”, wielkość wybiera klient */
function breed_find(string $txt): ?array
{
    $q = norm_txt($txt); if ($q === '') return null;
    foreach (breeds_get()['breeds'] as $b) if (norm_txt((string)$b['n']) === $q) return $b;
    return null;
}
function size_label(string $s): string { return ['small' => 'mały pies', 'medium' => 'średni pies', 'big' => 'DUŻY PIES'][$s] ?? ''; }
/* Wycena: ['breed' => nazwa|null, 'size', 'service' => opis, 'price' => zł, 'min' => minuty] albo ['error' => …] */
function quote(string $breedTxt, string $size, string $svcKey, bool $addon, int $coat = -1): array
{
    $c = breeds_get(); $b = breed_find($breedTxt);
    if (!$b && $breedTxt !== '') foreach ($c['breeds'] as $x) if (!empty($x['g']) && norm_txt((string)$x['g']) === norm_txt($breedTxt) && $x['s'] === $size) $b = $x;
    if ($b) $size = (string)$b['s'];
    if (!in_array($size, ['small', 'medium', 'big'], true)) return ['error' => 'Zaznacz, czy pies jest mały, średni czy duży.'];
    $svc = null; foreach ($c['services'] as $s) if (($s['k'] ?? '') === $svcKey) $svc = $s;
    if (!$svc) $svc = $c['services'][0] ?? null;
    if (!$svc) return ['error' => 'Brak usług w cenniku.'];
    $SZN = ['small' => 'małych', 'medium' => 'średnich', 'big' => 'dużych'];
    if (in_array($size, (array)($svc['no'] ?? []), true)) return ['error' => '„' . $svc['n'] . '” nie jest dostępne dla ' . $SZN[$size] . ' psów.'];
    $sz = $c['sizes'][$size] ?? ['p' => 0, 'm' => 120];
    switch ($svc['type'] ?? 'fixed') {
        case 'breed': $p = (int)($b['p'] ?? $sz['p']); $m = (int)($b['m'] ?? $sz['m']);
            if (!empty($b['v'])) { $v = $b['v'][$coat] ?? null; if (!$v) return ['error' => 'Wybierz długość sierści psa.'];
                $p = (int)$v['p']; $m = (int)$v['m']; $coatTxt = (string)$v['n']; }
            break;
        case 'size':  $p = (int)($svc['p'][$size] ?? 0); $m = (int)($svc['m'][$size] ?? 60); break;
        default:      $p = (int)($svc['p'] ?? 0); $m = (int)($svc['m'] ?? 60);
    }
    $label = (string)$svc['n'] . (isset($coatTxt) ? ' (' . $coatTxt . ')' : '');
    $ad = $c['addon'] ?? null;
    if ($addon && $ad && in_array($svc['k'], (array)($ad['for'] ?? []), true) && !in_array($size, (array)($ad['no'] ?? []), true)) { $p += (int)$ad['p']; $m += (int)$ad['m']; $label .= ' + ' . $ad['n']; }
    return ['breed' => $b ? (string)$b['n'] : null, 'size' => $size, 'service' => $label, 'svc' => (string)$svc['k'], 'price' => $p, 'min' => max(15, $m)];
}
function dur_txt(int $m): string { $h = intdiv($m, 60); $r = $m % 60; return ($h ? $h . ' h' : '') . ($h && $r ? ' ' : '') . ($r ? $r . ' min' : ''); }

/* ---------- GODZINY OTWARCIA I WOLNE GODZINY ROZPOCZĘCIA ---------- */
function hm2m(string $t): int { $p = explode(':', $t . ':0'); return (int)$p[0] * 60 + (int)$p[1]; }
function m2hm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
/* Standardowe godziny otwarcia: [0..6 (0 = niedziela) => ['09:00','18:00'], brak = nieczynne] */
function work_week(): array
{
    static $w = null; if ($w !== null) return $w;
    $w = [1 => ['09:00', '18:00'], 2 => ['09:00', '18:00'], 3 => ['09:00', '18:00'], 4 => ['09:00', '18:00'], 5 => ['09:00', '18:00'], 6 => ['09:00', '13:00']];
    try { $j = json_decode((string)(setting('c_work') ?? ''), true); if (is_array($j)) { $w = []; foreach ($j as $k => $v) if (is_array($v) && count($v) === 2) $w[(int)$k] = array_values($v); } } catch (Throwable $e) {}
    return $w;
}
/* Podstawowe godziny wizyt (siatka): domyślnie 9, 11, 13, 15 (sobota 9, 11). Gdy wizyta trwa dłużej,
   kolejna zaczyna się zaraz po niej (shih tzu 9:00–11:30 → następny klient od 11:30). Kto pierwszy, ten lepszy. */
function grid_week(): array
{
    static $g = null; if ($g !== null) return $g;
    $g = [1 => ['09:00', '11:00', '13:00', '15:00'], 2 => ['09:00', '11:00', '13:00', '15:00'], 3 => ['09:00', '11:00', '13:00', '15:00'], 4 => ['09:00', '11:00', '13:00', '15:00'], 5 => ['09:00', '11:00', '13:00', '15:00'], 6 => ['09:00', '11:00']];
    try { $j = json_decode((string)(setting('c_grid') ?? ''), true); if (is_array($j)) { $g = []; foreach ($j as $k => $v) if (is_array($v)) $g[(int)$k] = array_values($v); } } catch (Throwable $e) {}
    return $g;
}
function grid_for(string $date): array
{
    $w = work_for($date); if (!$w) return [];
    $out = [$w[0]];
    foreach (grid_week()[(int)(new DateTime($date))->format('w')] ?? [] as $t) { $m = hm2m((string)$t); if ($m >= $w[0] && $m < $w[1]) $out[] = $m; }
    $out = array_values(array_unique($out)); sort($out);
    return $out;
}
/* Wyjątki na konkretne dni: data => ['10:00','14:00'] albo [] = zamknięte */
function day_work_map(): array
{
    static $m = null; if ($m !== null) return $m;
    $m = [];
    try { $j = json_decode((string)(setting('c_daywork') ?? ''), true); if (is_array($j)) $m = $j; } catch (Throwable $e) {}
    return $m;
}
function work_for(string $date): ?array
{
    $m = day_work_map();
    $v = array_key_exists($date, $m) ? $m[$date] : (work_week()[(int)(new DateTime($date))->format('w')] ?? null);
    if (!is_array($v) || count($v) !== 2) return null;
    $o = hm2m((string)$v[0]); $c = hm2m((string)$v[1]);
    return $c > $o ? [$o, $c] : null;
}
function overrun_min(): int { $v = cfg('overrun'); return ($v === null || $v === '') ? 30 : max(0, min(120, (int)$v)); }
/* Zajęte przedziały (w minutach) dni z zakresu: rezerwacje (wg ich czasu), blokady panelu, Kalendarz Google */
function busy_map(string $from, string $to, int $exceptId = 0): array
{
    ensure_manage_cols();
    $busy = []; $closed = []; $big = [];
    $st = db()->prepare("SELECT id, bdate, btime, dur, size FROM bookings WHERE status <> 'cancelled' AND bdate BETWEEN ? AND ? AND id <> ?");
    $st->execute([$from, $to, $exceptId]);
    foreach ($st as $r) { $s = hm2m((string)$r['btime']); $busy[$r['bdate']][] = [$s, $s + ((int)$r['dur'] ?: 120)]; if (($r['size'] ?? '') === 'big') $big[$r['bdate']] = ($big[$r['bdate']] ?? 0) + 1; }
    $st = db()->prepare('SELECT bdate, btime, bend FROM blocks WHERE bdate BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    foreach ($st as $r) {
        if ($r['btime'] === null || $r['btime'] === '') { $closed[] = $r['bdate']; continue; }
        $s = hm2m((string)$r['btime']); $e = !empty($r['bend']) ? hm2m((string)$r['bend']) : $s + 120;
        $busy[$r['bdate']][] = [$s, max($e, $s + 15)];
    }
    $g = gcal_intervals($from, $to);
    foreach ($g['busy'] as $d => $list) foreach ($list as $iv) $busy[$d][] = $iv;
    $closed = array_merge($closed, $g['blocked_days']);
    foreach ($busy as &$l) usort($l, fn($a, $b) => $a[0] <=> $b[0]); unset($l);
    return ['busy' => $busy, 'closed' => array_values(array_unique($closed)), 'big' => $big];
}
/* Godziny rozpoczęcia: podstawowe godziny (9, 11, 13, 15) + koniec każdej zajętej wizyty („przesunięcie”, np. 11:30 po shih tzu).
   Godzina odpada, gdy wizyta danej rasy nachodzi na inną. Wizyta może skończyć się do $over minut po zamknięciu
   (duży pies – bez limitu). Ten sam algorytm działa w przeglądarce (template.html → startsFor). */
function starts_for(int $o, int $c, array $busy, int $dur, int $over, int $earliest, ?int $bigFrom, array $grid = []): array
{
    $cand = $grid ?: [$o]; $endMax = $c + $over;
    foreach ($busy as $iv) $cand[] = $iv[1];
    /* duży pies: od godz. $bigFrom (np. 15:00) bez limitu końca – Kama dostaje ostrzeżenie w panelu;
       gdy salon zamyka się wcześniej (sobota) – start tak, by skończyć ok. zamknięcia */
    if ($bigFrom !== null) { $bf = $bigFrom < $c ? $bigFrom : max($o, $c - $dur); $cand[] = $bf; $earliest = max($earliest, $bf); $endMax = PHP_INT_MAX; }
    $out = [];
    foreach ($cand as $t) {
        if ($t < $o || $t >= $c || $t < $earliest || $t + $dur > $endMax || $t % 5) continue;
        foreach ($busy as $iv) if ($t < $iv[1] && $t + $dur > $iv[0]) continue 2;
        $out[$t] = true;
    }
    $out = array_keys($out); sort($out);
    return $out;
}
function day_starts(string $date, int $dur, string $size, int $exceptId = 0, ?array $bm = null): array
{
    if (!valid_date($date)) return [];
    $today = new DateTime('today'); $d = new DateTime($date);
    if ($d < $today || $d > (new DateTime('today'))->modify('+' . (int)cfg('days_ahead') . ' days')) return [];
    $w = work_for($date); if (!$w) return [];
    $bm = $bm ?? busy_map($date, $date, $exceptId);
    if (in_array($date, $bm['closed'], true)) return [];
    $earliest = 0;
    if ($date === $today->format('Y-m-d')) $earliest = (int)date('G') * 60 + (int)date('i') + (int)cfg('min_notice_minutes');
    $bigFrom = null;
    if ($size === 'big') { $br = big_rules(); if ($br['max'] > 0 && ($bm['big'][$date] ?? 0) >= $br['max']) return []; $bigFrom = hm2m($br['from']); }
    return starts_for($w[0], $w[1], $bm['busy'][$date] ?? [], $dur, overrun_min(), $earliest, $bigFrom, grid_for($date));
}
/* null albo [komunikat, kod HTTP] */
function start_problem(string $date, string $time, int $dur, string $size, int $exceptId = 0): ?array
{
    if (!valid_date($date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return ['Nieprawidłowy termin.', 422];
    if (in_array(hm2m($time), day_starts($date, $dur, $size, $exceptId), true)) return null;
    if ($size === 'big') { $br = big_rules(); $bm = busy_map($date, $date, $exceptId); if ($br['max'] > 0 && ($bm['big'][$date] ?? 0) >= $br['max']) return ['Na ten dzień mamy już zapisanego dużego psa. Ze względu na organizację pracy prosimy o rezerwację w innym terminie.', 409]; }
    return ['Ta godzina jest już niedostępna dla tej wizyty. Wybierz inną.', 409];
}

/* ---------- PRZYPOMNIENIA DZIEŃ PRZED WIZYTĄ ---------- */
function reminder_text(array $r): string
{
    $ct = site_get()['contact'] ?? []; $brand = site_get()['t']['brand'] ?? 'KamaGrooming';
    $tom = (new DateTime('tomorrow'))->format('Y-m-d');
    return 'Dzień dobry, przypominamy o ' . ($r['bdate'] === $tom ? 'jutrzejszej wizycie (' . pl_date($r['bdate']) . ')' : 'wizycie w dniu ' . pl_date($r['bdate'])) . ' o godz. ' . $r['btime'] . ' – ' . $brand
        . (!empty($ct['street']) ? ', ' . $ct['street'] : '') . '. W razie zmiany planów prosimy o informację. Do zobaczenia!';
}
/* automatyczny e-mail z przypomnieniem dla klientów, którzy podali e-mail (uruchamiane przy odwiedzinach strony/panelu, max co 20 min) */
function send_due_reminders(): void
{
    try {
        $on = cfg('remind_mail'); if ($on === 0 || $on === '0') return;
        if ((int)date('G') < 8) return;
        $last = (int)(setting('rem_last') ?? 0); if (time() - $last < 1200) return;
        setting('rem_last', (string)time());
        ensure_manage_cols();
        $tom = (new DateTime('tomorrow'))->format('Y-m-d');
        $st = db()->prepare("SELECT * FROM bookings WHERE bdate = ? AND status <> 'cancelled' AND email IS NOT NULL AND email <> '' AND rem_mail = 0");
        $st->execute([$tom]);
        foreach ($st->fetchAll() as $r) {
            $ok = send_mail((string)$r['email'], 'Przypomnienie: wizyta jutro o ' . $r['btime'],
                reminder_text($r) . "\n\nAdres: " . cfg('address') . "\nTelefon: " . cfg('phone') . "\n\n" . cfg('site_url') . "\n", (string)cfg('notify_email'));
            if ($ok) db()->prepare('UPDATE bookings SET rem_mail = 1 WHERE id = ?')->execute([$r['id']]);
        }
    } catch (Throwable $e) { error_log('reminders: ' . $e->getMessage()); }
}

/* ---------- DUŻE PSY: tylko od wskazanej godziny i limit na dzień (panel → Ustawienia) ---------- */
function big_rules(): array
{
    $f = (string)(cfg('big_from') ?? '15:00'); if (!preg_match('/^\d{2}:\d{2}$/', $f)) $f = '15:00';
    $m = cfg('big_max'); $m = ($m === null || $m === '') ? 1 : max(0, (int)$m);
    return ['from' => $f, 'max' => $m];
}
function big_days(string $from, string $to): array
{
    $b = big_rules(); if ($b['max'] <= 0) return [];
    ensure_manage_cols();
    $st = db()->prepare("SELECT bdate, COUNT(*) n FROM bookings WHERE status <> 'cancelled' AND size = 'big' AND bdate BETWEEN ? AND ? GROUP BY bdate");
    $st->execute([$from, $to]); $out = [];
    foreach ($st as $r) if ((int)$r['n'] >= $b['max']) $out[] = $r['bdate'];
    return $out;
}
/* null albo [komunikat, kod HTTP] */
function big_problem(string $date, string $time, int $exceptId = 0): ?array
{
    $b = big_rules();
    if ($time < $b['from']) return ['Duże psy przyjmujemy od godz. ' . $b['from'] . '. Wybierz późniejszą godzinę.', 422];
    if ($b['max'] > 0) {
        ensure_manage_cols();
        $st = db()->prepare("SELECT COUNT(*) FROM bookings WHERE status <> 'cancelled' AND size = 'big' AND bdate = ? AND id <> ?");
        $st->execute([$date, $exceptId]);
        if ((int)$st->fetchColumn() >= $b['max']) return ['Na ten dzień mamy już zapisanego dużego psa. Ze względu na organizację pracy prosimy o rezerwację w innym terminie.', 409];
    }
    return null;
}

/* Sprawdza, czy termin można zarezerwować. Zwraca null albo [komunikat, kod HTTP]. */
function slot_problem(string $date, string $time): ?array
{
    if (!valid_date($date)) return ['Nieprawidłowa data.', 422];
    $today = new DateTime('today');
    $max   = (new DateTime('today'))->modify('+' . (int)cfg('days_ahead') . ' days');
    $d     = new DateTime($date);
    if ($d < $today || $d > $max) return ['Ten dzień jest poza zakresem rezerwacji.', 422];
    if (!in_array($time, hours_for($date), true)) return ['Wybrana godzina jest niedostępna.', 422];
    $limit = (new DateTime())->modify('+' . (int)cfg('min_notice_minutes') . ' minutes');
    if (new DateTime("$date $time") < $limit) return ['Ta godzina jest już za blisko. Wybierz późniejszą.', 422];
    $st = db()->prepare("SELECT COUNT(*) FROM blocks WHERE bdate = ? AND (btime IS NULL OR btime = '' OR btime = ?)");
    $st->execute([$date, $time]);
    if ((int)$st->fetchColumn() > 0) return ['Ten termin jest niedostępny.', 409];
    $g = gcal_busy($date, $date);
    if (in_array($date, $g['blocked_days'], true) || in_array($time, $g['taken'][$date] ?? [], true)) return ['Ten termin jest niedostępny.', 409];
    return null;
}

/* Ile godzin przed wizytą klient może ją sam zmienić lub odwołać przez stronę */
function manage_hours(): int { return (int)(cfg('manage_hours') ?? 24); }

function can_manage(array $r): bool
{
    if ($r['status'] === 'cancelled') return false;
    return (new DateTime($r['bdate'] . ' ' . $r['btime']))->getTimestamp() - time() >= manage_hours() * 3600;
}

/* Rezerwacja po kodzie „id.token” z przeglądarki klienta albo z linku w e-mailu */
function booking_by_ref(string $ref): ?array
{
    if (!preg_match('/^(\d{1,10})\.([0-9a-f]{32})$/', $ref, $m)) return null;
    ensure_manage_cols();
    $st = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([(int)$m[1]]);
    $r = $st->fetch();
    if (!$r || empty($r['mtoken']) || !hash_equals((string)$r['mtoken'], hash('sha256', $m[2]))) return null;
    return $r;
}
