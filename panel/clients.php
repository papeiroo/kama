<?php
/* ==========================================================================
   KLIENCI (baza stałych klientów) + edycja danych wizyty ołówkiem ✎
   Klient = numer telefonu (ostatnie 9 cyfr). Historia liczy się sama z wizyt,
   Kama może dopisać notatkę i liczbę wizyt sprzed strony internetowej.
   ========================================================================== */

function ucf(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
function phone9(?string $p): string { return substr(preg_replace('/\D/', '', (string)$p), -9); }

function clients_ensure(): void
{
    static $done = false; if ($done) return; $done = true;
    try { db()->query('SELECT phone9 FROM clients LIMIT 1'); return; } catch (Throwable $e) {}
    $sq = is_sqlite();
    $id = $sq ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $tail = $sq ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    db()->exec("CREATE TABLE IF NOT EXISTS clients (id $id, phone9 VARCHAR(9) NOT NULL UNIQUE, name VARCHAR(120) NULL, pet VARCHAR(160) NULL, note TEXT NULL, prev INT NOT NULL DEFAULT 0, created_at VARCHAR(20) NOT NULL)$tail");
}

/* wszystkie dane o klientach: [phone9 => ['name','phone','pets'=>[],'b'=>[wizyty],'c'=>wiersz z tabeli clients|null]] */
function client_index(bool $fresh = false): array
{
    static $ix = null; if ($ix !== null && !$fresh) return $ix;
    clients_ensure(); $ix = [];
    foreach (db()->query('SELECT id, bdate, btime, name, phone, pet, service, status, dur FROM bookings ORDER BY bdate, btime') as $r) {
        $k = phone9($r['phone']); if (strlen($k) < 9) continue;
        $ix[$k] ??= ['name' => '', 'phone' => '', 'pets' => [], 'b' => [], 'c' => null];
        $ix[$k]['name'] = $r['name']; $ix[$k]['phone'] = (string)$r['phone'];
        if (!empty($r['pet'])) $ix[$k]['pets'][mb_strtolower($r['pet'])] = $r['pet'];
        $ix[$k]['b'][] = $r;
    }
    foreach (db()->query('SELECT * FROM clients') as $c) {
        $k = $c['phone9'];
        $ix[$k] ??= ['name' => '', 'phone' => $k, 'pets' => [], 'b' => [], 'c' => null];
        $ix[$k]['c'] = $c;
        if (!empty($c['name'])) $ix[$k]['name'] = $c['name'];
        if (!empty($c['pet'])) $ix[$k]['pets'] = [mb_strtolower($c['pet']) => $c['pet']] + $ix[$k]['pets'];
    }
    return $ix;
}

/* ile wizyt klient miał PRZED tą wizytą (bez odwołanych) + wizyty sprzed strony */
function client_count(array $r): array
{
    $k = phone9($r['phone'] ?? ''); $ix = client_index();
    if (strlen($k) < 9 || !isset($ix[$k])) return [0, null];
    /* liczymy wcześniejsze DNI wizyt – dwa psy jednego dnia to jedna wizyta */
    $me = (string)($r['bdate'] ?? ''); $days = [];
    foreach ($ix[$k]['b'] as $b) if ($b['status'] !== 'cancelled' && (int)$b['id'] !== (int)($r['id'] ?? 0) && $b['bdate'] < $me) $days[$b['bdate']] = 1;
    return [count($days) + (int)($ix[$k]['c']['prev'] ?? 0), $ix[$k]];
}
function visits_word(int $n): string { return $n === 1 ? 'wizyta' : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 'wizyty' : 'wizyt'); }

/* plakietka w prawym górnym rogu karty wizyty */
function client_badge(array $r): string
{
    $k = phone9($r['phone'] ?? ''); if (strlen($k) < 9) return '';
    [$n, $c] = client_count($r);
    $note = trim((string)($c['c']['note'] ?? ''));
    if ($n > 0 || ($c['c'] ?? null)) {
        $t = '⭐ Stały klient' . ($n > 0 ? ' · ' . ($n + 1) . '. wizyta' : '');
        return '<a class="cbadge reg" href="?t=clients&amp;c=' . h($k) . '" title="' . h($note !== '' ? 'Notatka: ' . $note : 'Pokaż historię klienta') . '">' . h($t) . ($note !== '' ? ' 📝' : '') . '</a>';
    }
    return '<a class="cbadge new" href="?t=clients&amp;c=' . h($k) . '">Nowy klient</a>';
}

