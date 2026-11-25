<?php
/* ZAKŁADKA „RASY I CENY”: rasy (wielkość, cena, czas), inne rasy wg wielkości, usługi i dodatek SPA.
   Dane trafiają do ustawienia c_breeds; strona (cennik + kalendarz) i serwer liczą z nich cenę i czas wizyty. */

function min_txt(int $m): string { return intdiv($m, 60) . ':' . sprintf('%02d', $m % 60); }
/* „2:30”, „2h30”, „2,5”, „150” → minuty (0 = błąd) */
function parse_min(string $s): int
{
    $s = trim(str_replace(',', '.', mb_strtolower($s)));
    if (preg_match('/^(\d{1,2})\s*(?::|h|godz\.?)\s*(\d{1,2})?\s*(?:min)?$/u', $s, $m)) return (int)$m[1] * 60 + (int)($m[2] ?? 0);
    if (preg_match('/^\d+(\.\d+)?$/', $s)) { $v = (float)$s; return $v <= 8 ? (int)round($v * 60) : (int)round($v); }
    if (preg_match('/^(\d+)\s*min$/u', $s, $m)) return (int)$m[1];
    return 0;
}
const SIZE_NAMES = ['small' => 'Mała', 'medium' => 'Średnia', 'big' => 'Duża'];

function breeds_handle(string $a): ?string
{
    if ($a === 'breeds_reset') { setting('c_breeds', ''); return 'Przywrócono cennik ras z notatek Kamy.'; }
    if ($a !== 'save_breeds') return null;
    $cur = breeds_get(); $bad = [];
    $breeds = [];
    foreach ((array)($_POST['br'] ?? []) as $r) {
        $n = trim(mb_substr(strip_tags((string)($r['n'] ?? '')), 0, 60)); if ($n === '') continue;
        $m = parse_min((string)($r['m'] ?? '')); if ($m < 15 || $m > 600) { $bad[] = $n; continue; }
        $sz = in_array($r['s'] ?? '', ['small', 'medium', 'big'], true) ? $r['s'] : 'small';
        $row = ['n' => $n, 's' => $sz, 'p' => max(0, (int)($r['p'] ?? 0)), 'm' => $m, 'a' => trim(mb_substr(strip_tags((string)($r['a'] ?? '')), 0, 120))];
        if (trim((string)($r['g'] ?? '')) !== '') $row['g'] = mb_substr(strip_tags((string)$r['g']), 0, 60);
        /* warianty sierści – jeden na linię: „krótkowłosy | 130 | 1:45” */
        $vs = [];
        foreach (preg_split('/\R|;/u', (string)($r['v'] ?? '')) as $ln) {
            $ln = trim(strip_tags($ln)); if ($ln === '') continue;
            $pp = array_map('trim', explode('|', $ln));
            if (count($pp) < 3 || $pp[0] === '' || ($vm = parse_min($pp[2])) < 15) { $bad[] = $n . ' (wariant „' . mb_substr($ln, 0, 30) . '”)'; continue; }
            $vs[] = ['n' => mb_substr($pp[0], 0, 40), 'p' => max(0, (int)preg_replace('/\D/', '', $pp[1])), 'm' => $vm];
        }
        if ($vs) { $row['v'] = $vs; $row['p'] = min(array_column($vs, 'p')); $row['m'] = $vs[0]['m']; }
        $breeds[] = $row;
    }
    if ($bad) return 'Nic nie zapisano – popraw czas przy: ' . implode(', ', array_slice($bad, 0, 5)) . ' (wpisz np. 2:30).';
    usort($breeds, fn($x, $y) => strcoll(mb_strtolower($x['n']), mb_strtolower($y['n'])));
    $sizes = $cur['sizes'];
    foreach (['small', 'medium', 'big'] as $k) {
        $m = parse_min((string)($_POST['sz'][$k]['m'] ?? '')); if ($m < 15) return 'Nic nie zapisano – popraw czas dla „inna rasa – ' . SIZE_NAMES[$k] . '”.';
        $sizes[$k] = ['label' => $cur['sizes'][$k]['label'] ?? '', 'p' => max(0, (int)($_POST['sz'][$k]['p'] ?? 0)), 'm' => $m];
    }
    $svcs = [];
    foreach ((array)($_POST['svc'] ?? []) as $r) {
        $n = trim(mb_substr(strip_tags((string)($r['n'] ?? '')), 0, 60)); if ($n === '') continue;
        $type = in_array($r['type'] ?? '', ['breed', 'size', 'fixed'], true) ? $r['type'] : 'fixed';
        $k = preg_match('/^[a-z0-9_]{2,20}$/', (string)($r['k'] ?? '')) ? $r['k'] : 'x' . substr(md5($n . microtime()), 0, 6);
        $s = ['k' => $k, 'n' => $n, 'd' => trim(mb_substr(strip_tags((string)($r['d'] ?? '')), 0, 80)), 'type' => $type];
        $no = array_values(array_intersect(['small', 'medium', 'big'], (array)($r['no'] ?? []))); if ($no) $s['no'] = $no;
        if ($type === 'size') {
            foreach (['small', 'medium', 'big'] as $z) { $s['p'][$z] = max(0, (int)($r['p'][$z] ?? 0)); $mm = parse_min((string)($r['m'][$z] ?? '')); if ($mm < 5) return "Nic nie zapisano – popraw czas usługi „{$n}”."; $s['m'][$z] = $mm; }
        } elseif ($type === 'fixed') {
            $s['p'] = max(0, (int)($r['p'] ?? 0)); $s['m'] = parse_min((string)($r['m'] ?? '')); if ($s['m'] < 5) return "Nic nie zapisano – popraw czas usługi „{$n}”.";
        }
        $svcs[] = $s;
    }
    if (!$svcs || !$breeds) return 'Cennik musi mieć co najmniej jedną rasę i jedną usługę.';
    $ad = null;
    if (trim((string)($_POST['ad']['n'] ?? '')) !== '') {
        $ad = ['n' => trim(mb_substr(strip_tags((string)$_POST['ad']['n']), 0, 70)), 'p' => max(0, (int)($_POST['ad']['p'] ?? 0)), 'm' => max(0, parse_min((string)($_POST['ad']['m'] ?? '0'))), 'for' => ['full']];
        $no = array_values(array_intersect(['small', 'medium', 'big'], (array)($_POST['ad']['no'] ?? []))); if ($no) $ad['no'] = $no;
    }
    setting('c_breeds', json_encode(['breeds' => $breeds, 'sizes' => $sizes, 'services' => $svcs, 'addon' => $ad], JSON_UNESCAPED_UNICODE));
    return 'Zapisano: ' . count($breeds) . ' ras i ' . count($svcs) . ' usług. Cennik i kalendarz na stronie już liczą z nowych danych.';
}

