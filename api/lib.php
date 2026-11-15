<?php
declare(strict_types=1);

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $c = require (is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php');
        date_default_timezone_set($c['timezone'] ?? 'Europe/Warsaw');
    }
    return $key === null ? $c : ($c[$key] ?? null);
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
    if (!preg_match('#^uploads/(gallery|certs|avatars)/[0-9a-f-]+\.jpg$#', $rel)) return;
    $f = dirname(__DIR__) . '/' . $rel;
    if (is_file($f)) @unlink($f);
}


/* ---------- ZMIANY WIZYT PRZEZ KLIENTÓW (odwołanie / przełożenie przez stronę) ---------- */
/* Dodaje do tabeli rezerwacji kolumny potrzebne do zarządzania wizytą (raz, automatycznie). */
function ensure_manage_cols(): void
{
    static $done = false; if ($done) return; $done = true;
    if (setting('schema_manage') === '2') return;
    $cols = [
        'mtoken'   => 'VARCHAR(64) NULL',   // skrót (sha256) tajnego kodu klienta
        'chg'      => 'VARCHAR(10) NULL',   // cancel | move – ostatnia zmiana zrobiona przez klienta
        'chg_from' => 'VARCHAR(20) NULL',   // poprzedni termin przy przełożeniu
        'chg_at'   => 'VARCHAR(20) NULL',
        'chg_seen' => 'INT NOT NULL DEFAULT 1',
        'pet'      => 'VARCHAR(160) NULL',  // imię i rasa / waga psa
    ];
    foreach ($cols as $c => $def) {
        try { db()->query("SELECT $c FROM bookings LIMIT 1"); }
        catch (Throwable $e) { db()->exec("ALTER TABLE bookings ADD COLUMN $c $def"); }
    }
    setting('schema_manage', '2');
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