/* ołówek ✎ – edycja jednego pola wizyty */
function pen(array $r, string $f, string $v, string $label, string $kind = 'text'): string
{
    return '<button type="button" class="pen" data-id="' . (int)$r['id'] . '" data-f="' . h($f) . '" data-v="' . h($v) . '" data-l="' . h($label) . '" data-k="' . h($kind) . '" title="Edytuj: ' . h($label) . '" aria-label="Edytuj: ' . h($label) . '">✎</button>';
}

/* dane wizyty (imię, pies, usługa, kontakt) z ołówkami – wspólne dla listy i kalendarza */
function bk_info(array $r): string
{
    $tel9 = phone9($r['phone'] ?? '');
    $h = '<div class="name"><span>' . h($r['name']) . '</span>' . pen($r, 'name', (string)$r['name'], 'imię i nazwisko') . '</div>';
    $h .= '<div class="pet">🐾 <span>' . (trim((string)($r['pet'] ?? '')) !== '' ? h($r['pet']) : '<i class="muted">pies – brak danych</i>') . '</span>' . pen($r, 'pet', (string)($r['pet'] ?? ''), 'pies (rasa, imię)')
        . size_tag($r) . pen($r, 'size', (string)($r['size'] ?? ''), 'wielkość psa', 'size') . pet_pic($r) . '</div>';
    $h .= '<div class="svc"><span>' . (trim((string)($r['service'] ?? '')) !== '' ? h($r['service']) : '<i class="muted">usługa – brak</i>') . '</span>' . pen($r, 'service', (string)($r['service'] ?? ''), 'usługa') . '</div>';
    $h .= '<div class="contact">'
        . '<span>' . ($tel9 ? '<a href="tel:+48' . h($tel9) . '">📞 ' . h(phone_pretty((string)$r['phone'])) . '</a>' : '<i class="muted">📞 brak telefonu</i>') . pen($r, 'phone', (string)($r['phone'] ?? ''), 'telefon', 'tel') . '</span>'
        . '<span>' . (!empty($r['email']) ? '<a href="mailto:' . h($r['email']) . '">✉️ ' . h($r['email']) . '</a>' : '<i class="muted">✉️ brak e-maila</i>') . pen($r, 'email', (string)($r['email'] ?? ''), 'e-mail', 'email') . '</span>'
        . '</div>';
    return $h;
}
function dur_pen(array $r): string { $d = (int)($r['dur'] ?? 0) ?: 120; return pen($r, 'dur', intdiv($d, 60) . ':' . sprintf('%02d', $d % 60), 'czas trwania (np. 2:30)', 'dur'); }

/* zapis jednego pola wizyty */
function bk_edit(int $id): string
{
    $f = (string)($_POST['f'] ?? ''); $v = trim((string)($_POST['v'] ?? ''));
    $st = db()->prepare('SELECT * FROM bookings WHERE id = ?'); $st->execute([$id]); $bk = $st->fetch();
    if (!$bk) return 'Nie znaleziono wizyty.';
    switch ($f) {
        case 'name':
            if ($v === '') return 'Imię nie może być puste.';
            $v = mb_substr($v, 0, 120); break;
        case 'phone':
            $v = mb_substr(preg_replace('/[^\d+ ]/', '', $v), 0, 20);
            if ($v !== '' && strlen(phone9($v)) < 9) return 'Numer telefonu musi mieć 9 cyfr.';
            break;
        case 'email':
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) return 'Nieprawidłowy adres e-mail.';
            $v = mb_substr($v, 0, 160); break;
        case 'pet': case 'service':
            $v = mb_substr($v, 0, 160); break;
        case 'size':
            if (!in_array($v, ['', 'small', 'medium', 'big'], true)) return 'Wybierz wielkość psa.';
            break;
        case 'dur':
            $m = parse_min($v);
            if ($m < 10 || $m > 600) return 'Podaj czas trwania, np. 2:30 albo 90.';
            if ($bk['status'] !== 'cancelled') {
                $s0 = hm2m((string)$bk['btime']); $e0 = $s0 + $m;
                $bm = busy_map((string)$bk['bdate'], (string)$bk['bdate'], (int)$bk['id']);
                foreach ($bm['busy'][$bk['bdate']] ?? [] as [$a1, $b1]) if ($s0 < $b1 && $e0 > $a1) return 'Nie zmieniono – przy takim czasie wizyta nachodzi na inną wizytę lub blokadę (' . m2hm($a1) . '–' . m2hm($b1) . ').';
            }
            $v = $m; break;
        default: return 'Nieznane pole.';
    }
    db()->prepare("UPDATE bookings SET $f = ? WHERE id = ?")->execute([$v === '' ? null : $v, $id]);
    if ($f === 'dur') { $wk = work_for((string)$bk['bdate']); $end = hm2m((string)$bk['btime']) + (int)$v;
        return 'Zapisano: ' . $bk['btime'] . '–' . m2hm($end) . '.' . ($wk && $end > $wk[1] ? ' Uwaga – kończy się po zamknięciu salonu.' : ''); }
    return 'Zapisano zmiany.';
}