function breeds_view(): void
{
    $c = breeds_get(); $sel = fn($v) => implode('', array_map(fn($k) => '<option value="' . $k . '"' . ($k === $v ? ' selected' : '') . '>' . SIZE_NAMES[$k] . '</option>', array_keys(SIZE_NAMES)));
    $i = 0; ?>
  <section class="card">
    <h2>Rasy i ceny</h2>
    <p class="hint">Cena i czas pełnej pielęgnacji dla każdej rasy. Z tego strona liczy cennik, a kalendarz – ile miejsca zajmie wizyta (np. shih tzu 2:30 o 9:00 → następny klient od 11:30). Czas wpisuj jako <b>godziny:minuty</b>, np. <b>2:30</b>. Wielkość decyduje o cenie kąpieli i o zasadach dla dużych psów.</p>
    <form method="post" id="bf">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="save_breeds"><input type="hidden" name="back" value="?t=prices">
      <input type="search" id="bq" placeholder="🔍 Szukaj rasy…" class="bsearch">
      <div class="btable" id="brows">
        <div class="bhead"><span>Rasa</span><span>Wielkość</span><span>Cena zł</span><span>Czas</span><span></span></div>
        <?php foreach ($c['breeds'] as $b): $i++; ?>
        <div class="brow"><input name="br[<?= $i ?>][n]" value="<?= h($b['n']) ?>" aria-label="Rasa"><select name="br[<?= $i ?>][s]" aria-label="Wielkość"><?= $sel($b['s']) ?></select><input name="br[<?= $i ?>][p]" value="<?= (int)$b['p'] ?>" inputmode="numeric" aria-label="Cena"><input name="br[<?= $i ?>][m]" value="<?= h(min_txt((int)$b['m'])) ?>" aria-label="Czas" inputmode="decimal"><input type="hidden" name="br[<?= $i ?>][a]" value="<?= h($b['a'] ?? '') ?>"><input type="hidden" name="br[<?= $i ?>][g]" value="<?= h($b['g'] ?? '') ?>"><button type="button" class="rm" aria-label="Usuń rasę">✕</button>
          <?php $vt = implode("\n", array_map(fn($v) => $v['n'] . ' | ' . (int)$v['p'] . ' | ' . min_txt((int)$v['m']), $b['v'] ?? [])); ?>
          <details class="bvar"<?= $vt !== '' ? ' open' : '' ?>><summary><?= $vt !== '' ? 'Warianty sierści' : '+ warianty sierści' ?></summary><textarea name="br[<?= $i ?>][v]" rows="3" placeholder="krótkowłosy | 130 | 1:45&#10;średnia sierść | 150 | 2:10&#10;długowłosy / puchaty | 180 | 2:30"><?= h($vt) ?></textarea><small>Jeden wariant na linię: nazwa | cena | czas. Gdy są warianty, klient wybiera długość sierści, a cena i czas z wierszy wyżej są pomijane.</small></details></div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn sm ghost" id="addb">+ Dodaj rasę</button>

      <h2 style="margin-top:24px">Inna rasa / mieszaniec</h2>
      <p class="hint">Gdy klient wpisze rasę spoza listy, zaznacza wielkość psa – wtedy pełna pielęgnacja kosztuje i trwa tyle:</p>
      <div class="btable sz">
        <?php foreach (['small', 'medium', 'big'] as $k): $z = $c['sizes'][$k] ?? ['p' => 0, 'm' => 120, 'label' => '']; ?>
        <div class="brow"><span class="bl"><?= h($z['label'] ?: SIZE_NAMES[$k]) ?></span><input name="sz[<?= $k ?>][p]" value="<?= (int)$z['p'] ?>" inputmode="numeric" aria-label="Cena"><input name="sz[<?= $k ?>][m]" value="<?= h(min_txt((int)$z['m'])) ?>" aria-label="Czas"></div>
        <?php endforeach; ?>
      </div>

      <h2 style="margin-top:24px">Usługi</h2>
      <p class="hint">Kolejność jak na stronie. „Pełna pielęgnacja” bierze cenę i czas z tabeli ras, „Kąpiel” – zależnie od wielkości psa.</p>
      <div id="srows">
      <?php foreach ($c['services'] as $j => $s): $t = $s['type'] ?? 'fixed'; ?>
        <div class="srow card2">
          <input type="hidden" name="svc[<?= $j ?>][k]" value="<?= h($s['k']) ?>"><input type="hidden" name="svc[<?= $j ?>][type]" value="<?= h($t) ?>">
          <div class="sgrid"><label>Nazwa<input name="svc[<?= $j ?>][n]" value="<?= h($s['n']) ?>"></label><label>Opis<input name="svc[<?= $j ?>][d]" value="<?= h($s['d'] ?? '') ?>"></label></div>
          <div class="noz">Niedostępne dla: <?php foreach (['small' => 'małych', 'medium' => 'średnich', 'big' => 'dużych'] as $z => $zl): ?><label><input type="checkbox" name="svc[<?= $j ?>][no][]" value="<?= $z ?>" <?= in_array($z, (array)($s['no'] ?? []), true) ? 'checked' : '' ?>> <?= $zl ?></label><?php endforeach; ?></div>
          <?php if ($t === 'breed'): ?><p class="hint" style="margin:6px 0 0">Cena i czas – z tabeli ras powyżej.</p>
          <?php elseif ($t === 'size'): ?>
            <div class="sgrid three"><?php foreach (['small', 'medium', 'big'] as $z): ?><label><?= SIZE_NAMES[$z] ?> rasa: cena / czas<span class="pair"><input name="svc[<?= $j ?>][p][<?= $z ?>]" value="<?= (int)($s['p'][$z] ?? 0) ?>" inputmode="numeric"><input name="svc[<?= $j ?>][m][<?= $z ?>]" value="<?= h(min_txt((int)($s['m'][$z] ?? 60))) ?>"></span></label><?php endforeach; ?></div>
          <?php else: ?>
            <div class="sgrid"><label>Cena zł<input name="svc[<?= $j ?>][p]" value="<?= (int)($s['p'] ?? 0) ?>" inputmode="numeric"></label><label>Czas<input name="svc[<?= $j ?>][m]" value="<?= h(min_txt((int)($s['m'] ?? 60))) ?>"></label></div>
            <button type="button" class="link rms">Usuń usługę</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <button type="button" class="btn sm ghost" id="adds">+ Dodaj usługę (stała cena)</button>

      <h2 style="margin-top:24px">Dodatek do pielęgnacji</h2>
      <?php $ad = $c['addon'] ?? ['n' => '', 'p' => 0, 'm' => 0]; ?>
      <div class="sgrid three"><label>Nazwa<input name="ad[n]" value="<?= h($ad['n'] ?? '') ?>" placeholder="puste = brak dodatku"></label><label>Dopłata zł<input name="ad[p]" value="<?= (int)($ad['p'] ?? 0) ?>" inputmode="numeric"></label><label>Dodatkowy czas<input name="ad[m]" value="<?= h(min_txt((int)($ad['m'] ?? 0))) ?>"></label></div>
      <div class="noz">Niedostępne dla: <?php foreach (['small' => 'małych', 'medium' => 'średnich', 'big' => 'dużych'] as $z => $zl): ?><label><input type="checkbox" name="ad[no][]" value="<?= $z ?>" <?= in_array($z, (array)($ad['no'] ?? []), true) ? 'checked' : '' ?>> <?= $zl ?></label><?php endforeach; ?></div>

      <button class="btn full" style="width:100%;margin-top:18px">Zapisz rasy i ceny</button>
    </form>
    <details class="confirm" style="margin-top:12px"><summary class="link">Przywróć cennik z notatek Kamy</summary>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="breeds_reset"><input type="hidden" name="back" value="?t=prices"><button class="btn sm danger">Tak, przywróć</button></form></details>
  </section>
  <script>
  (function(){
    let n=Date.now();const sel='<?= str_replace("'", "\\'", $sel('small')) ?>';
    document.getElementById('addb').onclick=()=>{n++;const d=document.createElement('div');d.className='brow';
      d.innerHTML=`<input name="br[${n}][n]" placeholder="np. Pudel miniaturowy"><select name="br[${n}][s]">${sel}</select><input name="br[${n}][p]" inputmode="numeric" placeholder="150"><input name="br[${n}][m]" placeholder="2:30"><input type="hidden" name="br[${n}][a]" value=""><button type="button" class="rm" aria-label="Usuń rasę">✕</button>`;
      const box=document.getElementById('brows');box.insertBefore(d,box.children[1]);d.querySelector('input').focus()};
    document.getElementById('adds').onclick=()=>{n++;const d=document.createElement('div');d.className='srow card2';
      d.innerHTML=`<input type="hidden" name="svc[${n}][k]" value=""><input type="hidden" name="svc[${n}][type]" value="fixed"><div class="sgrid"><label>Nazwa<input name="svc[${n}][n]" placeholder="np. Czyszczenie uszu"></label><label>Opis<input name="svc[${n}][d]"></label></div><div class="sgrid"><label>Cena zł<input name="svc[${n}][p]" inputmode="numeric" placeholder="20"></label><label>Czas<input name="svc[${n}][m]" placeholder="0:15"></label></div><button type="button" class="link rms">Usuń usługę</button>`;
      document.getElementById('srows').appendChild(d);d.querySelector('input:not([type=hidden])').focus()};
    document.getElementById('bf').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;if(b.classList.contains('rm'))b.closest('.brow').remove();if(b.classList.contains('rms'))b.closest('.srow').remove()});
    const nr=s=>s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/ł/g,'l');
    document.getElementById('bq').oninput=e=>{const q=nr(e.target.value.trim());document.querySelectorAll('#brows .brow').forEach(r=>{r.hidden=q&&!nr(r.querySelector('input').value).includes(q)})};
  })();
  </script>
<?php }
