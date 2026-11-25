<?php
declare(strict_types=1);
require __DIR__ . '/../api/lib.php';
require __DIR__ . '/site-editor.php';
require __DIR__ . '/breeds-editor.php';
require __DIR__ . '/clients.php';
require __DIR__ . '/voice.php';
if (!is_configured()) { header('Location: ../api/install.php'); exit; }

/* PANEL REZERWACJI KAMAGROOMING – logowanie hasłem ustawionym w api/install.php */
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/panel/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_name('kgpanel');
session_start();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function go(string $q = ''): void { header('Location: ./' . $q); exit; }
function chg_badge(array $r): string
{
    if (($r['chg'] ?? '') !== 'move' || empty($r['chg_from'])) return '';
    $p = explode(' ', (string)$r['chg_from']);
    return '<span class="src mv">🔁 przełożona przez klienta' . (count($p) === 2 ? ' · było: ' . h(pl_date($p[0]) . ', ' . $p[1]) : '') . '</span>';
}
function pet_pic(array $r): string { return !empty($r['photo']) ? '<a class="ppic" href="?photo=' . (int)$r['id'] . '" target="_blank" rel="noopener" title="Zdjęcie psa – powiększ"><img src="?photo=' . (int)$r['id'] . '" alt="Zdjęcie psa" loading="lazy"></a>' : ''; }
function resched_form(array $r, string $back): string
{
    if (($r['status'] ?? '') === 'cancelled') return '';
    $tel9 = substr(preg_replace('/\D/', '', (string)($r['phone'] ?? '')), -9);
    $c = h(csrf()); $id = (int)$r['id'];
    return '<details class="confirm resch"><summary class="btn sm ghost">🗓 Zmień termin</summary>'
        . '<form method="post" class="rform"><input type="hidden" name="csrf" value="' . $c . '"><input type="hidden" name="a" value="resched"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<p class="hint" style="margin:0">Zaproponuj klientowi inny dzień lub godzinę. Wizyta zostanie przeniesiona i oznaczona jako potwierdzona, a ' . ($tel9 ? 'telefon otworzy gotowego SMS-a do klienta' : 'klient nie ma numeru telefonu') . (!empty($r['email']) ? ' (dostanie też e-mail)' : '') . '.</p>'
        . '<div class="rgrid"><label>Nowy dzień<input type="date" name="date" value="' . h($r['bdate']) . '" min="' . h(date('Y-m-d')) . '" required></label>'
        . '<label>Godzina<input type="time" name="time" value="' . h($r['btime']) . '" step="900" required></label></div>'
        . '<div class="acts">'
        . ($tel9 ? '<a class="btn sm rsms" href="sms:+48' . h($tel9) . '">🗓 Zmień termin (SMS)</a>' : '<button class="btn sm">Zapisz nowy termin</button>')
        . '<button type="button" class="link" onclick="this.closest(\'details\').open=false">Anuluj</button></div></form></details>';
}
function sms_href(string $phone, string $body): string { $t = substr(preg_replace('/\D/', '', $phone), -9); return $t ? 'sms:+48' . $t . '?&body=' . rawurlencode($body) : ''; }
function cancel_sms_body(array $r): string { return 'Dzień dobry, niestety musimy odwołać wizytę ' . pl_date($r['bdate']) . ' o godz. ' . $r['btime'] . '. Przepraszamy – prosimy o kontakt, znajdziemy inny termin. KamaGrooming'; }
function cancel_buttons(array $r): string { $l = cancel_sms_link($r); return $l !== '' ? $l : '<button class="btn sm danger">Tak, odwołaj wizytę</button>'; }
function cancel_sms_link(array $r): string { $hr = sms_href((string)($r['phone'] ?? ''), cancel_sms_body($r)); return $hr ? '<a class="btn sm danger csms" href="' . h($hr) . '">✕ Odwołaj (SMS)</a>' : ''; }
/* po odwołaniu / zmianie terminu zwykłym przyciskiem: przycisk „Wyślij SMS do klienta” na górze panelu */
function sms_after(?array $v = null): ?array { if ($v !== null) { $_SESSION['sms_after'] = $v; return null; } $x = $_SESSION['sms_after'] ?? null; unset($_SESSION['sms_after']); return $x; }
/* ostrzeżenie, gdy wizyta kończy się po zamknięciu salonu (np. duży pies od 15:00 na 4,5 h) */
function late_note(array $r): string
{
    if (($r['status'] ?? '') === 'cancelled') return '';
    $w = work_for((string)$r['bdate']); $dur = (int)($r['dur'] ?? 0) ?: 120; $end = hm2m((string)$r['btime']) + $dur;
    if (!$w || $end <= $w[1]) return '';
    return '<p class="late">⚠️ Uwaga – realizacja ' . h(dur_txt($dur)) . ', skończysz ok. ' . h(m2hm($end)) . ' (zamknięcie ' . h(m2hm($w[1])) . ').</p>';
}
function size_tag(array $r): string { $s = $r['size'] ?? ''; return $s === 'big' ? ' <span class="sz big">DUŻY PIES</span>' : ($s === 'small' ? ' <span class="sz">mały pies</span>' : ($s === 'medium' ? ' <span class="sz">średni pies</span>' : '')); }
function flash(?string $m = null): ?string { if ($m !== null) { $_SESSION['flash'] = $m; return null; } $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

$error = '';
try { $hash = setting('admin_hash'); } catch (Throwable $e) { $hash = null; $error = 'Brak połączenia z bazą. Sprawdź api/config.php i uruchom api/install.php.'; }
if (!$hash && !$error) { header('Location: ../api/install.php'); exit; }

/* ---------- logowanie (z blokadą po 5 błędach na 15 minut) ---------- */
if (empty($_SESSION['auth'])) {
    $lfKey = 'lf_' . md5(client_ip());
    if ($hash && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
        $fails = json_decode(setting($lfKey) ?? '{"n":0,"t":0}', true);
        if ($fails['n'] >= 5 && time() - $fails['t'] < 900) {
            $error = 'Zbyt wiele nieudanych prób. Spróbuj za 15 minut.';
        } elseif (password_verify((string)$_POST['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            setting($lfKey, '{"n":0,"t":0}');
            go();
        } else {
            usleep(800000);
            $n = ($fails['n'] >= 5) ? 1 : $fails['n'] + 1;
            setting($lfKey, json_encode(['n' => $n, 't' => time()]));
            $error = 'Nieprawidłowe hasło.';
        }
    }
    page_head('Logowanie');
    echo '<main class="login"><div class="card"><div class="brand">KamaGrooming</div><h1>Panel rezerwacji</h1>';
    if ($error) echo '<p class="err">' . h($error) . '</p>';
    if ($hash) echo '<form method="post"><label for="pw">Hasło</label><input id="pw" name="password" type="password" autocomplete="current-password" autofocus required><button class="btn">Zaloguj</button></form>';
    elseif (!$error) echo '<p>Panel nie jest jeszcze zainstalowany. Otwórz <code>/api/install.php</code>.</p>';
    echo '</div></main></body></html>';
    exit;
}

try { ensure_manage_cols(); } catch (Throwable $e) { error_log('cols: ' . $e->getMessage()); }

/* zdjęcie psa z rezerwacji – tylko po zalogowaniu */
if (isset($_GET['photo'])) {
    $st = db()->prepare('SELECT photo FROM bookings WHERE id = ?'); $st->execute([(int)$_GET['photo']]); $ph = (string)$st->fetchColumn();
    $f = dirname(__DIR__) . '/' . $ph;
    if (!preg_match('#^uploads/pets/[0-9a-f-]+\.jpg$#', $ph) || !is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400'); readfile($f); exit;
}

/* ---------- akcje (POST + CSRF) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { flash('Sesja wygasła, spróbuj ponownie.'); go(); }
    $a = (string)($_POST['a'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = (string)($_POST['back'] ?? '');
    $back = preg_match('/^\?[a-z=&0-9-]*$/', $back) ? $back : '';
    try {
        switch ($a) {
            case 'remind':
                db()->prepare('UPDATE bookings SET rem_sms = 1 WHERE id = ?')->execute([$id]); break;
            case 'resched':
                $date = (string)($_POST['date'] ?? ''); $time = (string)($_POST['time'] ?? '');
                if (!valid_date($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) { flash('Podaj poprawny dzień i godzinę.'); break; }
                $st = db()->prepare('SELECT * FROM bookings WHERE id = ?'); $st->execute([$id]); $bk = $st->fetch();
                if (!$bk || $bk['status'] === 'cancelled') break;
                if ($bk['bdate'] === $date && $bk['btime'] === $time) { flash('To ten sam termin – nic nie zmieniono.'); break; }
                try {
                    db()->prepare("UPDATE bookings SET bdate = ?, btime = ?, slot_key = ?, status = 'confirmed', chg_seen = 1 WHERE id = ?")->execute([$date, $time, "$date $time", $id]);
                } catch (PDOException $e) { flash($e->getCode() === '23000' ? 'Ta godzina jest już zajęta przez inną wizytę – wybierz inną.' : 'Nie udało się zmienić terminu.'); break; }
                $old = pl_date($bk['bdate']) . ', godz. ' . $bk['btime']; $new = pl_date($date) . ', godz. ' . $time; $mailed = false;
                if (!empty($bk['email'])) {
                    $brand = site_get()['t']['brand'] ?? 'KamaGrooming';
                    $mailed = send_mail((string)$bk['email'], 'Nowy termin wizyty: ' . $new,
                        "Dzień dobry,\n\nzmieniliśmy termin wizyty Twojego psa.\n\nByło: $old\nNowy termin: $new\nAdres: " . cfg('address') . "\n\nJeśli nowy termin nie pasuje, zadzwoń lub napisz SMS: " . cfg('phone') . " – znajdziemy inny.\n\nDo zobaczenia!\n$brand\n" . cfg('site_url') . "\n",
                        (string)cfg('notify_email'));
                }
                if (!empty($bk['phone']) && empty($_POST['nosms'])) sms_after(['phone' => (string)$bk['phone'], 'name' => (string)$bk['name'], 'body' => 'Dzień dobry, proponujemy nowy termin wizyty: ' . pl_date($date) . ' o godz. ' . $time . '. Jeśli nie pasuje, prosimy o informację. KamaGrooming']);
                flash('Termin zmieniony na ' . $new . '.' . ($mailed ? ' Klient dostał e-mail.' : '')); break;
            case 'confirm': case 'cancel':
                $st = db()->prepare('SELECT * FROM bookings WHERE id = ?'); $st->execute([$id]); $bk = $st->fetch();
                if (!$bk) break;
                if ($a === 'confirm') db()->prepare("UPDATE bookings SET status = 'confirmed', chg_seen = 1 WHERE id = ? AND status <> 'cancelled'")->execute([$id]);
                else db()->prepare("UPDATE bookings SET status = 'cancelled', slot_key = NULL, chg_seen = 1 WHERE id = ?")->execute([$id]);
                $mailed = false;
                if (!empty($bk['email']) && $bk['status'] !== ($a === 'confirm' ? 'confirmed' : 'cancelled')) {
                    $when = pl_date($bk['bdate']) . ', godz. ' . $bk['btime']; $sn = site_get(); $brand = $sn['t']['brand'] ?? 'KamaGrooming';
                    $body = $a === 'confirm'
                        ? "Dzień dobry,\n\npotwierdzamy wizytę: $when.\nAdres: " . cfg('address') . "\n\nW razie pytań: " . cfg('phone') . ".\nDo zobaczenia!\n$brand\n" . cfg('site_url') . "\n"
                        : "Dzień dobry,\n\nniestety musimy odwołać wizytę: $when.\nZadzwoń lub napisz SMS, a znajdziemy inny termin: " . cfg('phone') . ".\nMożesz też wybrać nowy termin na stronie: " . cfg('site_url') . "/#rezerwacja\n\nPrzepraszamy i pozdrawiamy,\n$brand\n";
                    $mailed = send_mail((string)$bk['email'], ($a === 'confirm' ? 'Wizyta potwierdzona: ' : 'Wizyta odwołana: ') . $when, $body, (string)cfg('notify_email'));
                }
                if ($a === 'cancel' && $bk['status'] !== 'cancelled' && !empty($bk['phone']) && empty($_POST['nosms'])) sms_after(['phone' => (string)$bk['phone'], 'name' => (string)$bk['name'], 'body' => cancel_sms_body($bk)]);
                flash(($a === 'confirm' ? 'Wizyta zaakceptowana.' : 'Wizyta odwołana. Termin jest znów wolny.') . ($mailed ? ' Klient dostał e-mail.' : '')); break;
            case 'restore':
                /* przywrócenie omyłkowo odwołanej wizyty – tylko gdy termin wciąż wolny */
                $st = db()->prepare('SELECT * FROM bookings WHERE id = ?'); $st->execute([$id]); $bk = $st->fetch();
                if (!$bk || $bk['status'] !== 'cancelled') break;
                $s0 = hm2m((string)$bk['btime']); $e0 = $s0 + ((int)($bk['dur'] ?? 0) ?: 120);
                $bm = busy_map((string)$bk['bdate'], (string)$bk['bdate'], (int)$bk['id']);
                $clash = false; foreach ($bm['busy'][$bk['bdate']] ?? [] as [$a1, $b1]) if ($s0 < $b1 && $e0 > $a1) { $clash = true; break; }
                if ($clash) { flash('Nie można przywrócić – w tym czasie jest już inna wizyta lub blokada. Użyj „Zmień termin” albo dodaj wizytę ręcznie.'); break; }
                try {
                    db()->prepare("UPDATE bookings SET status = 'confirmed', slot_key = ?, chg = NULL, chg_seen = 1 WHERE id = ?")->execute([$bk['bdate'] . ' ' . $bk['btime'], $id]);
                } catch (PDOException $e) { flash('Nie można przywrócić – ta godzina jest już zajęta.'); break; }
                flash('Wizyta przywrócona (' . pl_date($bk['bdate']) . ', ' . $bk['btime'] . '). Daj znać klientce, że wizyta jest aktualna.');
                break;
            case 'seen':
                db()->prepare('UPDATE bookings SET chg_seen = 1 WHERE id = ?')->execute([$id]);
                break;
            case 'delete':
                $st = db()->prepare('SELECT photo FROM bookings WHERE id = ?'); $st->execute([$id]); if ($ph = $st->fetchColumn()) delete_upload((string)$ph);
                db()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
                flash('Rezerwacja usunięta.'); break;
            case 'add':
                $date = (string)($_POST['date'] ?? ''); $time = (string)($_POST['time'] ?? '');
                $name = trim((string)($_POST['name'] ?? '')); $phone = preg_replace('/[\s\-()]/', '', (string)($_POST['phone'] ?? ''));
                if (!valid_date($date) || !preg_match('/^\d{2}:\d{2}$/', $time) || $name === '') { flash('Uzupełnij datę, godzinę i imię.'); break; }
                $breed = trim((string)($_POST['breed'] ?? ''));
                $q = quote($breed, (string)($_POST['size'] ?? ''), (string)($_POST['svc'] ?? 'full'), !empty($_POST['addon']), (int)($_POST['coat'] ?? -1));
                if (isset($q['error'])) { flash(str_contains($q['error'], 'sierści') || str_contains($q['error'], 'dostępne') ? $q['error'] : 'Wpisz rasę z listy albo zaznacz wielkość psa.'); break; }
                $dur = parse_min((string)($_POST['dur'] ?? '')) ?: (int)$q['min'];
                $petTxt = trim(($q['breed'] ?: $breed) . (trim((string)($_POST['pet'] ?? '')) !== '' ? ' · ' . trim((string)$_POST['pet']) : ''), ' ·');
                ensure_manage_cols();
                try {
                    db()->prepare("INSERT INTO bookings (bdate, btime, name, phone, email, service, pet, size, dur, price, status, slot_key, source, ip, created_at)
                                   VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 'confirmed', ?, 'panel', NULL, ?)")
                        ->execute([$date, $time, mb_substr($name, 0, 120), mb_substr($phone, 0, 20), mb_substr($q['service'] . ' · ok. ' . dur_txt($dur) . ' · ' . $q['price'] . ' zł', 0, 160), mb_substr($petTxt, 0, 160) ?: null, $q['size'], $dur, $q['price'], "$date $time", date('Y-m-d H:i:s')]);
                    $wk = work_for($date); $end = hm2m($time) + $dur;
                    flash('Wizyta dodana: ' . $time . '–' . m2hm($end) . '.' . ($wk && $end > $wk[1] ? ' Uwaga – kończy się po zamknięciu salonu (' . m2hm($wk[1]) . ').' : ''));
                } catch (PDOException $e) { flash($e->getCode() === '23000' ? 'Ta godzina jest już zajęta.' : 'Nie udało się dodać wizyty.'); }
                break;
            case 'block':
                $date = (string)($_POST['date'] ?? ''); $time = (string)($_POST['time'] ?? '');
                $to = (string)($_POST['date_to'] ?? ''); $tt = (string)($_POST['time_to'] ?? '');
                ensure_manage_cols();
                if (!valid_date($date)) { flash('Wybierz datę.'); break; }
                if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';
                $end = valid_date($to) && $to >= $date ? $to : $date;
                $d = new DateTime($date); $n = 0;
                while ($d->format('Y-m-d') <= $end && $n < 62) {
                    db()->prepare('INSERT INTO blocks (bdate, btime, bend, note, created_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$d->format('Y-m-d'), $time ?: null, ($time && preg_match('/^\d{2}:\d{2}$/', $tt) && $tt > $time) ? $tt : ($time ? m2hm(hm2m($time) + 60) : null), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 160) ?: null, date('Y-m-d H:i:s')]);
                    $d->modify('+1 day'); $n++;
                }
                flash($n > 1 ? "Zablokowano $n dni." : 'Termin zablokowany.'); break;
            case 'unblock':
                db()->prepare('DELETE FROM blocks WHERE id = ?')->execute([$id]);
                flash('Blokada usunięta.'); break;
            case 'save_prices':
                $items = [];
                $pn = (array)($_POST['p_name'] ?? []);
                foreach ($pn as $i => $n) {
                    $n = trim((string)$n); if ($n === '') continue;
                    $items[] = ['name' => mb_substr($n, 0, 60), 'desc' => mb_substr(trim((string)($_POST['p_desc'][$i] ?? '')), 0, 80),
                                'set' => mb_substr(trim((string)($_POST['p_set'][$i] ?? '')), 0, 12), 'refill' => mb_substr(trim((string)($_POST['p_refill'][$i] ?? '')), 0, 12)];
                }
                $ex = [];
                foreach ((array)($_POST['e_name'] ?? []) as $i => $n) {
                    $n = trim((string)$n); if ($n === '') continue;
                    $ex[] = ['name' => mb_substr($n, 0, 60), 'price' => mb_substr(trim((string)($_POST['e_price'][$i] ?? '')), 0, 12)];
                }
                if (!$items) { flash('Cennik musi mieć co najmniej jedną pozycję.'); break; }
                content_set('prices', $items); content_set('extras', $ex);
                content_set('note', mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300));
                flash('Cennik zapisany. Zmiany są już na stronie.'); break;
            case 'gal_add':
                $after = save_jpeg_dataurl((string)($_POST['data_after'] ?? ''), 'gallery');
                $before = save_jpeg_dataurl((string)($_POST['data_before'] ?? ''), 'gallery');
                if (!$after || !$before) { if ($after) delete_upload($after); if ($before) delete_upload($before); flash('Dodaj oba zdjęcia: PO i PRZED (JPG/PNG z telefonu).'); break; }
                $c = content_get(); $g = array_values(array_filter($c['gallery'], 'is_array'));
                array_unshift($g, ['name' => mb_substr(trim((string)($_POST['dog'] ?? '')), 0, 40), 'after' => $after, 'before' => $before]);
                content_set('gallery', $g); flash('Realizacja dodana na początek galerii.'); break;
            case 'cert_add':
                $path = save_jpeg_dataurl((string)($_POST['data'] ?? ''), 'certs');
                if (!$path) { flash('Nie udało się zapisać zdjęcia. Spróbuj innego pliku (JPG/PNG z telefonu).'); break; }
                $c = content_get();
                $c['certs'][] = ['img' => $path, 'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120)]; content_set('certs', $c['certs']); flash('Certyfikat dodany.');
                break;
            case 'gal_name':
                $c = content_get(); $list = array_values($c['gallery']); $i = (int)($_POST['idx'] ?? -1);
                if (isset($list[$i]) && is_array($list[$i])) { $list[$i]['name'] = mb_substr(trim((string)($_POST['dog'] ?? '')), 0, 40); content_set('gallery', $list); flash('Imię zapisane.'); }
                break;
            case 'gal_move': case 'gal_del': case 'cert_move': case 'cert_del': case 'cert_title':
                $key = strpos($a, 'gal') === 0 ? 'gallery' : 'certs';
                $c = content_get(); $list = array_values($c[$key]); $i = (int)($_POST['idx'] ?? -1);
                if (!isset($list[$i])) break;
                if (substr($a, -4) === 'move') {
                    $j = $i + ((($_POST['dir'] ?? '') === 'up') ? -1 : 1);
                    if (isset($list[$j])) { [$list[$i], $list[$j]] = [$list[$j], $list[$i]]; }
                } elseif (substr($a, -3) === 'del') {
                    if (is_array($list[$i])) { foreach (['img', 'after', 'before'] as $kk) if (!empty($list[$i][$kk])) delete_upload((string)$list[$i][$kk]); } else delete_upload((string)$list[$i]);
                    array_splice($list, $i, 1); flash('Usunięto.');
                } else {
                    $list[$i]['title'] = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120); flash('Podpis zapisany.');
                }
                content_set($key, $list); break;
            case 'day_work':
                $date = (string)($_POST['date'] ?? ''); if (!valid_date($date)) break;
                $m = day_work_map(); $mode = (string)($_POST['mode'] ?? 'save');
                $o = (string)($_POST['o'] ?? ''); $c = (string)($_POST['c'] ?? '');
                if ($mode === 'reset') { unset($m[$date]); flash('Przywrócono standardowe godziny w tym dniu.'); }
                elseif ($mode === 'close') { $m[$date] = []; flash('Ten dzień jest teraz nieczynny dla rezerwacji.'); }
                elseif (preg_match('/^\d{2}:\d{2}$/', $o) && preg_match('/^\d{2}:\d{2}$/', $c) && $c > $o) { $m[$date] = [$o, $c]; flash("Godziny w tym dniu: $o–$c."); }
                else { flash('Godzina zamknięcia musi być późniejsza niż otwarcia.'); break; }
                ksort($m); setting('c_daywork', json_encode($m)); break;
            case 'week_work':
                $w = []; $err = false;
                for ($d = 0; $d <= 6; $d++) {
                    if (empty($_POST["on$d"])) continue;
                    $o = (string)($_POST["o$d"] ?? ''); $c = (string)($_POST["c$d"] ?? '');
                    if (!preg_match('/^\d{2}:\d{2}$/', $o) || !preg_match('/^\d{2}:\d{2}$/', $c) || $c <= $o) { $err = true; continue; }
                    $w[$d] = [$o, $c];
                }
                if ($err) { flash('Popraw godziny – zamknięcie musi być później niż otwarcie. Nic nie zapisano.'); break; }
                setting('c_work', json_encode($w));
                $gw = [];
                for ($d = 0; $d <= 6; $d++) {
                    preg_match_all('/(\d{1,2})[:.](\d{2})|\b(\d{1,2})\b/', (string)($_POST["g$d"] ?? ''), $mm, PREG_SET_ORDER);
                    $ts = [];
                    foreach ($mm as $x) { $hh = (int)($x[1] !== '' ? $x[1] : $x[3]); $mi = (int)($x[2] ?? 0); if ($hh < 24 && $mi < 60) $ts[] = sprintf('%02d:%02d', $hh, $mi); }
                    $ts = array_values(array_unique($ts)); sort($ts); if ($ts) $gw[$d] = $ts;
                }
                setting('c_grid', json_encode($gw));
                $oc = json_decode((string)(setting('c_cfg') ?? ''), true); if (!is_array($oc)) $oc = [];
                $oc['overrun'] = max(0, min(120, (int)($_POST['overrun'] ?? 30))); setting('c_cfg', json_encode($oc, JSON_UNESCAPED_UNICODE));
                flash('Godziny otwarcia zapisane. Kalendarz na stronie już z nich korzysta.'); break;
            case 'rev_add':
                $text = trim((string)($_POST['text'] ?? '')); $nm = trim((string)($_POST['rname'] ?? ''));
                if (mb_strlen($text) < 5 || $nm === '') { flash('Wpisz imię i treść opinii.'); break; }
                $c = content_get();
                $c['reviews'][] = ['name' => mb_substr($nm, 0, 60), 'text' => mb_substr($text, 0, 600), 'stars' => max(1, min(5, (int)($_POST['stars'] ?? 5))), 'source' => mb_substr(trim((string)($_POST['source'] ?? '')), 0, 30), 'link' => (preg_match('#^https?://\S+$#i', $lk = trim((string)($_POST['link'] ?? ''))) ? mb_substr($lk, 0, 500) : ''),
                    'img' => (($d = (string)($_POST['data'] ?? '')) !== '' ? (save_jpeg_dataurl($d, 'avatars') ?? '') : '')];
                content_set('reviews', $c['reviews']); flash('Opinia dodana na stronie.'); break;
            case 'rev_edit':
                $c = content_get(); $list = array_values($c['reviews']); $i = (int)($_POST['idx'] ?? -1);
                if (!isset($list[$i])) break;
                $text = trim((string)($_POST['text'] ?? '')); $nm = trim((string)($_POST['rname'] ?? ''));
                if (mb_strlen($text) < 5 || $nm === '') { flash('Wpisz imię i treść opinii.'); break; }
                $lk = trim((string)($_POST['link'] ?? ''));
                $list[$i] = array_merge($list[$i], ['name' => mb_substr($nm, 0, 60), 'text' => mb_substr($text, 0, 600), 'stars' => max(1, min(5, (int)($_POST['stars'] ?? 5))),
                    'source' => mb_substr(trim((string)($_POST['source'] ?? '')), 0, 30), 'link' => preg_match('#^https?://\S+$#i', $lk) ? mb_substr($lk, 0, 500) : '']);
                content_set('reviews', $list); flash('Opinia zapisana.'); break;
            case 'rev_photo': case 'rev_nophoto':
                $c = content_get(); $list = array_values($c['reviews']); $i = (int)($_POST['idx'] ?? -1);
                if (!isset($list[$i])) break;
                if (!empty($list[$i]['img'])) delete_upload($list[$i]['img']);
                $list[$i]['img'] = '';
                if ($a === 'rev_photo') { $path = save_jpeg_dataurl((string)($_POST['data'] ?? ''), 'avatars'); if (!$path) { flash('Nie udało się zapisać zdjęcia.'); break; } $list[$i]['img'] = $path; flash('Zdjęcie dodane.'); }
                else flash('Zdjęcie usunięte.');
                content_set('reviews', $list); break;
            case 'rev_move': case 'rev_del':
                $c = content_get(); $list = array_values($c['reviews']); $i = (int)($_POST['idx'] ?? -1);
                if (!isset($list[$i])) break;
                if ($a === 'rev_del') { if (!empty($list[$i]['img'])) delete_upload($list[$i]['img']); array_splice($list, $i, 1); flash('Opinia usunięta.'); }
                else { $j = $i + ((($_POST['dir'] ?? '') === 'up') ? -1 : 1); if (isset($list[$j])) [$list[$i], $list[$j]] = [$list[$j], $list[$i]]; }
                content_set('reviews', $list); break;
            case 'bk_edit':
                flash(bk_edit($id)); break;
            case 'client_save': case 'client_del':
                flash(clients_handle($a)); break;
            case 'save_breeds': case 'breeds_reset':
                flash((string)breeds_handle($a)); break;
            case 'site_save': case 'site_reset': case 'site_img': case 'set_pass': case 'set_cfg':
                $m = strpos($a, 'site_') === 0 ? site_handle($a) : settings_handle($a);
                if ($m !== null) flash($m); break;
            case 'logout':
                $_SESSION = []; session_destroy(); go();
        }
    } catch (Throwable $e) { error_log('panel: ' . $e->getMessage()); flash('Wystąpił błąd. Spróbuj ponownie.'); }
    go($back);
}

/* ---------- widoki ---------- */
try { purge_old(); } catch (Throwable $e) {}
$tab = (string)($_GET['t'] ?? 'cal');
$todayS = date('Y-m-d');
$pending = (int)db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending' AND bdate >= '$todayS'")->fetchColumn();

page_head('Rezerwacje');
$f = flash();
?>
<header class="top"><div class="brand">KamaGrooming</div>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="logout"><button class="link">Wyloguj</button></form>
</header>
<nav class="tabs">
  <a href="?t=cal" class="<?= $tab === 'cal' ? 'on' : '' ?>">Kalendarz</a>
  <a href="?t=next" class="<?= $tab === 'next' ? 'on' : '' ?>">Nadchodzące<?= $pending ? ' <b>' . $pending . '</b>' : '' ?></a>
  <a href="?t=add" class="<?= $tab === 'add' ? 'on' : '' ?>">Dodaj wizytę</a>
  <a href="?t=clients" class="<?= $tab === 'clients' ? 'on' : '' ?>">👥 Klienci</a>
  <a href="?t=blocks" class="<?= $tab === 'blocks' ? 'on' : '' ?>">Wolne dni</a>
  <a href="?t=hours" class="<?= $tab === 'hours' ? 'on' : '' ?>">Godziny pracy</a>
  <a href="?t=past" class="<?= $tab === 'past' ? 'on' : '' ?>">Historia</a>
  <a href="?t=prices" class="<?= $tab === 'prices' ? 'on' : '' ?>">🐶 Rasy i ceny</a>
  <a href="?t=gallery" class="<?= $tab === 'gallery' ? 'on' : '' ?>">Galeria</a>
  <a href="?t=certs" class="<?= $tab === 'certs' ? 'on' : '' ?>">Certyfikaty</a>
  <a href="?t=reviews" class="<?= $tab === 'reviews' ? 'on' : '' ?>">Opinie</a>
  <a href="?t=site" class="<?= $tab === 'site' ? 'on' : '' ?>">✏️ Treści strony</a>
  <a href="?t=settings" class="<?= $tab === 'settings' ? 'on' : '' ?>">Ustawienia</a>
</nav>
<main class="wrap">
<?php if ($f): ?><p class="flash"><?= h($f) ?></p><?php endif; ?>
<?php $sa = sms_after(); if ($sa && ($hr = sms_href($sa['phone'], $sa['body']))): ?>
  <div class="note smsbox"><span>📱 Daj znać klientowi (<?= h($sa['name']) ?>) SMS-em – treść jest już gotowa, możesz ją poprawić przed wysłaniem.</span><a class="btn sm" href="<?= h($hr) ?>">Wyślij SMS do klienta</a></div>
<?php endif; ?>
<?php $ct = site_get()['contact'] ?? []; if ($tab !== 'site' && (trim((string)($ct['company'] ?? '')) === '' || trim((string)($ct['nip'] ?? '')) === '')): ?>
  <p class="note">⚖️ Uzupełnij <b>nazwę firmy i NIP</b> w <a href="?t=site&amp;s=dane">Treści strony → Dane salonu i kontakt</a> – pojawią się w polityce prywatności i regulaminie rezerwacji, których wymaga prawo.</p>
<?php endif; ?>
<?php
    /* ---------- zmiany zrobione przez klientów na stronie (do przyjęcia) ---------- */
    $chgs = [];
    try { $chgs = db()->query("SELECT * FROM bookings WHERE chg_seen = 0 AND chg IS NOT NULL ORDER BY chg_at DESC LIMIT 30")->fetchAll(); } catch (Throwable $e) {}
    if ($chgs): ?>
  <section class="chgbox">
    <h2>Zmiany od klientów <span><?= count($chgs) ?></span></h2>
    <?php foreach ($chgs as $c): $cf = $c['chg_from'] ? explode(' ', (string)$c['chg_from']) : null;
        $tel9 = substr(preg_replace('/\D/', '', (string)$c['phone']), -9);
        $sms = 'Dzień dobry, potwierdzam zmianę terminu – wizyta ' . pl_date($c['bdate']) . ' o godz. ' . $c['btime'] . '. Do zobaczenia! KamaGrooming'; ?>
    <div class="chg <?= h($c['chg']) ?>">
      <div class="chg-t">
        <?php if ($c['chg'] === 'cancel'): ?>
          <b><?= h($c['name']) ?></b> odwołuje wizytę <b><?= h(pl_date($c['bdate']) . ', ' . $c['btime']) ?></b>. Termin jest znów wolny.
        <?php else: ?>
          <b><?= h($c['name']) ?></b> przekłada wizytę<?php if ($cf && count($cf) === 2): ?> z <s><?= h(pl_date($cf[0]) . ', ' . $cf[1]) ?></s><?php endif; ?> na <b><?= h(pl_date($c['bdate']) . ', ' . $c['btime']) ?></b>.
          <?= $c['status'] === 'cancelled' ? 'Wizyta jest już odwołana.' : 'Potwierdź nowy termin albo odwołaj wizytę.' ?>
        <?php endif; ?>
        <span class="chg-w"><?= h(date('d.m H:i', strtotime((string)$c['chg_at']))) ?><?php if ($tel9): ?> · <a href="tel:+48<?= h($tel9) ?>"><?= h(phone_pretty((string)$c['phone'])) ?></a><?php endif; ?></span>
      </div>
      <div class="acts">
        <?php if ($c['chg'] === 'move' && $c['status'] !== 'cancelled'): ?>
          <form method="post" class="cform"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="confirm"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="back" value="?t=<?= h($tab ?? 'cal') ?>"><?php if ($tel9): ?><a class="btn sm csms" href="sms:+48<?= h($tel9) ?>?&amp;body=<?= rawurlencode($sms) ?>">✓ Potwierdź (SMS)</a><?php else: ?><button class="btn sm">Potwierdź</button><?php endif; ?></form>
          <a class="btn sm ghost" href="?t=cal&amp;m=<?= h(substr((string)$c['bdate'], 0, 7)) ?>&amp;d=<?= h($c['bdate']) ?>#dzien">Pokaż w kalendarzu</a>
        <?php else: ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="seen"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="back" value="?t=<?= h($tab ?? 'cal') ?>"><button class="btn sm">OK, przyjęłam</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php
    /* ---------- PRZYPOMNIENIA SMS: najbliższy dzień z wizytami (jutro albo do 3 dni naprzód) ---------- */
    send_due_reminders();
    $remDay = null; $rem = [];
    try {
        $st = db()->prepare("SELECT MIN(bdate) FROM bookings WHERE status <> 'cancelled' AND bdate > ? AND bdate <= ?");
        $st->execute([$todayS, (new DateTime('+3 days'))->format('Y-m-d')]); $remDay = $st->fetchColumn() ?: null;
        if ($remDay) { $st = db()->prepare("SELECT * FROM bookings WHERE status <> 'cancelled' AND bdate = ? AND phone IS NOT NULL AND phone <> '' ORDER BY btime"); $st->execute([$remDay]); $rem = $st->fetchAll(); }
    } catch (Throwable $e) {}
    $remLeft = array_filter($rem, fn($r) => empty($r['rem_sms']));
    if ($rem): ?>
  <details class="chgbox rem" <?= $remLeft ? 'open' : '' ?>>
    <summary><h2>🔔 Przypomnienia – <?= h(pl_date($remDay)) ?> <span><?= count($remLeft) ?: '✓' ?></span></h2></summary>
    <p class="hint" style="margin:0 0 8px"><?= $remLeft ? 'Jednym kliknięciem otworzysz gotowego SMS-a z przypomnieniem. Klienci z e-mailem dostają też przypomnienie mailem automatycznie.' : 'Wszystkim klientom na ten dzień wysłano już przypomnienie.' ?></p>
    <?php foreach ($rem as $r): $tel9 = substr(preg_replace('/\D/', '', (string)$r['phone']), -9); ?>
    <div class="chg">
      <div class="chg-t"><b><?= h($r['btime']) ?></b> · <?= h($r['name']) ?><?= !empty($r['pet']) ? ' · 🐶 ' . h($r['pet']) : '' ?><?= $r['status'] === 'pending' ? ' <span class="sz">niepotwierdzona</span>' : '' ?></div>
      <div class="acts">
        <?php if (empty($r['rem_sms'])): ?>
        <form method="post" class="cform"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="remind"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="?t=<?= h($tab) ?>"><a class="btn sm csms" href="<?= h(sms_href((string)$r['phone'], reminder_text($r))) ?>">📩 Przypomnij (SMS)</a></form>
        <?php else: ?><span class="sz ok">✓ przypomniano</span><a class="link" href="<?= h(sms_href((string)$r['phone'], reminder_text($r))) ?>">wyślij ponownie</a><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </details>
<?php endif; ?>

<?php
    /* ---------- NOWE REZERWACJE DO AKCEPTACJI (na każdej zakładce, jak u Jagody: Akceptuj / Odwołaj) ---------- */
    $pend = [];
    if ($tab !== 'next') { try { $st = db()->prepare("SELECT * FROM bookings WHERE status = 'pending' AND bdate >= ? ORDER BY bdate, btime LIMIT 20"); $st->execute([$todayS]); $pend = $st->fetchAll(); } catch (Throwable $e) {} }
    if ($pend): ?>
  <section class="chgbox pend">
    <h2>Nowe rezerwacje do akceptacji <span><?= count($pend) ?></span></h2>
    <?php foreach ($pend as $r): $tel9 = substr(preg_replace('/\D/', '', (string)$r['phone']), -9);
        $sms = 'Dzień dobry, potwierdzam wizytę ' . pl_date($r['bdate']) . ' o godz. ' . $r['btime'] . '. Do zobaczenia! KamaGrooming'; ?>
    <div class="chg">
      <?= pet_pic($r) ?>
      <div class="chg-t"><b><?= h(pl_date($r['bdate'])) ?>, <?= h($r['btime'] . '–' . m2hm(hm2m((string)$r['btime']) + ((int)($r['dur'] ?? 0) ?: 120))) ?></b> · <?= h($r['name']) ?> <?= client_badge($r) ?><?= !empty($r['pet']) ? ' · 🐶 ' . h($r['pet']) : '' ?><?= size_tag($r) ?><?= !empty($r['service']) ? '<span class="chg-w">' . h($r['service']) . '</span>' : '' ?>
        <span class="chg-w"><?php if ($tel9): ?><a href="tel:+48<?= h($tel9) ?>">📞 <?= h(phone_pretty((string)$r['phone'])) ?></a><?php endif; ?><?= !empty($r['email']) ? ' · ' . h($r['email']) : '' ?></span><?= late_note($r) ?></div>
      <div class="acts">
        <form method="post" class="cform"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="confirm"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="?t=<?= h($tab) ?>"><?php if ($tel9): ?><a class="btn sm csms" href="sms:+48<?= h($tel9) ?>?&amp;body=<?= rawurlencode($sms) ?>">✓ Akceptuj (SMS)</a><?php else: ?><button class="btn sm">✓ Akceptuj</button><?php endif; ?></form>
        <?= resched_form($r, '?t=' . $tab) ?>
        <details class="confirm"><summary class="btn sm danger">✕ Odwołaj</summary><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="?t=<?= h($tab) ?>"><?= cancel_buttons($r) ?></form></details>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($tab === 'cal'):
    /* ---------- KALENDARZ MIESIĄCA (jak na stronie) + szczegóły dnia ---------- */
    $m = (string)($_GET['m'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) $m = date('Y-m');
    $first = new DateTime("$m-01");
    $last  = (clone $first)->modify('last day of this month');
    $fromS = $first->format('Y-m-d'); $toS = $last->format('Y-m-d');
    $sel = (string)($_GET['d'] ?? '');
    if (!valid_date($sel) || substr($sel, 0, 7) !== $m) $sel = ($m === date('Y-m')) ? $todayS : '';

    $byDay = [];
    $st = db()->prepare("SELECT * FROM bookings WHERE status <> 'cancelled' AND bdate BETWEEN ? AND ? ORDER BY btime");
    $st->execute([$fromS, $toS]);
    foreach ($st as $r) $byDay[$r['bdate']][] = $r;
    $blkDay = []; $blkSlot = [];
    $st = db()->prepare('SELECT * FROM blocks WHERE bdate BETWEEN ? AND ? ORDER BY btime');
    $st->execute([$fromS, $toS]);
    foreach ($st as $b) { if ($b['btime']) $blkSlot[$b['bdate']][] = $b; else $blkDay[$b['bdate']] = $b; }
    try { $g = gcal_intervals($fromS, $toS); } catch (Throwable $e) { $g = ['busy' => [], 'blocked_days' => []]; }
    $gDay = array_flip($g['blocked_days']);
    try { $bm = busy_map($fromS, $toS); } catch (Throwable $e) { $bm = ['busy' => [], 'closed' => [], 'big' => []]; }
    $dwm = day_work_map();

    $months = ['styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
    $prev = (clone $first)->modify('-1 month')->format('Y-m'); $next = (clone $first)->modify('+1 month')->format('Y-m');
?>
  <section class="cal">
    <div class="cal-top">
      <h2><?= h($months[(int)$first->format('n') - 1] . ' ' . $first->format('Y')) ?></h2>
      <div class="cal-nav"><a class="ib" href="?t=cal&amp;m=<?= h($prev) ?>" aria-label="Poprzedni miesiąc">‹</a><a class="ib" href="?t=cal&amp;m=<?= h(date('Y-m')) ?>" aria-label="Dzisiaj" style="width:auto;padding:0 12px;font-size:.8rem">dziś</a><a class="ib" href="?t=cal&amp;m=<?= h($next) ?>" aria-label="Następny miesiąc">›</a></div>
    </div>
    <div class="dow"><span>Pn</span><span>Wt</span><span>Śr</span><span>Cz</span><span>Pt</span><span>So</span><span>Nd</span></div>
    <div class="days">
<?php
    $lead = ((int)$first->format('N')) - 1;
    $nowM = (int)date('G') * 60 + (int)date('i'); $daySum = [];
    for ($i = 0; $i < $lead; $i++) echo '<span class="day empty"></span>';
    for ($d = clone $first; $d <= $last; $d->modify('+1 day')):
        $ds = $d->format('Y-m-d'); $w = work_for($ds);
        $bk = $byDay[$ds] ?? [];
        $closed = !$w; $dayOff = isset($blkDay[$ds]) || isset($gDay[$ds]);
        $free = ($closed || $dayOff || $ds < $todayS) ? 0 : count(day_starts($ds, 150, 'small', 0, $bm));
        $cls = 'day';
        if ($ds < $todayS) $cls .= ' past';
        if ($closed && !$bk) $cls .= ' off';
        elseif ($dayOff) $cls .= ' blocked';
        elseif (!$free && !$closed && $ds >= $todayS) $cls .= ' full';
        if ($ds === $todayS) $cls .= ' today';
        if ($ds === $sel) $cls .= ' sel';
        if (array_key_exists($ds, $dwm)) $cls .= ' custom';
        $dots = ''; $sum = ['f' => $free, 'p' => 0, 'c' => 0, 'd' => 0];
        foreach ($bk as $r) {
            $done = $ds < $todayS || ($ds === $todayS && hm2m((string)$r['btime']) < $nowM);
            $k = $done ? 'd' : ($r['status'] === 'confirmed' ? 'c' : 'p');
            $sum[$k]++; $dots .= '<i class="' . $k . '"></i>';
        }
        if ($free) $dots .= str_repeat('<i class="f"></i>', min($free, 3));
        $daySum[$ds] = $sum;
?>
      <a class="<?= $cls ?>" href="?t=cal&amp;m=<?= h($m) ?>&amp;d=<?= h($ds) ?>#dzien"><b><?= (int)$d->format('j') ?></b><?php if ($dots): ?><span class="dots"><?= $dots ?></span><?php elseif ($dayOff): ?><span class="tag">wolne</span><?php endif; ?></a>
<?php endfor; ?>
    </div>
    <div class="legend"><span><i class="f"></i>Wolne miejsce (wizyta 2,5 h)</span><span><i class="p"></i>Do potwierdzenia</span><span><i class="c"></i>Potwierdzona</span><span><i class="d"></i>Zrealizowana</span><span><i class="h"></i>Zmienione godziny</span></div>
<?php $sum = $daySum[$sel] ?? null; if ($sum): ?>
    <p class="msum-h">Podsumowanie dnia: <b><?= h(pl_date($sel)) ?></b></p>
    <div class="msum">
      <div><b><?= $sum['f'] ?></b><span>godzin startu (2,5 h)</span></div>
      <div class="p"><b><?= $sum['p'] ?></b><span>do potwierdzenia</span></div>
      <div class="c"><b><?= $sum['c'] ?></b><span>potwierdzonych</span></div>
      <div class="g"><b><?= $sum['p'] + $sum['c'] ?></b><span>do zrealizowania</span></div>
      <div class="d"><b><?= $sum['d'] ?></b><span>zrealizowanych</span></div>
    </div>
<?php else: ?>
    <p class="msum-h">Kliknij dzień w kalendarzu, żeby zobaczyć jego podsumowanie.</p>
<?php endif; ?>
  </section>

<?php if ($sel):
    $w = work_for($sel); $bk = $byDay[$sel] ?? [];
    $back = '?t=cal&m=' . $m . '&d=' . $sel;
    /* oś dnia: wizyty, blokady, wydarzenia z kalendarza i wolne okienka między nimi */
    $items = [];
    foreach ($bk as $r) { $s = hm2m((string)$r['btime']); $items[] = ['s' => $s, 'e' => $s + ((int)$r['dur'] ?: 120), 't' => 'bk', 'r' => $r]; }
    foreach ($blkSlot[$sel] ?? [] as $b) { $s = hm2m((string)$b['btime']); $items[] = ['s' => $s, 'e' => !empty($b['bend']) ? hm2m((string)$b['bend']) : $s + 120, 't' => 'blk', 'r' => $b]; }
    foreach ($g['busy'][$sel] ?? [] as $iv) $items[] = ['s' => $iv[0], 'e' => $iv[1], 't' => 'g'];
    if ($w && !isset($blkDay[$sel]) && !isset($gDay[$sel])) {
        $cur = $w[0]; $sorted = $items; usort($sorted, fn($a, $b) => $a['s'] <=> $b['s']);
        foreach ($sorted as $it) { if ($it['s'] - $cur >= 15 && $cur < $w[1]) $items[] = ['s' => $cur, 'e' => min($it['s'], $w[1]), 't' => 'free']; $cur = max($cur, $it['e']); }
        if ($w[1] - $cur >= 15) $items[] = ['s' => $cur, 'e' => $w[1], 't' => 'free'];
    }
    usort($items, fn($a, $b) => [$a['s'], $a['t'] === 'free'] <=> [$b['s'], $b['t'] === 'free']);
?>
  <h2 class="day" id="dzien"><?= h(pl_date($sel)) ?></h2>
  <?php if (isset($blkDay[$sel])): ?><p class="note">⛔ Dzień zablokowany w panelu<?= $blkDay[$sel]['note'] ? ' (' . h($blkDay[$sel]['note']) . ')' : '' ?>. Usuniesz blokadę w zakładce „Wolne dni”.</p><?php endif; ?>
  <?php if (isset($gDay[$sel])): ?><p class="note">📅 W kalendarzu iPhone/Google jest wydarzenie całodniowe – na stronie ten dzień jest niedostępny.</p><?php endif; ?>
  <?php $custom = array_key_exists($sel, $dwm); $std = work_week()[(int)(new DateTime($sel))->format('w')] ?? null; if ($sel >= $todayS): ?>
  <details class="card hedit" <?= $custom ? 'open' : '' ?>>
    <summary><b>🕘 Godziny w tym dniu</b> <span class="hint" style="margin:0"><?= $w ? h(m2hm($w[0]) . '–' . m2hm($w[1])) . ($custom ? ' (zmienione)' : '') : 'nieczynne' ?></span><span class="hed">Edytuj</span></summary>
    <p class="hint">Godziny otwarcia tylko na ten dzień (standardowo: <?= $std ? h($std[0] . '–' . $std[1]) : 'nieczynne' ?>). Kalendarz na stronie dopasuje wolne godziny do długości wizyt.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="day_work"><input type="hidden" name="date" value="<?= h($sel) ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
      <div class="grid"><label>Otwarcie<input type="time" name="o" step="900" value="<?= h($w ? m2hm($w[0]) : ($std[0] ?? '09:00')) ?>"></label><label>Zamknięcie<input type="time" name="c" step="900" value="<?= h($w ? m2hm($w[1]) : ($std[1] ?? '18:00')) ?>"></label></div>
      <div class="acts" style="margin-top:12px"><button class="btn sm" name="mode" value="save">Zapisz godziny</button>
      <?php if ($custom): ?><button class="btn sm ghost" name="mode" value="reset">Przywróć standardowe</button><?php endif; ?>
      <button class="btn sm danger" name="mode" value="close">Zamknij ten dzień</button></div>
    </form>
  </details>
  <?php endif; ?>
  <?php if (!$items): ?><p class="empty">W ten dzień salon jest nieczynny.</p><?php endif; ?>
  <?php foreach ($items as $it): $tr = m2hm($it['s']) . '–' . m2hm($it['e']);
      if ($it['t'] === 'bk'): $r = $it['r'];
        $tel9 = substr(preg_replace('/\D/', '', (string)$r['phone']), -9);
        $sms = 'Dzień dobry, potwierdzam wizytę ' . pl_date($r['bdate']) . ' o godz. ' . $r['btime'] . '. Do zobaczenia! KamaGrooming'; ?>
    <article class="bk <?= h($r['status']) ?>">
      <div class="row1"><span class="time"><?= h($tr) ?></span><?= dur_pen($r) ?><span class="pill"><?= $r['status'] === 'confirmed' ? 'Potwierdzona' : 'Do potwierdzenia' ?></span><?= $r['source'] === 'panel' ? '<span class="src">dodana ręcznie</span>' : '' ?><?= chg_badge($r) ?><?= client_badge($r) ?></div>
      <?= late_note($r) ?>
      <?= bk_info($r) ?>
      <?php if ($sel >= $todayS): ?><div class="acts">
        <?php if ($r['status'] === 'pending'): ?>
          <form method="post" class="cform"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="confirm"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><?php if ($tel9): ?><a class="btn sm csms" href="sms:+48<?= h($tel9) ?>?&amp;body=<?= rawurlencode($sms) ?>">✓ Potwierdź (SMS)</a><?php else: ?><button class="btn sm">Potwierdź</button><?php endif; ?></form>
        <?php endif; ?>
        <?= resched_form($r, $back) ?>
        <details class="confirm"><summary class="btn sm danger">Odwołaj</summary>
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><?= cancel_buttons($r) ?></form>
        </details>
      </div><?php endif; ?>
    </article>
  <?php elseif ($it['t'] === 'blk'): $b = $it['r']; ?>
    <div class="slotrow busy"><span class="time"><?= h($tr) ?></span><span>⛔ zablokowane w panelu<?= $b['note'] ? ' · ' . h($b['note']) : '' ?></span>
      <form method="post" style="margin-left:auto"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="unblock"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="link">odblokuj</button></form></div>
  <?php elseif ($it['t'] === 'g'): ?>
    <div class="slotrow busy"><span class="time"><?= h($tr) ?></span><span>📅 zajęte w kalendarzu iPhone/Google</span></div>
  <?php elseif ($sel >= $todayS): ?>
    <div class="slotrow free"><span class="time"><?= h($tr) ?></span><span>wolne · <?= h(dur_txt($it['e'] - $it['s'])) ?></span>
      <span class="sacts"><a class="btn sm ghost" href="?t=add&amp;date=<?= h($sel) ?>&amp;time=<?= h(m2hm($it['s'])) ?>">+ Dodaj wizytę</a>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="block"><input type="hidden" name="date" value="<?= h($sel) ?>"><input type="hidden" name="time" value="<?= h(m2hm($it['s'])) ?>"><input type="hidden" name="time_to" value="<?= h(m2hm($it['e'])) ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="btn sm ghost">Zablokuj</button></form></span>
    </div>
  <?php endif; endforeach; ?>
  <?php if ($sel >= $todayS && $w && !isset($blkDay[$sel])): ?>
    <form method="post" class="dayblock"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="block"><input type="hidden" name="date" value="<?= h($sel) ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="link">Zablokuj cały ten dzień</button></form>
  <?php endif; ?>
<?php else: ?>
  <p class="empty">Kliknij dzień w kalendarzu, żeby zobaczyć wizyty i wolne godziny.</p>
<?php endif; ?>

<?php elseif ($tab === 'hours'): $w = work_week(); $gw = grid_week(); $dn = [1 => 'Poniedziałek', 2 => 'Wtorek', 3 => 'Środa', 4 => 'Czwartek', 5 => 'Piątek', 6 => 'Sobota', 0 => 'Niedziela']; $br = big_rules(); ?>
  <section class="card">
    <h2>Godziny otwarcia</h2>
    <p class="hint">Od której do której przyjmujesz i o których godzinach zaczynają się wizyty (np. 9, 11, 13, 15). Gdy wizyta trwa dłużej – np. shih tzu 9:00–11:30 – kolejna godzina sama przesuwa się na 11:30. Kto pierwszy, ten lepszy: godziny, na które nie zmieści się już pies danej rasy, znikają (czas z zakładki „Rasy i ceny”). Godziny na pojedynczy dzień zmienisz w Kalendarzu (kliknij dzień → „Godziny w tym dniu”).</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="week_work"><input type="hidden" name="back" value="?t=hours">
      <?php foreach ($dn as $d => $label): $v = $w[$d] ?? null; ?>
        <div class="wrow2"><label class="sw"><input type="checkbox" name="on<?= $d ?>" value="1" <?= $v ? 'checked' : '' ?>><span><?= $label ?></span></label>
          <input type="time" name="o<?= $d ?>" step="900" value="<?= h($v[0] ?? '09:00') ?>" aria-label="<?= $label ?> otwarcie"><span>–</span><input type="time" name="c<?= $d ?>" step="900" value="<?= h($v[1] ?? '18:00') ?>" aria-label="<?= $label ?> zamknięcie">
          <label class="gridl">Podstawowe godziny wizyt<input name="g<?= $d ?>" value="<?= h(implode(', ', $gw[$d] ?? [])) ?>" placeholder="np. 9:00, 11:00, 13:00, 15:00"></label></div>
      <?php endforeach; ?>
      <label style="margin-top:14px">Poślizg dla ostatniej wizyty (małe i średnie psy), w minutach<small class="linkhint">Wizyta może skończyć się tyle minut po zamknięciu. Duży pies od <?= h($br['from']) ?> może skończyć dowolnie później – dostaniesz wtedy ostrzeżenie przy rezerwacji.</small><input type="number" name="overrun" min="0" max="120" step="5" value="<?= overrun_min() ?>"></label>
      <button class="btn" style="width:100%;margin-top:14px">Zapisz godziny otwarcia</button>
    </form>
    <p class="hint" style="margin-top:12px">Zasady dla dużych psów (od której godziny, ile dziennie) zmienisz w zakładce <a href="?t=settings">Ustawienia</a>.</p>
  </section>

<?php elseif ($tab === 'reviews'): $list = array_values(content_get()['reviews']); ?>
  <section class="card">
    <h2>Dodaj opinię</h2>
    <p class="hint">Przepisz prawdziwą opinię klienta, np. z Google, Facebooka, Booksy albo wiadomości (za jego zgodą). Sekcja „Opinie” pokaże się na stronie po dodaniu pierwszej opinii.</p>
    <form method="post" class="grid"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="rev_add"><input type="hidden" name="back" value="?t=reviews">
      <label>Imię klienta<input name="rname" maxlength="60" placeholder="np. Kasia" required></label>
      <label>Skąd opinia <small>(opcjonalnie)</small><select name="source"><option value="">—</option><option>Google</option><option>Facebook</option><option>Instagram</option><option>Booksy</option></select></label>
      <label>Ocena<select name="stars"><option value="5">★★★★★ (5)</option><option value="4">★★★★☆ (4)</option><option value="3">★★★☆☆ (3)</option><option value="2">★★☆☆☆ (2)</option><option value="1">★☆☆☆☆ (1)</option></select></label>
      <label class="full">Treść opinii<textarea name="text" rows="3" maxlength="600" required></textarea></label>
      <label class="full">Link do opinii <small>(po kliknięciu w opinię na stronie otworzy się oryginał - skopiuj krótki link z aplikacji fb telefonu, długi link z przeglądarki nie zadziała - odsyła do strony głównej)</small><input name="link" type="url" maxlength="500" placeholder="https://www.facebook.com/share/p/…"></label>
      <div class="full avrow"><span class="avprev" id="avPrev">?</span><label class="filebtn btn sm ghost">📷 Zdjęcie klienta lub pupila (opcjonalnie)<input type="file" accept="image/*" hidden class="avfile" data-target="avData" data-prev="avPrev"></label><input type="hidden" name="data" id="avData"></div>
      <p class="hint full" style="margin:-4px 0 0">Zdjęcie profilowe wgrywaj tylko za zgodą klienta. Panel sam przytnie je do kółka.</p>
      <button class="btn full">Dodaj opinię</button>
    </form>
  </section>
  <h2 class="day">Opinie na stronie (<?= count($list) ?>)</h2>
  <?php if (!$list): ?><p class="empty">Brak opinii – sekcja „Opinie” jest ukryta na stronie.</p><?php endif; ?>
  <?php foreach ($list as $i => $r): ?>
    <article class="bk confirmed">
      <div class="row1"><?php if (!empty($r['img'])): ?><img class="avimg" src="../<?= h($r['img']) ?>" alt=""><?php endif; ?><span class="time" style="font-size:1rem"><?= str_repeat('★', (int)$r['stars']) . str_repeat('☆', 5 - (int)$r['stars']) ?></span><span class="src"><?= h($r['name']) ?><?= $r['source'] ? ' · ' . h($r['source']) : '' ?></span></div>
      <div class="svc" style="color:var(--cream)"><?= nl2br(h($r['text'])) ?></div>
      <?php if (!empty($r['link'])): ?><div class="contact"><a href="<?= h($r['link']) ?>" target="_blank" rel="noopener noreferrer">🔗 Otwórz oryginalną opinię</a></div><?php endif; ?>
      <div class="acts">
        <?php foreach (['up' => '↑', 'dn' => '↓'] as $dir => $lab): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="rev_move"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="dir" value="<?= $dir ?>"><input type="hidden" name="back" value="?t=reviews"><button class="btn sm ghost" <?= ($dir === 'up' && $i === 0) || ($dir === 'dn' && $i === count($list) - 1) ? 'disabled' : '' ?>><?= $lab ?></button></form>
        <?php endforeach; ?>
        <details class="redit"><summary class="btn sm ghost">✏️ Edytuj</summary>
          <form method="post" class="grid"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="rev_edit"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=reviews">
            <label>Imię klienta<input name="rname" maxlength="60" value="<?= h($r['name']) ?>" required></label>
            <label>Skąd opinia<select name="source"><?php foreach (['', 'Google', 'Facebook', 'Instagram', 'Booksy'] as $sv): ?><option value="<?= h($sv) ?>" <?= ($r['source'] ?? '') === $sv ? 'selected' : '' ?>><?= $sv === '' ? '—' : h($sv) ?></option><?php endforeach; ?></select></label>
            <label>Ocena<select name="stars"><?php for ($sn = 5; $sn >= 1; $sn--): ?><option value="<?= $sn ?>" <?= (int)$r['stars'] === $sn ? 'selected' : '' ?>><?= str_repeat('★', $sn) . str_repeat('☆', 5 - $sn) ?> (<?= $sn ?>)</option><?php endfor; ?></select></label>
            <label class="full">Treść opinii<textarea name="text" rows="3" maxlength="600" required><?= h($r['text']) ?></textarea></label>
            <label class="full">Link do opinii <small>(po kliknięciu w opinię na stronie otworzy się oryginał - skopiuj krótki link z aplikacji fb telefonu, długi link z przeglądarki nie zadziała - odsyła do strony głównej)</small><input name="link" type="url" maxlength="500" value="<?= h($r['link'] ?? '') ?>" placeholder="https://www.facebook.com/share/p/…"></label>
            <button class="btn full">Zapisz zmiany</button>
          </form>
        </details>
        <form method="post" class="avform"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="rev_photo"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=reviews"><input type="hidden" name="data" id="avd<?= $i ?>"><label class="filebtn btn sm ghost">📷 <?= empty($r['img']) ? 'Dodaj zdjęcie' : 'Zmień zdjęcie' ?><input type="file" accept="image/*" hidden class="avfile" data-target="avd<?= $i ?>" data-submit="1"></label></form>
        <?php if (!empty($r['img'])): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="rev_nophoto"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=reviews"><button class="btn sm ghost">Usuń zdjęcie</button></form><?php endif; ?>
        <details class="confirm"><summary class="btn sm danger">Usuń</summary><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="rev_del"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=reviews"><button class="btn sm danger">Tak, usuń</button></form></details>
      </div>
    </article>
  <?php endforeach; ?>
  <script>
  document.addEventListener('change',e=>{const f=e.target.closest('.avfile');if(!f||!f.files[0])return;
    const u=URL.createObjectURL(f.files[0]),im=new Image();
    im.onload=()=>{const S=320,c=document.createElement('canvas');c.width=c.height=S;const x=c.getContext('2d');x.fillStyle='#fff';x.fillRect(0,0,S,S);
      const m=Math.min(im.width,im.height);x.drawImage(im,(im.width-m)/2,(im.height-m)/2,m,m,0,0,S,S);URL.revokeObjectURL(u);
      const d=c.toDataURL('image/jpeg',0.88);document.getElementById(f.dataset.target).value=d;
      if(f.dataset.prev){const p=document.getElementById(f.dataset.prev);p.textContent='';p.style.backgroundImage='url('+d+')'}
      if(f.dataset.submit)f.closest('form').submit();};
    im.src=u;});
  </script>

<?php elseif ($tab === 'prices'): breeds_view(); ?>
<?php elseif ($tab === 'clients'): clients_view(); ?>

<?php elseif ($tab === 'gallery'): $c = content_get(); $list = array_values(array_filter($c['gallery'], 'is_array')); ?>
  <section class="card">
    <h2>Dodaj realizację (przed i po)</h2>
    <p class="hint">Wpisz imię psa i dodaj dwa zdjęcia. Zdjęcie <b>PO</b> widać na stronie od razu. Zdjęcie <b>PRZED</b> pokazuje się po najechaniu myszką (na telefonie po przytrzymaniu palca).</p>
    <form method="post" id="upf" class="grid">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="gal_add"><input type="hidden" name="back" value="?t=gallery">
      <input type="hidden" name="data_after" id="dA"><input type="hidden" name="data_before" id="dB">
      <label class="full">Imię psa<input name="dog" maxlength="40" placeholder="np. Bobik"></label>
      <div class="pick"><span class="pl">PO (na stronie)</span><label class="filebtn btn sm ghost">📷 Wybierz<input type="file" accept="image/*" hidden data-to="dA" data-prev="pA"></label><img id="pA" alt="" hidden></div>
      <div class="pick"><span class="pl">PRZED (po najechaniu)</span><label class="filebtn btn sm ghost">📷 Wybierz<input type="file" accept="image/*" hidden data-to="dB" data-prev="pB"></label><img id="pB" alt="" hidden></div>
      <button class="btn full" id="upsave">Zapisz na stronie</button>
    </form>
  </section>
  <h2 class="day">Realizacje na stronie (<?= count($list) ?>)</h2>
  <?php if (!$list): ?><p class="empty">Galeria jest pusta.</p><?php endif; ?>
  <div class="glist pairs">
  <?php foreach ($list as $i => $it): ?>
    <div class="gitem">
      <div class="duo"><figure><img src="../<?= h($it['after']) ?>" alt="" loading="lazy"><figcaption>po</figcaption></figure><figure><img src="../<?= h($it['before']) ?>" alt="" loading="lazy"><figcaption>przed</figcaption></figure></div>
      <form method="post" class="ctitle"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="gal_name"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=gallery"><input name="dog" value="<?= h($it['name'] ?? '') ?>" maxlength="40" placeholder="Imię psa"><button class="btn sm ghost">Zapisz</button></form>
      <div class="gacts">
        <?php foreach (['up' => '↑', 'dn' => '↓'] as $dir => $lab): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="gal_move"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="dir" value="<?= $dir ?>"><input type="hidden" name="back" value="?t=gallery"><button class="btn sm ghost" <?= ($dir === 'up' && $i === 0) || ($dir === 'dn' && $i === count($list) - 1) ? 'disabled' : '' ?>><?= $lab ?></button></form>
        <?php endforeach; ?>
        <details class="confirm"><summary class="btn sm danger">Usuń</summary>
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="gal_del"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=gallery"><button class="btn sm danger">Tak, usuń</button></form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <script>
  (function(){
    /* zdjęcie z telefonu → JPEG max 1200 px (zachowane proporcje) */
    document.querySelectorAll('#upf input[type=file]').forEach(f=>f.addEventListener('change',()=>{const file=f.files[0];if(!file)return;
      const u=URL.createObjectURL(file),im=new Image();im.onload=()=>{const k=Math.min(1,1200/Math.max(im.width,im.height));const c=document.createElement('canvas');
        c.width=Math.round(im.width*k);c.height=Math.round(im.height*k);c.getContext('2d').drawImage(im,0,0,c.width,c.height);URL.revokeObjectURL(u);
        const d=c.toDataURL('image/jpeg',0.84);document.getElementById(f.dataset.to).value=d;const p=document.getElementById(f.dataset.prev);p.src=d;p.hidden=false};im.src=u}));
    document.getElementById('upf').addEventListener('submit',e=>{if(!document.getElementById('dA').value||!document.getElementById('dB').value){e.preventDefault();alert('Dodaj oba zdjęcia: PO i PRZED.');return}
      const b=document.getElementById('upsave');b.textContent='Zapisywanie…';setTimeout(()=>b.disabled=true,0)});
  })();
  </script>

<?php elseif ($tab === 'certs'): $isG = false; $c = content_get(); $list = array_values($isG ? $c['gallery'] : $c['certs']); ?>
  <section class="card">
    <h2><?= $isG ? 'Dodaj zdjęcie do realizacji' : 'Dodaj certyfikat' ?></h2>
    <p class="hint"><?= $isG ? 'Wybierz zdjęcie z telefonu, przesuń je palcem i powiększ suwakiem tak, żeby oczy były w złotej ramce. Na stronie zdjęcie pokaże się jako pasek z oczami.' : 'Zrób zdjęcie certyfikatu albo wybierz skan. Dopisz nazwę szkolenia, np. „Strzyżenie psów ras małych – Akademia XYZ, 2026”.' ?></p>
    <form method="post" id="upf">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="<?= $isG ? 'gal_add' : 'cert_add' ?>"><input type="hidden" name="back" value="?t=<?= $tab ?>"><input type="hidden" name="data" id="updata">
      <label class="filebtn btn ghost">📷 Wybierz zdjęcie<input type="file" id="upfile" accept="image/*" hidden></label>
      <div id="crop" hidden>
        <canvas id="cv" class="<?= $isG ? 'strip' : 'cert' ?>"></canvas>
        <?php if ($isG): ?><label>Powiększenie<input type="range" id="zoom" min="1" max="4" step="0.01" value="1"></label><?php endif; ?>
        <?php if (!$isG): ?><label>Nazwa certyfikatu<input name="title" maxlength="120" placeholder="np. Kurs groomingu – Akademia XYZ, 2026"></label><?php endif; ?>
        <button class="btn" id="upsave" style="margin-top:12px;width:100%">Zapisz na stronie</button>
      </div>
    </form>
  </section>
  <h2 class="day"><?= $isG ? 'Zdjęcia w galerii (' . count($list) . ')' : 'Certyfikaty na stronie (' . count($list) . ')' ?></h2>
  <?php if (!$list): ?><p class="empty"><?= $isG ? 'Galeria jest pusta.' : 'Nie dodano jeszcze certyfikatów – sekcja na stronie jest ukryta, dopóki nie dodasz pierwszego.' ?></p><?php endif; ?>
  <div class="glist <?= $isG ? '' : 'certs' ?>">
  <?php foreach ($list as $i => $it): $src = '../' . ($isG ? $it : $it['img']); ?>
    <div class="gitem">
      <img src="<?= h($src) ?>" alt="" loading="lazy">
      <?php if (!$isG): ?>
        <form method="post" class="ctitle"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="cert_title"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=certs"><input name="title" value="<?= h($it['title']) ?>" maxlength="120" placeholder="Nazwa certyfikatu"><button class="btn sm ghost">Zapisz</button></form>
      <?php endif; ?>
      <div class="gacts">
        <?php foreach (['up' => '↑', 'dn' => '↓'] as $dir => $lab): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="<?= $isG ? 'gal_move' : 'cert_move' ?>"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="dir" value="<?= $dir ?>"><input type="hidden" name="back" value="?t=<?= $tab ?>"><button class="btn sm ghost" <?= ($dir === 'up' && $i === 0) || ($dir === 'dn' && $i === count($list) - 1) ? 'disabled' : '' ?>><?= $lab ?></button></form>
        <?php endforeach; ?>
        <details class="confirm"><summary class="btn sm danger">Usuń</summary>
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="<?= $isG ? 'gal_del' : 'cert_del' ?>"><input type="hidden" name="idx" value="<?= $i ?>"><input type="hidden" name="back" value="?t=<?= $tab ?>"><button class="btn sm danger">Tak, usuń</button></form>
        </details>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <script>
  (function(){
    const strip=<?= $isG ? 'true' : 'false' ?>;
    const file=document.getElementById('upfile'),cv=document.getElementById('cv'),ctx=cv.getContext('2d'),crop=document.getElementById('crop'),zoom=document.getElementById('zoom');
    let img=null,s=1,base=1,ox=0,oy=0;
    const OW=strip?1000:0,OH=strip?400:0;
    function draw(){ if(!img)return;
      if(strip){cv.width=OW;cv.height=OH;ctx.fillStyle='#22113a';ctx.fillRect(0,0,OW,OH);const k=base*s;const w=img.width*k,h=img.height*k;
        ox=Math.min(0,Math.max(OW-w,ox));oy=Math.min(0,Math.max(OH-h,oy));ctx.drawImage(img,ox,oy,w,h);}
      else{const m=1600/Math.max(img.width,img.height);const k=Math.min(1,m);cv.width=Math.round(img.width*k);cv.height=Math.round(img.height*k);ctx.drawImage(img,0,0,cv.width,cv.height);} }
    file.onchange=()=>{const f=file.files[0];if(!f)return;const u=URL.createObjectURL(f);const im=new Image();im.onload=()=>{img=im;
      if(strip){base=Math.max(OW/im.width,OH/im.height);s=1;if(zoom)zoom.value=1;ox=(OW-im.width*base)/2;oy=(OH-im.height*base)/2;}
      crop.hidden=false;draw();URL.revokeObjectURL(u)};im.src=u};
    if(strip){
      zoom.oninput=()=>{const cx=OW/2,cy=OH/2,old=base*s;s=+zoom.value;const nw=base*s;ox=cx-(cx-ox)*nw/old;oy=cy-(cy-oy)*nw/old;draw()};
      let drag=null;
      cv.addEventListener('pointerdown',e=>{drag={x:e.clientX,y:e.clientY,ox,oy};cv.setPointerCapture(e.pointerId)});
      cv.addEventListener('pointermove',e=>{if(!drag)return;const r=OW/cv.getBoundingClientRect().width;ox=drag.ox+(e.clientX-drag.x)*r;oy=drag.oy+(e.clientY-drag.y)*r;draw()});
      ['pointerup','pointercancel'].forEach(t=>cv.addEventListener(t,()=>drag=null));
    }
    document.getElementById('upf').addEventListener('submit',e=>{if(!img){e.preventDefault();return}
      document.getElementById('updata').value=cv.toDataURL('image/jpeg',0.86);
      const b=document.getElementById('upsave');b.textContent='Zapisywanie…';setTimeout(()=>b.disabled=true,0);});
  })();
  </script>

<?php elseif ($tab === 'next' || $tab === 'past'):
    $past = $tab === 'past';
    $st = $past
        ? db()->prepare("SELECT * FROM bookings WHERE bdate < ? ORDER BY bdate DESC, btime DESC LIMIT 200")
        : db()->prepare("SELECT * FROM bookings WHERE bdate >= ? ORDER BY bdate, btime");
    $st->execute([$todayS]);
    $rows = $st->fetchAll();
    if (!$rows) echo '<p class="empty">' . ($past ? 'Brak wcześniejszych wizyt.' : 'Brak zaplanowanych wizyt. Nowe rezerwacje ze strony pojawią się tutaj.') . '</p>';
    $cur = null;
    foreach ($rows as $r):
        if ($r['bdate'] !== $cur) { $cur = $r['bdate']; echo '<h2 class="day">' . h(pl_date($cur)) . '</h2>'; }
        $tel = $r['phone']; $tel9 = substr(preg_replace('/\D/', '', $tel), -9);
        $sms = 'Dzień dobry, potwierdzam wizytę ' . pl_date($r['bdate']) . ' o godz. ' . $r['btime'] . '. Do zobaczenia! KamaGrooming';
        $labels = ['pending' => 'Do potwierdzenia', 'confirmed' => 'Potwierdzona', 'cancelled' => 'Odwołana'];
?>
  <article class="bk <?= h($r['status']) ?>">
    <div class="row1"><span class="time"><?= h($r['btime'] . '–' . m2hm(hm2m((string)$r['btime']) + ((int)($r['dur'] ?? 0) ?: 120))) ?></span><?= dur_pen($r) ?><span class="pill"><?= h($r['status'] === 'cancelled' && ($r['chg'] ?? '') === 'cancel' ? 'Odwołana przez klienta' : ($labels[$r['status']] ?? $r['status'])) ?></span><?= $r['source'] === 'panel' ? '<span class="src">dodana ręcznie</span>' : '' ?><?= chg_badge($r) ?><?= client_badge($r) ?></div>
    <?= late_note($r) ?>
    <?= bk_info($r) ?>
    <?php if (!$past): ?>
    <div class="acts">
      <?php if ($r['status'] === 'pending'): ?>
        <form method="post" class="cform"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="confirm"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="?t=next"><?php if ($tel9): ?><a class="btn sm csms" href="sms:+48<?= h($tel9) ?>?&amp;body=<?= rawurlencode($sms) ?>">✓ Potwierdź (SMS)</a><?php else: ?><button class="btn sm">Potwierdź</button><?php endif; ?></form>
      <?php endif; ?>
      <?php if ($r['status'] !== 'cancelled'): ?>
        <?= resched_form($r, '?t=next') ?>
        <details class="confirm"><summary class="btn sm danger">Odwołaj</summary>
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="?t=next"><?= cancel_buttons($r) ?></form>
        </details>
      <?php else: ?>
        <?php if (!$past): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="?t=next"><button class="btn sm">↺ Przywróć wizytę</button></form><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="?t=next"><button class="btn sm ghost">Usuń z listy</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>

<?php elseif ($tab === 'add'): $bc = breeds_get(); ?>
  <section class="card">
    <h2>Dodaj wizytę umówioną telefonicznie</h2>
    <p class="hint">Czas wizyty liczy się sam z rasy i usługi (możesz go zmienić). Ten czas zniknie z kalendarza na stronie, żeby nikt go nie zajął.</p>
    <?= voice_box() ?>
    <form method="post" class="grid" id="addf">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="add"><input type="hidden" name="back" value="<?= valid_date((string)($_GET['date'] ?? '')) ? h('?t=cal&m=' . substr($_GET['date'], 0, 7) . '&d=' . $_GET['date']) : '?t=next' ?>">
      <label>Data<input type="date" name="date" min="<?= h($todayS) ?>" value="<?= h(valid_date((string)($_GET['date'] ?? '')) ? $_GET['date'] : '') ?>" required></label>
      <label>Godzina rozpoczęcia<input type="time" name="time" step="300" value="<?= h(preg_match('/^\d{2}:\d{2}$/', (string)($_GET['time'] ?? '')) ? $_GET['time'] : '') ?>" required></label>
      <label>Imię i nazwisko<input name="name" maxlength="120" required></label>
      <label>Telefon<input name="phone" type="tel" maxlength="20"><small id="cliHint" class="chint"></small></label>
      <label>Rasa psa<input name="breed" list="brl" maxlength="80" placeholder="zacznij pisać…" required><datalist id="brl"><?php foreach ($bc['breeds'] as $b) echo '<option value="' . h($b['n']) . '">'; ?></datalist></label>
      <label id="coatL" hidden>Długość sierści<select name="coat"></select></label>
      <label>Wielkość <small>(gdy rasy nie ma na liście)</small><select name="size"><option value="small">Mały</option><option value="medium">Średni</option><option value="big">Duży</option></select></label>
      <label>Usługa<select name="svc"><?php foreach ($bc['services'] as $sv) echo '<option value="' . h($sv['k']) . '">' . h($sv['n']) . '</option>'; ?></select></label>
      <?php if (!empty($bc['addon'])): ?><label class="sw"><input type="checkbox" name="addon" value="1"><span>+ <?= h($bc['addon']['n']) ?></span></label><?php endif; ?>
      <label>Pies <small>(imię, waga – opcjonalnie)</small><input name="pet" maxlength="100" placeholder="np. Fafik, 6 kg"></label>
      <label>Czas wizyty <small id="durHint"></small><input name="dur" placeholder="np. 2:30"></label>
      <button class="btn full">Dodaj wizytę</button>
    </form>
  </section>
  <script>
  (function(){const B=window.__ADD_B=<?= json_encode($bc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;const f=document.getElementById('addf');
    const nr=s=>String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/ł/g,'l').replace(/[^a-z0-9]+/g,' ').trim();
    const t=m=>Math.floor(m/60)+':'+String(m%60).padStart(2,'0');
    function calc(){const b=B.breeds.find(x=>nr(x.n)===nr(f.breed.value));const size=b?b.s:f.size.value;if(b)f.size.value=b.s;
      const sv=B.services.find(s=>s.k===f.svc.value)||B.services[0];const sz=B.sizes[size]||{p:0,m:120};let p,m;
      const cl=document.getElementById('coatL'),hv=!!(b&&b.v&&b.v.length);cl.hidden=!hv;
      if(hv&&f.coat.dataset.for!==b.n){f.coat.innerHTML=b.v.map((v,i)=>'<option value="'+i+'">'+v.n.replace(/</g,'&lt;')+' – '+v.p+' zł, '+t(+v.m)+'</option>').join('');f.coat.dataset.for=b.n}
      if(!hv){f.coat.innerHTML='<option value="-1"></option>';f.coat.dataset.for=''}
      if(sv.type==='breed'){p=b?b.p:sz.p;m=b?b.m:sz.m;if(hv){const v=b.v[+f.coat.value]||b.v[0];p=v.p;m=v.m}}else if(sv.type==='size'){p=sv.p[size];m=sv.m[size]}else{p=sv.p;m=sv.m}
      if(f.addon&&f.addon.checked&&B.addon&&(B.addon.for||[]).includes(sv.k)){p+=+B.addon.p;m+=+B.addon.m}
      f.dur.value=t(m);document.getElementById('durHint').textContent='(wg cennika: '+t(m)+' · '+p+' zł)'}
    ['breed','size','svc','addon','coat'].forEach(n=>f[n]&&f[n].addEventListener('change',calc));
    const C=<?php $cm = []; foreach (client_index() as $k => $c) { $dd = []; foreach ($c['b'] as $b) if ($b['status'] !== 'cancelled' && $b['bdate'] < $todayS) $dd[$b['bdate']] = 1; $n = count($dd) + (int)($c['c']['prev'] ?? 0); $cm[$k] = ['n' => $c['name'], 'p' => (string)(reset($c['pets']) ?: ''), 'v' => $n, 'r' => ($n > 0 || $c['c']) ? 1 : 0, 'o' => mb_strimwidth((string)($c['c']['note'] ?? ''), 0, 120, '…')]; } echo json_encode($cm ?: new stdClass, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    f.phone.addEventListener('input',()=>{const k=f.phone.value.replace(/\D/g,'').slice(-9),h=document.getElementById('cliHint'),c=k.length===9?C[k]:null;
      if(!c){h.innerHTML=k.length===9?'<span class="cbadge new">Nowy klient</span>':'';return}
      h.innerHTML=(c.r?'<span class="cbadge reg">⭐ Stały klient'+(c.v?' · '+(c.v+1)+'. wizyta':'')+'</span> ':'')+(c.n?' '+c.n.replace(/</g,'&lt;'):'')+(c.o?'<br>📝 '+c.o.replace(/</g,'&lt;'):'');
      if(!f.name.value&&c.n)f.name.value=c.n;
      if(!f.breed.value&&c.p){const b=B.breeds.find(x=>c.p.toLowerCase().startsWith(x.n.toLowerCase()));if(b){f.breed.value=b.n;calc()}}});f.breed.addEventListener('input',()=>{if(B.breeds.some(x=>nr(x.n)===nr(f.breed.value)))calc()});
  })();
  </script>

<?php elseif ($tab === 'site'): site_editor_view(); ?>
<?php elseif ($tab === 'settings'): settings_view(); ?>
<?php elseif ($tab === 'blocks'):
    $rows = db()->query("SELECT * FROM blocks WHERE bdate >= '$todayS' ORDER BY bdate, btime")->fetchAll(); ?>
  <?php $gs = gcal_status(); ?>
  <section class="card gcal">
    <h2>Kalendarz Google</h2>
    <?php if ($gs['state'] === 'off'): ?>
      <p class="hint">Nie podłączono. Wklej tajny adres iCal swojego kalendarza w zakładce <a href="?t=settings">Ustawienia</a>. Wtedy każde wydarzenie w kalendarzu zajmie termin na stronie.</p>
    <?php elseif ($gs['state'] === 'ok'): ?>
      <p class="hint">✅ Połączono. Ostatnie pobranie: <?= h(date('d.m H:i', $gs['at'])) ?>. Zmiany w kalendarzu widać na stronie po maks. 5 minutach. Wydarzenie „cały dzień” blokuje cały dzień, a zwykłe wydarzenie – godziny, na które nachodzi.</p>
    <?php elseif ($gs['state'] === 'error'): ?>
      <p class="err">Nie udało się pobrać kalendarza (<?= h(date('d.m H:i', $gs['at'])) ?>). Sprawdź tajny adres iCal w zakładce <a href="?t=settings">Ustawienia</a>. Do czasu naprawy strona używa ostatniej pobranej wersji.</p>
    <?php else: ?>
      <p class="hint">Adres podłączony. Status pojawi się po pierwszym otwarciu kalendarza na stronie.</p>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Zablokuj termin</h2>
    <p class="hint">Urlop, szkolenie albo wolne popołudnie. Zostaw pole godziny puste, żeby zablokować cały dzień.</p>
    <form method="post" class="grid">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="block"><input type="hidden" name="back" value="?t=blocks">
      <label>Od dnia<input type="date" name="date" min="<?= h($todayS) ?>" required></label>
      <label>Do dnia <small>(opcjonalnie)</small><input type="date" name="date_to" min="<?= h($todayS) ?>"></label>
      <label>Tylko godzina <small>(opcjonalnie)</small><input type="time" name="time" step="1800"></label>
      <label>Notatka <small>(opcjonalnie)</small><input name="note" maxlength="160" placeholder="np. urlop"></label>
      <button class="btn full">Zablokuj</button>
    </form>
  </section>
  <h2 class="day">Zablokowane terminy</h2>
  <?php if (!$rows) echo '<p class="empty">Brak blokad.</p>'; foreach ($rows as $b): ?>
    <div class="blk"><span><b><?= h(pl_date($b['bdate'])) ?></b> · <?= $b['btime'] ? h($b['btime']) : 'cały dzień' ?><?= $b['note'] ? ' · ' . h($b['note']) : '' ?></span>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="unblock"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="back" value="?t=blocks"><button class="link">Usuń</button></form></div>
  <?php endforeach; ?>
<?php endif; ?>
</main>
<script>
/* „Zapisz i wyślij SMS” przy zmianie terminu */
document.addEventListener('click',e=>{const a=e.target.closest('a.rsms');if(!a)return;e.preventDefault();
  const f=a.closest('form');if(!f.reportValidity())return;const d=f.date.value,t=f.time.value;
  const M=['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'],[y,m,dd]=d.split('-').map(Number);
  const body='Dzień dobry, proponujemy nowy termin wizyty: '+dd+' '+M[m-1]+' o godz. '+t+'. Jeśli nie pasuje, prosimy o informację. KamaGrooming';
  a.textContent='Zapisuję…';fetch(location.pathname,{method:'POST',body:(fd=>(fd.append('nosms','1'),fd))(new FormData(f)),credentials:'same-origin'}).catch(()=>{}).finally(()=>{location.href=a.getAttribute('href')+'?&body='+encodeURIComponent(body);setTimeout(()=>location.reload(),1500)});});
/* „Potwierdź z SMS”: zapisuje potwierdzenie w panelu i od razu otwiera SMS do klienta */
document.addEventListener('click',e=>{const a=e.target.closest('a.csms');if(!a)return;e.preventDefault();
  const f=a.closest('form');a.textContent='Otwieram SMS…';
  fetch(location.pathname,{method:'POST',body:(fd=>(fd.append('nosms','1'),fd))(new FormData(f)),credentials:'same-origin'}).catch(()=>{}).finally(()=>{location.href=a.getAttribute('href');setTimeout(()=>location.reload(),1500)});});
</script>
<?= clients_assets() ?>
</body></html>
<?php
function time_list(string $name, array $times): string
{
    $h = '<div class="tl" data-name="' . h($name) . '">';
    foreach ($times as $t) $h .= '<span class="tli"><input type="time" name="' . h($name) . '[]" value="' . h($t) . '" step="900"><button type="button" class="tlx" aria-label="Usuń godzinę">✕</button></span>';
    return $h . '<button type="button" class="tla btn sm ghost">+ godzina</button></div>';
}
function time_list_js(): string
{
    return "<script>document.addEventListener('click',e=>{const x=e.target.closest('.tlx');if(x){x.closest('.tli').remove();return}const a=e.target.closest('.tla');if(!a)return;const box=a.closest('.tl');const last=[...box.querySelectorAll('input')].pop();let v='09:00';if(last&&last.value){const[hh,mm]=last.value.split(':').map(Number);const t=Math.min(hh*60+mm+150,23*60+45);v=String(Math.floor(t/60)).padStart(2,'0')+':'+String(t%60).padStart(2,'0')}const s=document.createElement('span');s.className='tli';s.innerHTML='<input type=\"time\" step=\"900\" name=\"'+box.dataset.name+'[]\" value=\"'+v+'\"><button type=\"button\" class=\"tlx\" aria-label=\"Usuń godzinę\">✕</button>';box.insertBefore(s,a);s.querySelector('input').focus()});</script>";
}
function page_head(string $title): void { ?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#1c1510"><title><?= h($title) ?> · Panel KamaGrooming</title>
<link rel="stylesheet" href="../fonts/fonts.css"><link rel="icon" href="../ikony/favicon-32.png"><link rel="apple-touch-icon" href="../ikony/apple-touch-icon.png">
<style>
:root{--ink:#1c1510;--plum:#2a2018;--plum2:#352a20;--gold:#cfa75e;--hi:#f3dca6;--cream:#f5ede0;--muted:#b9a993;--line:rgba(207,167,94,.28);--ok:#7fc79a;--warn:#e9b44c;--bad:#f0a3b8}
*{box-sizing:border-box}body{margin:0;background:var(--ink);color:var(--cream);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:env(safe-area-inset-top) 0 env(safe-area-inset-bottom)}
a{color:var(--hi)}.brand{display:inline-block;font-family:"EB Garamond",Georgia,serif;font-weight:500;font-size:1.7rem;line-height:1.1;padding:.12em .45em .18em;margin:-.12em -.45em -.18em;background:linear-gradient(100deg,#9a7232,#f3dca6 45%,#cfa75e 70%,#9a7232);-webkit-background-clip:text;background-clip:text;color:transparent}.login .brand{font-size:2.1rem}
.top{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)}
.tabs{display:flex;gap:6px;overflow-x:auto;padding:10px 16px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--ink);z-index:2}
.tabs a{white-space:nowrap;text-decoration:none;color:var(--muted);padding:8px 14px;border-radius:999px;border:1px solid transparent;font-size:.92rem}
.tabs a.on{color:var(--ink);background:var(--hi)}.tabs b{display:inline-grid;place-items:center;min-width:20px;height:20px;border-radius:10px;background:var(--bad);color:var(--ink);font-size:.75rem;margin-left:4px}
.wrap{max-width:760px;margin:0 auto;padding:12px 16px 40px}
.day{font-size:1rem;color:var(--gold);margin:22px 0 8px;text-transform:capitalize;letter-spacing:.02em}
.bk{background:var(--plum);border:1px solid var(--line);border-left:4px solid var(--warn);border-radius:14px;padding:14px;margin-bottom:10px;display:grid;gap:6px}
.bk.confirmed{border-left-color:var(--ok)}.bk.cancelled{border-left-color:#5e5040;opacity:.6}
.row1{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.time{font-size:1.35rem;font-weight:600;color:var(--hi);font-variant-numeric:tabular-nums}
.pill{font-size:.75rem;padding:2px 10px;border-radius:999px;background:rgba(233,180,76,.18);color:var(--warn)}.confirmed .pill{background:rgba(127,199,154,.18);color:var(--ok)}.cancelled .pill{background:rgba(255,255,255,.08);color:var(--muted)}
.src{font-size:.75rem;color:var(--muted)}.name{font-size:1.1rem;font-weight:500}.svc{color:var(--muted);font-size:.92rem}.pet{color:var(--hi);font-size:.95rem}
.contact{display:flex;flex-wrap:wrap;gap:14px;font-size:.95rem}.contact a{text-decoration:none}
.acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;align-items:flex-start}.acts form{margin:0}
.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:12px 20px;background:linear-gradient(120deg,#9a7232,var(--gold),var(--hi));color:var(--ink);font:600 .95rem system-ui,sans-serif;cursor:pointer;text-decoration:none}
.btn.sm{padding:9px 14px;font-size:.85rem}.btn.ghost{background:transparent;color:var(--hi);border:1px solid var(--gold)}.btn.danger{background:transparent;color:var(--bad);border:1px solid rgba(240,163,184,.5)}
details.confirm summary{list-style:none}details.confirm summary::-webkit-details-marker{display:none}details.confirm[open] summary{display:none}
.link{background:none;border:0;color:var(--muted);text-decoration:underline;cursor:pointer;font:inherit}
.card{background:var(--plum);border:1px solid var(--line);border-radius:16px;padding:18px;margin-top:12px}.card h2{margin:0 0 4px;font-size:1.15rem;color:var(--hi)}
.hint{color:var(--muted);font-size:.9rem;margin:0 0 12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.grid .full{grid-column:1/-1}
label{display:grid;gap:6px;font-size:.85rem;color:var(--gold)}label small{color:var(--muted)}
input,select{width:100%;padding:11px 12px;border-radius:10px;border:1px solid #5e4e3c;background:var(--ink);color:#fff;font-size:16px;color-scheme:dark}
.blk{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 14px;border:1px solid var(--line);border-radius:12px;margin-bottom:8px}.blk form{margin:0}
.flash{background:rgba(127,199,154,.15);color:#c8eed5;padding:10px 14px;border-radius:12px}.err{background:rgba(240,163,184,.15);color:#f6c3d1;padding:10px 12px;border-radius:10px}
.empty{color:var(--muted);text-align:center;padding:30px 0}
.login{min-height:100vh;display:grid;place-items:center;padding:16px}.login .card{width:min(380px,100%);text-align:center}.login h1{margin:4px 0 16px;font-size:1.2rem;color:var(--cream)}
.login form{display:grid;gap:10px;text-align:left}.login label{margin-bottom:-4px}

.cal{background:var(--ink);border:1px solid var(--line);border-radius:18px;padding:14px;margin-top:6px}
.cal-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.cal-top h2{margin:0;font-family:Georgia,"Times New Roman",serif;font-weight:500;font-size:1.5rem;color:var(--hi);text-transform:capitalize}
.cal-nav{display:flex;gap:6px}.ib{display:inline-grid;place-items:center;width:38px;height:38px;border-radius:50%;border:1px solid var(--line);color:var(--hi);text-decoration:none;font-size:1.1rem;border-radius:999px}
.dow,.days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:5px}
.dow span{text-align:center;font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);padding-bottom:4px}
.days .day{aspect-ratio:1;border-radius:11px;background:var(--plum);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;text-decoration:none;color:var(--cream);border:1px solid transparent;min-width:0;margin:0;font-size:inherit;text-transform:none;letter-spacing:0}
.days .day b{font-weight:500;font-size:1rem}.days .day.empty{visibility:hidden}
.days .day.off,.days .day.past{background:transparent;color:rgba(185,169,147,.35)}.days .day.past.sel,.days .day.past:hover{color:var(--cream)}
.days .day.blocked{background:rgba(110,95,75,.25);color:var(--muted)}.days .day.full{background:rgba(207,167,94,.12)}
.days .day.today{box-shadow:inset 0 0 0 1px var(--hi)}.days .day.sel{background:linear-gradient(135deg,#9a7232,var(--hi));color:var(--ink)}.days .day.sel .freec,.days .day.sel .tag{color:var(--ink)}
.days .day:hover{border-color:var(--gold)}
.dots{display:flex;gap:3px;flex-wrap:wrap;justify-content:center;max-width:90%}.dots i,.legend i{width:7px;height:7px;border-radius:50%;display:inline-block}
i.p{background:var(--warn)}i.c{background:var(--ok)}i.x{background:#5e5040}i.d{background:#6e604e}i.f{background:transparent;box-shadow:inset 0 0 0 1.5px var(--gold)}
.days .day.sel i.f{box-shadow:inset 0 0 0 1.5px var(--ink)}
.msum-h{margin:16px 0 0;font-size:.82rem;color:var(--muted)}.msum-h b{color:var(--gold);font-weight:500}
.msum{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:8px}
.msum div{background:var(--plum);border-radius:11px;padding:10px 6px;text-align:center;border:1px solid rgba(207,167,94,.15)}
.msum b{display:block;font-size:1.35rem;font-weight:600;color:var(--gold);line-height:1.1}.msum span{font-size:.68rem;color:var(--muted);line-height:1.2;display:block;margin-top:3px}
.pick{display:grid;gap:8px;justify-items:start;border:1px dashed var(--line);border-radius:12px;padding:12px}.pick .pl{font-size:.8rem;letter-spacing:.08em;color:var(--gold)}.pick img{width:100%;max-height:220px;object-fit:contain;border-radius:8px}.pairs .duo{display:grid;grid-template-columns:1fr 1fr;gap:6px}.pairs figure{margin:0;position:relative}.pairs figure img{width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:8px;display:block}.pairs figcaption{position:absolute;left:6px;top:6px;font-size:.7rem;background:rgba(0,0,0,.55);padding:1px 7px;border-radius:99px}
.msum .p b{color:var(--warn)}.msum .c b{color:var(--ok)}.msum .g b{color:var(--cream)}.msum .d b{color:#b0a08a}
@media (max-width:520px){.msum{grid-template-columns:repeat(3,1fr)}.msum .g{grid-column:span 2}}
.freec,.tag{font-size:.62rem;color:var(--muted);line-height:1}
.legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:10px;font-size:.78rem;color:var(--muted)}.legend span{display:inline-flex;align-items:center;gap:6px}
.slotrow{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 14px;border:1px dashed var(--line);border-radius:12px;margin-bottom:8px;color:var(--muted)}
.slotrow .time{font-size:1.1rem;font-weight:600;color:var(--hi);min-width:52px;font-variant-numeric:tabular-nums}
.slotrow.free{border-style:solid}.slotrow.busy{opacity:.75}.sacts{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap}.sacts form{margin:0}
.note{background:rgba(207,167,94,.1);color:var(--hi);padding:10px 14px;border-radius:12px;font-size:.92rem}
.dayblock{margin:6px 0 0;text-align:right}
@media (max-width:420px){.dow,.days{gap:3px}.days .day{border-radius:8px}.days .day b{font-size:.9rem}.freec{font-size:.55rem}}

.ptable{display:grid;gap:8px;margin-bottom:10px}
.phead,.prow{display:grid;grid-template-columns:1.3fr 1.6fr .7fr .7fr auto;gap:6px;align-items:center}
.ex .prow{grid-template-columns:2fr .7fr auto}
.phead span{font-size:.72rem;color:var(--gold);text-transform:uppercase;letter-spacing:.08em}
.prow input{padding:9px 10px}
.rb{display:flex;gap:4px}.rb button{width:34px;height:38px;border-radius:8px;border:1px solid var(--line);background:transparent;color:var(--hi);cursor:pointer}.rb .rm{color:var(--bad)}
textarea{width:100%;padding:11px 12px;border-radius:10px;border:1px solid #5e4e3c;background:var(--ink);color:#fff;font:inherit;font-size:16px;resize:vertical}
.filebtn{cursor:pointer;display:inline-flex}
#cv{display:block;width:100%;margin-top:14px;border-radius:12px;border:2px solid var(--gold);touch-action:none;background:var(--plum)}#cv.strip{aspect-ratio:5/2;cursor:grab}#cv.cert{height:auto;max-height:60vh;object-fit:contain;cursor:default}
#crop label{margin-top:12px}
.glist{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.gitem{background:var(--plum);border:1px solid var(--line);border-radius:14px;overflow:hidden;display:grid}
.gitem img{width:100%;aspect-ratio:5/2;object-fit:cover;display:block}.certs .gitem img{aspect-ratio:4/3;object-fit:contain;background:#fff}
.gacts{display:flex;gap:6px;padding:10px;flex-wrap:wrap;align-items:flex-start}.gacts form{margin:0}.btn[disabled]{opacity:.35;pointer-events:none}
.ctitle{display:flex;gap:6px;padding:10px 10px 0}.ctitle input{padding:8px 10px}
@media (max-width:620px){.phead{display:none}.prow{grid-template-columns:1fr 1fr;padding:10px;border:1px solid var(--line);border-radius:12px}.prow input:nth-child(1),.prow input:nth-child(2){grid-column:1/-1}.prow .rb{grid-column:1/-1;justify-content:flex-end}.ex .prow{grid-template-columns:1fr 1fr}}

.tl{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.tli{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px;padding:2px 4px 2px 10px;background:var(--ink)}
.tli input{width:auto;border:0;background:transparent;padding:6px 2px;font-size:16px}
.tlx{border:0;background:none;color:var(--bad);cursor:pointer;width:28px;height:28px;border-radius:50%}
.hedit summary{cursor:pointer;list-style:none;display:flex;flex-wrap:wrap;gap:8px;align-items:center;color:var(--hi)}.hedit summary::-webkit-details-marker{display:none}
.hedit[open] summary{margin-bottom:8px}
.chgbox{background:linear-gradient(135deg,rgba(207,167,94,.16),rgba(207,167,94,.05));border:1px solid var(--gold);border-radius:16px;padding:16px 18px;margin:0 0 18px}
.chgbox h2{margin:0 0 10px;font-size:1.05rem;color:var(--hi);display:flex;align-items:center;gap:8px}.chgbox h2 span{background:var(--gold);color:var(--ink);border-radius:999px;font-size:.75rem;padding:1px 9px}
.chg{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid rgba(207,167,94,.2)}.chg:first-of-type{border-top:0}
.chg-t{flex:1 1 320px;font-size:.92rem;line-height:1.5}.chg-t s{color:var(--muted)}.chg-w{display:block;font-size:.78rem;color:var(--muted)}.chg-w a{color:var(--muted)}
.chg .acts{margin:0}.chg.cancel .chg-t::before{content:"❌ "}.chg.move .chg-t::before{content:"🔁 "}
.src.mv{color:var(--hi)}
.hed{margin-left:auto;font-size:.8rem;font-weight:600;color:var(--gold);border:1px solid var(--gold);border-radius:999px;padding:5px 14px;white-space:nowrap}.hed::after{content:" ▾"}
.hedit summary:hover .hed{background:rgba(207,167,94,.12)}.hedit[open] .hed{font-size:0;padding:5px 12px}.hedit[open] .hed::after{content:"Zwiń ▴";font-size:.8rem}
.wrow{display:grid;grid-template-columns:130px 1fr;gap:10px;align-items:center;padding:10px 0;border-bottom:1px dashed var(--line)}
.wrow b{color:var(--hi);font-weight:500}
.days .day.custom::after{content:"";position:absolute;top:6px;right:6px;width:6px;height:6px;border-radius:50%;background:#b98cf0}.days .day{position:relative}
i.h{background:#b98cf0}
@media (max-width:520px){.wrow{grid-template-columns:1fr}}
.avrow{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.avprev{width:56px;height:56px;border-radius:50%;background:var(--berry,#4a2470) center/cover no-repeat;display:grid;place-items:center;color:var(--hi);border:1px solid var(--gold);font-size:1.2rem}
.avimg{width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid var(--gold)}
.avform{margin:0}
.redit[open]{order:10;flex-basis:100%;width:100%}.redit summary{list-style:none;display:inline-flex}.redit summary::-webkit-details-marker{display:none}.redit[open] summary{margin-bottom:10px}
.linkhint{display:block;margin-top:2px;font-size:.8rem;line-height:1.45;color:var(--muted);text-transform:none;letter-spacing:0}.linkhint b{color:var(--hi);font-weight:600}
@media (max-width:520px){.grid{grid-template-columns:1fr}}
.ppic{flex:none;display:block;width:64px;height:64px;border-radius:12px;overflow:hidden;border:1px solid var(--gold)}.ppic img{width:100%;height:100%;object-fit:cover;display:block}.pet .ppic{width:84px;height:84px;margin-top:6px}
.resch[open]{flex-basis:100%;width:100%;order:5}.resch summary{list-style:none}.resch summary::-webkit-details-marker{display:none}.resch[open] summary{display:none}
.rform{display:grid;gap:10px;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--ink)}.rgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.chgbox.rem summary{list-style:none;cursor:pointer}.chgbox.rem summary::-webkit-details-marker{display:none}.chgbox.rem h2{margin:0}.chgbox.rem[open] h2{margin-bottom:6px}.sz.ok{background:rgba(127,199,154,.18);color:var(--ok)}
.smsbox{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-top:10px;border:1px solid var(--gold)}
.late{margin:6px 0;padding:8px 12px;border-radius:10px;background:rgba(233,180,76,.16);border:1px solid var(--warn);color:var(--hi);font-size:.9rem}
.bsearch{width:100%;margin:6px 0 10px}
.btable{display:grid;gap:6px}.bhead,.brow{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(86px,1fr) 70px 70px 34px;gap:6px;align-items:center}
.bhead{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;padding:0 2px}
.brow input,.brow select{padding:8px 9px;min-width:0}.brow .rm{width:34px;height:34px;border-radius:50%;border:1px solid var(--line);background:transparent;color:var(--muted);cursor:pointer}
.btable.sz .brow{grid-template-columns:minmax(0,2fr) 80px 80px}
label[hidden],#coatL[hidden]{display:none!important}
.noz{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;margin-top:8px;font-size:.82rem;color:var(--muted)}.noz label{display:inline-flex!important;flex-direction:row!important;align-items:center;gap:5px;color:var(--cream);font-size:.85rem}.noz input{width:18px;height:18px;accent-color:var(--gold)}
.bvar{grid-column:1/-1;margin:-2px 0 4px}.bvar summary{cursor:pointer;font-size:.72rem;color:var(--muted);opacity:.7;list-style:none;display:inline-block}.bvar[open] summary{color:var(--gold);opacity:1;font-size:.8rem}.bvar summary::-webkit-details-marker{display:none}.bvar textarea{width:100%;margin-top:6px;padding:8px 10px;border-radius:10px;border:1px solid var(--line);background:var(--ink);color:var(--cream);font:inherit;font-size:15px}.bvar small{display:block;color:var(--muted);font-size:.74rem;margin-top:4px}.btable.sz .bl{font-size:.9rem;color:var(--cream)}
.card2{border:1px solid var(--line);border-radius:14px;padding:12px;margin-top:10px;background:var(--ink)}
.sgrid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.sgrid.three{grid-template-columns:repeat(3,1fr)}.sgrid label{font-size:.78rem}
.pair{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.wrow2{display:grid;grid-template-columns:minmax(0,1.4fr) 1fr auto 1fr;gap:8px;align-items:center;margin-top:10px;padding-bottom:10px;border-bottom:1px dashed var(--line)}.wrow2 .gridl{grid-column:1/-1;font-size:.78rem}.wrow2 .sw{padding:8px 10px}
@media (max-width:560px){.bhead{display:none}.btable:not(.sz) .brow{grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) minmax(0,1fr) 34px;row-gap:5px;padding:8px 0;border-bottom:1px dashed var(--line)}.btable:not(.sz) .brow input:first-child{grid-column:1/-1}.sgrid.three{grid-template-columns:1fr}.wrow2{grid-template-columns:1fr 1fr auto 1fr}.wrow2 .sw{grid-column:1/-1}}
.sz{font-size:.72rem;font-weight:600;letter-spacing:.04em;padding:1px 8px;border-radius:99px;background:rgba(255,255,255,.08);color:var(--muted);white-space:nowrap}.sz.big{background:var(--warn);color:var(--ink)}
.chgbox.pend{border-color:var(--warn);background:linear-gradient(135deg,rgba(233,180,76,.16),rgba(233,180,76,.04))}.chgbox.pend h2 span{background:var(--warn)}
.sx summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px;font-size:1.05rem;color:var(--hi)}.sx summary::-webkit-details-marker{display:none}
.sx summary em.off{font-style:normal;font-size:.72rem;color:var(--ink);background:var(--muted);border-radius:99px;padding:1px 9px}
.sx[open] summary{margin-bottom:12px}.sx[open] .hed{font-size:0;padding:5px 12px}.sx[open] .hed::after{content:"Zwiń ▴";font-size:.8rem}
.sform{display:grid;gap:14px}.fld{display:grid;gap:6px}.fl{font-size:.85rem;color:var(--gold)}.fh{color:var(--muted);font-size:.8rem;margin-top:-3px}
.sw{display:flex!important;align-items:center;gap:10px;color:var(--cream);font-size:.95rem;background:var(--ink);border:1px solid var(--line);border-radius:12px;padding:10px 12px}.sw input{width:22px;height:22px;accent-color:var(--gold)}
.lrows{display:grid;gap:10px}.lrow{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--ink)}
.lrow label{font-size:.75rem}.lrow .rb{grid-column:1/-1;justify-content:flex-end}.lrow input,.lrow select,.lrow textarea{padding:9px 10px}
.imgf{display:flex;gap:14px;align-items:center;padding:10px;border:1px dashed var(--line);border-radius:12px;margin-bottom:10px}.imgf img{width:120px;height:84px;object-fit:cover;border-radius:10px;border:1px solid var(--gold);flex:none}.imgf img.sq{width:84px;border-radius:50%}
.imgf div{display:grid;gap:6px;justify-items:start}.imgf b{color:var(--hi);font-weight:500}.imgf small{color:var(--muted);font-size:.8rem}
textarea{line-height:1.45}
</style></head><body>
<?php }