/* akcje zakładki Klienci */
function clients_handle(string $a): string
{
    clients_ensure();
    $k = phone9((string)($_POST['phone'] ?? ''));
    if ($a === 'client_del') {
        db()->prepare('DELETE FROM clients WHERE phone9 = ?')->execute([phone9((string)($_POST['k'] ?? ''))]);
        return 'Usunięto notatkę i dane klienta z bazy (wizyty zostały w historii).';
    }
    if (strlen($k) < 9) return 'Podaj numer telefonu (9 cyfr) – po nim rozpoznajemy klienta.';
    $old = phone9((string)($_POST['k'] ?? ''));
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    $pet = mb_substr(trim((string)($_POST['pet'] ?? '')), 0, 160);
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000);
    $prev = max(0, min(999, (int)($_POST['prev'] ?? 0)));
    if ($old !== '' && $old !== $k) {   // zmiana numeru – przenieś też numer w wizytach
        foreach (client_index()[$old]['b'] ?? [] as $b) db()->prepare('UPDATE bookings SET phone = ? WHERE id = ?')->execute([$k, $b['id']]);
        db()->prepare('DELETE FROM clients WHERE phone9 = ?')->execute([$old]);
    }
    $st = db()->prepare('SELECT id FROM clients WHERE phone9 = ?'); $st->execute([$k]);
    if ($st->fetchColumn()) db()->prepare('UPDATE clients SET name = ?, pet = ?, note = ?, prev = ? WHERE phone9 = ?')->execute([$name ?: null, $pet ?: null, $note ?: null, $prev, $k]);
    else db()->prepare('INSERT INTO clients (phone9, name, pet, note, prev, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$k, $name ?: null, $pet ?: null, $note ?: null, $prev, date('Y-m-d H:i:s')]);
    return $old === '' ? 'Klient zapisany w bazie.' : 'Zapisano dane klienta.';
}

function client_form(string $k = '', array $c = []): string
{
    $x = $c['c'] ?? [];
    return '<form method="post" class="grid2"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="a" value="client_save"><input type="hidden" name="k" value="' . h($k) . '"><input type="hidden" name="back" value="?t=clients' . ($k ? '&c=' . h($k) : '') . '">'
        . '<label>Imię i nazwisko<input name="name" maxlength="120" value="' . h($x['name'] ?? ($c['name'] ?? '')) . '" placeholder="np. Anna Kowalska"></label>'
        . '<label>Telefon<input name="phone" type="tel" inputmode="tel" required value="' . h($k) . '" placeholder="np. 600 100 200"></label>'
        . '<label>Pies (rasa, imię)<input name="pet" maxlength="160" value="' . h($x['pet'] ?? (($c['pets'] ?? []) ? reset($c['pets']) : '')) . '" placeholder="np. Shih tzu · Fafik"></label>'
        . '<label>Wizyty przed stroną internetową<small>ile razy był(a) już wcześniej – doliczy się do licznika</small><input name="prev" type="number" min="0" max="999" value="' . (int)($x['prev'] ?? 0) . '"></label>'
        . '<label class="full">Notatka (widzi tylko Kama)<textarea name="note" rows="3" maxlength="2000" placeholder="np. pies boi się suszarki, strzyżenie na 6 mm, płaci kartą">' . h($x['note'] ?? '') . '</textarea></label>'
        . '<div class="full"><button class="btn">' . ($k ? 'Zapisz zmiany' : 'Dodaj klienta') . '</button></div></form>';
}

function clients_view(): void
{
    $ix = client_index(); $today = date('Y-m-d');
    $sel = phone9((string)($_GET['c'] ?? ''));
    if ($sel !== '' && strlen($sel) === 9) {
        $c = $ix[$sel] ?? ['name' => '', 'phone' => $sel, 'pets' => [], 'b' => [], 'c' => null];
        $done = array_unique(array_column(array_filter($c['b'], fn($b) => $b['status'] !== 'cancelled' && $b['bdate'] < $today), 'bdate'));
        $n = count($done) + (int)($c['c']['prev'] ?? 0);
        echo '<p><a href="?t=clients" class="link">‹ Wszyscy klienci</a></p><section class="card">';
        echo '<h2>' . h($c['name'] ?: 'Klient') . ' <span class="cbadge ' . ($n || $c['c'] ? 'reg' : 'new') . '">' . ($n || $c['c'] ? '⭐ Stały klient' : 'Nowy klient') . '</span></h2>';
        echo '<p class="hint">📞 <a href="tel:+48' . h($sel) . '">' . h(phone_pretty($sel)) . '</a> · wizyt: <b>' . $n . '</b>' . ((int)($c['c']['prev'] ?? 0) ? ' (w tym ' . (int)$c['c']['prev'] . ' sprzed strony)' : '') . ($c['pets'] ? ' · 🐾 ' . h(implode(', ', $c['pets'])) : '') . '</p>';
        if (!empty($c['c']['note'])) echo '<p class="cnote">📝 ' . nl2br(h($c['c']['note'])) . '</p>';
        echo '<details class="confirm"' . ($c['c'] ? '' : ' open') . '><summary class="btn sm ghost">✎ Edytuj dane i notatkę</summary>' . client_form($sel, $c) . '</details></section>';
        echo '<h2 class="hist-h">Historia wizyt</h2>';
        if (!$c['b']) echo '<p class="empty">Brak wizyt zapisanych przez stronę lub panel.</p>';
        $labels = ['pending' => 'do potwierdzenia', 'confirmed' => 'potwierdzona', 'cancelled' => 'odwołana'];
        foreach (array_reverse($c['b']) as $b) {
            $fut = $b['bdate'] >= $today;
            echo '<div class="hrow' . ($b['status'] === 'cancelled' ? ' x' : '') . '"><b>' . h(ucf(pl_date($b['bdate']))) . ', ' . h($b['btime']) . '</b>'
                . '<span>' . h(trim(($b['pet'] ?? '') . ' · ' . ($b['service'] ?? ''), ' ·')) . '</span>'
                . '<span class="pill">' . ($fut && $b['status'] !== 'cancelled' ? 'nadchodząca' : ($b['status'] === 'cancelled' ? 'odwołana' : ($b['bdate'] < $today ? 'odbyta' : $labels[$b['status']] ?? ''))) . '</span>'
                . '<a class="link" href="?t=cal&amp;m=' . h(substr($b['bdate'], 0, 7)) . '&amp;d=' . h($b['bdate']) . '#dzien">kalendarz</a></div>';
        }
        if ($c['c']) echo '<form method="post" style="margin-top:18px" onsubmit="return confirm(\'Usunąć klienta z bazy? Wizyty zostaną w historii.\')"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="a" value="client_del"><input type="hidden" name="k" value="' . h($sel) . '"><input type="hidden" name="back" value="?t=clients"><button class="btn sm danger">Usuń klienta z bazy</button></form>';
        return;
    }
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $list = [];
    foreach ($ix as $k => $c) {
        $done = 0; $last = ''; $next = '';
        foreach ($c['b'] as $b) { if ($b['status'] === 'cancelled') continue; if ($b['bdate'] < $today) { if ($last !== $b['bdate']) $done++; $last = $b['bdate']; } elseif ($next === '') $next = $b['bdate'] . ' ' . $b['btime']; }
        $n = $done + (int)($c['c']['prev'] ?? 0);
        $hay = mb_strtolower($c['name'] . ' ' . $k . ' ' . implode(' ', $c['pets']) . ' ' . ($c['c']['note'] ?? ''));
        if ($q !== '' && mb_strpos($hay, $q) === false && strpos($k, preg_replace('/\D/', '', $q) ?: 'x') === false) continue;
        $list[] = compact('k', 'c', 'n', 'last', 'next');
    }
    usort($list, fn($a, $b) => [$b['n'], $a['c']['name']] <=> [$a['n'], $b['c']['name']]);
    ?>
  <section class="card">
    <h2>Klienci</h2>
    <p class="hint">Klient rozpoznawany jest po <b>numerze telefonu</b>. Każdy, kto zapisze się przez stronę albo dodasz go w panelu, trafia tu sam. Przy rezerwacji zobaczysz plakietkę <span class="cbadge reg">⭐ Stały klient · 3. wizyta</span> albo <span class="cbadge new">Nowy klient</span>. Swoich dotychczasowych stałych klientów dopisz poniżej – wystarczy telefon i imię.</p>
    <form method="get" class="csearch"><input type="hidden" name="t" value="clients"><input name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Szukaj: imię, telefon, pies, notatka…"><button class="btn sm">Szukaj</button></form>
    <details class="confirm"><summary class="btn sm">+ Dodaj stałego klienta</summary><?= client_form() ?></details>
  </section>
  <?php if (!$list) echo '<p class="empty">' . ($q !== '' ? 'Nikogo nie znaleziono.' : 'Brak klientów – pojawią się tu po pierwszych rezerwacjach albo dodaj ich ręcznie.') . '</p>'; ?>
  <div class="clist">
  <?php foreach ($list as $x): $c = $x['c']; ?>
    <a class="crow" href="?t=clients&amp;c=<?= h($x['k']) ?>">
      <span class="cn"><b><?= h($c['name'] ?: '(bez imienia)') ?></b><small>📞 <?= h(phone_pretty($x['k'])) ?><?= $c['pets'] ? ' · 🐾 ' . h(implode(', ', array_slice($c['pets'], 0, 2))) : '' ?></small><?= !empty($c['c']['note']) ? '<small class="cnote-s">📝 ' . h(mb_strimwidth($c['c']['note'], 0, 90, '…')) . '</small>' : '' ?></span>
      <span class="cv"><b><?= (int)$x['n'] ?></b><small><?= visits_word((int)$x['n']) ?></small></span>
      <span class="cd"><?= $x['next'] ? '<small>najbliższa</small>' . h(ucf(pl_date(substr($x['next'], 0, 10)))) : ($x['last'] ? '<small>ostatnia</small>' . h(ucf(pl_date($x['last']))) : '<small>—</small>') ?></span>
    </a>
  <?php endforeach; ?>
  </div>
<?php }

/* JS + CSS ołówków i plakietek (raz na stronę) */
function clients_assets(): string
{
    return <<<'H'
<style>
.pen{border:0;background:none;color:var(--muted);cursor:pointer;font-size:.85rem;padding:2px 6px;margin-left:2px;border-radius:6px;line-height:1;vertical-align:middle;opacity:.75}
.pen:hover,.pen:focus-visible{color:var(--hi);background:rgba(207,167,94,.14);opacity:1}
.bk .name,.bk .pet,.bk .svc{display:flex;align-items:center;flex-wrap:wrap;gap:4px}
.bk .contact>span{display:inline-flex;align-items:center}
.muted{color:var(--muted);font-style:normal;opacity:.8}
.pform{display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;width:100%;margin:2px 0}
.pform input,.pform select{flex:1 1 220px;min-width:0;padding:8px 10px;border-radius:10px;border:1px solid var(--gold);background:var(--ink);color:var(--cream);font:inherit;font-size:16px}
.pform small{flex-basis:100%;color:var(--muted);font-size:.75rem}
.bk{position:relative}
.cbadge{display:inline-block;font-size:.74rem;padding:3px 10px;border-radius:999px;text-decoration:none;white-space:nowrap;font-weight:600;vertical-align:middle}
.cbadge.reg{background:linear-gradient(120deg,#9a7232,var(--gold));color:var(--ink)}
.cbadge.new{background:rgba(255,255,255,.07);color:var(--muted);border:1px solid var(--line);font-weight:500}
.row1 .cbadge{margin-left:auto}
.cnote{background:rgba(207,167,94,.1);border-left:3px solid var(--gold);padding:10px 12px;border-radius:8px;color:var(--cream)}
.csearch{display:flex;gap:8px;margin:12px 0}.csearch input{flex:1;min-width:0;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--ink);color:var(--cream);font:inherit;font-size:16px}
.clist{display:grid;gap:8px;margin-top:12px}
.crow{display:grid;grid-template-columns:minmax(0,1fr) 70px 140px;gap:12px;align-items:center;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--plum);text-decoration:none;color:var(--cream)}
.crow:hover{border-color:var(--gold)}.crow small{display:block;color:var(--muted);font-size:.78rem;margin-top:2px}.crow .cnote-s{color:var(--hi);opacity:.8}
.cv{text-align:center}.cv b{font-size:1.3rem;color:var(--gold)}.cd{font-size:.82rem;text-align:right}
.hrow{display:grid;grid-template-columns:200px minmax(0,1fr) auto auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line);font-size:.9rem}
.hrow.x{opacity:.55}.hist-h{font-size:1rem;color:var(--gold);margin:22px 0 8px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.grid2 .full{grid-column:1/-1}
.grid2 input,.grid2 textarea{padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--ink);color:var(--cream);font:inherit;font-size:16px}
.chint{font-size:.85rem;margin-top:4px}
@media(max-width:640px){.crow{grid-template-columns:minmax(0,1fr) 56px}.cd{display:none}.hrow{grid-template-columns:1fr auto}.hrow span:not(.pill){grid-column:1/-1;order:3}.hrow a{display:none}.grid2{grid-template-columns:1fr}.row1 .cbadge{margin-left:0}}
</style>
<script>
document.addEventListener('click',e=>{
  const p=e.target.closest('.pen');
  if(p){e.preventDefault();const host=p.parentElement;if(host.querySelector('.pform'))return;
    const k=p.dataset.k,v=p.dataset.v,f=document.createElement('form');f.method='post';f.className='pform';
    const csrf=(document.querySelector('input[name=csrf]')||{}).value||'';
    let field;
    if(k==='size'){field='<select name="v"><option value="">– nie podano –</option>'+[['small','mały pies'],['medium','średni pies'],['big','duży pies']].map(([a,b])=>'<option value="'+a+'"'+(a===v?' selected':'')+'>'+b+'</option>').join('')+'</select>'}
    else{field='<input name="v" type="'+(k==='tel'?'tel':k==='email'?'email':'text')+'"'+(k==='tel'?' inputmode="tel"':'')+'>'}
    f.innerHTML='<input type="hidden" name="csrf"><input type="hidden" name="a" value="bk_edit"><input type="hidden" name="id"><input type="hidden" name="f"><input type="hidden" name="back">'+field+
      '<button class="btn sm">Zapisz</button><button type="button" class="link pcancel">Anuluj</button>'+(k==='dur'?'<small>Czas trwania wizyty, np. 2:30 albo 150 (minuty). Godzinę rozpoczęcia zmienisz przyciskiem „Zmień termin”.</small>':'');
    f.csrf.value=csrf;f.id.value=p.dataset.id;f.f.value=p.dataset.f;f.back.value=location.search||'?t=next';
    if(k!=='size')f.v.value=v;
    const kids=[...host.childNodes];kids.forEach(n=>{if(n.nodeType===1)n.dataset.hid=n.hidden?'1':'',n.nodeType===1&&(n.hidden=true)});
    host.appendChild(f);f._kids=kids;f.v.focus();if(f.v.select)f.v.select();return}
  const c=e.target.closest('.pcancel');
  if(c){const f=c.closest('.pform');(f._kids||[]).forEach(n=>{if(n.nodeType===1)n.hidden=n.dataset.hid==='1'});f.remove()}
});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){const f=document.querySelector('.pform');f&&f.querySelector('.pcancel').click()}});
</script>
H;
}
