<?php
/* EDYCJA TREŚCI STRONY – schemat sekcji, zapis i formularze (dołączane przez panel/index.php) */

function site_schema(): array
{
    $sec = fn(string $id, string $title, ?string $toggle, array $f, string $hint = '') => compact('id', 'title', 'toggle', 'f', 'hint');
    return [
        $sec('dane', 'Dane salonu i kontakt', null, [
            ['c', 'phone', 'Telefon', 'Używany w przyciskach „Zadzwoń”, SMS i WhatsApp oraz w mailach do klientów.'],
            ['c', 'street', 'Ulica i numer'], ['c', 'city', 'Kod pocztowy i miasto'],
            ['c', 'fb', 'Link do Facebooka', 'Puste = ikonki Facebooka znikną ze strony.'],
            ['t', 'brand', 'Nazwa salonu'],
            ['ta', 'foot_p', 'Tekst w stopce'], ['lines', 'fsvc', 'Usługi wypisane w stopce', 'Jedna usługa w linii.'],
            ['t', 'copy', 'Podpis na samym dole (po ©)'],
            ['seo', 'title', 'Tytuł strony w Google'], ['seo', 'desc', 'Opis strony w Google', 'Ok. 150 znaków. Pokazuje się pod tytułem w wynikach wyszukiwania i przy udostępnianiu na Facebooku.'],
        ]),
        $sec('top', 'Góra strony (powitanie)', null, [
            ['img', 'logo', 'Logo w kółku', 'Kwadratowe zdjęcie lub grafika – zostanie przycięta do kwadratu.', 'sq'],
            ['img', 'icon', 'Małe logo (pasek menu i stopka)', '', 'sq'],
            ['t', 'hero_small', 'Mały napis nad nazwą'], ['ta', 'hero_lead', 'Tekst powitalny'],
            ['t', 'hero_btn1', 'Przycisk 1 (rezerwacja)'], ['t', 'hero_btn2', 'Przycisk 2 (cennik)'],
            ['rows', 'facts', 'Trzy wyróżniki pod przyciskami', 'Link: wpisz „fb”, żeby napis prowadził do Facebooka, albo pełny adres https://…', [['b', 'Duży napis'], ['s', 'Mały napis'], ['link', 'Link (opcjonalnie)']]],
        ]),
        $sec('pasek', 'Złoty pasek z napisami', 'pasek', [
            ['lines', 'marquee', 'Napisy przesuwające się na złotym pasku', 'Jeden napis w linii.'],
        ]),
        $sec('o-nas', 'O salonie', 'o-nas', [
            ['t', 'about_eye', 'Mały nagłówek'], ['t', 'about_h2', 'Tytuł'],
            ['ta', 'about_p', 'Opis salonu', 'Pusta linia między akapitami = nowy akapit.', 8],
            ['lines', 'chips', 'Złote etykiety pod opisem', 'Jedna etykieta w linii.'],
            ['img', 'about', 'Zdjęcie w łuku'], ['img', 'badge', 'Zdjęcie w małym kółku', '', 'sq'],
            ['img', 'inside', 'Duże zdjęcie salonu', 'To samo zdjęcie pokazuje się też w sekcji „Dojazd” jako „W środku”.'],
            ['t', 'inside_b', 'Podpis dużego zdjęcia – pogrubiony'], ['t', 'inside_cap', 'Podpis dużego zdjęcia – tekst'],
        ]),
        $sec('uslugi', 'Usługi (kafelki)', 'uslugi', [
            ['t', 'svc_eye', 'Mały nagłówek'], ['t', 'svc_h2', 'Tytuł'], ['ta', 'svc_lead', 'Tekst pod tytułem'],
            ['rows', 'services', 'Kafelki usług', 'Lista punktów: jeden punkt w linii (opcjonalnie). Cena: np. „od 120 zł” albo puste.', [['icon', 'Ikonka', 'icon'], ['title', 'Nazwa'], ['desc', 'Opis', 'ta'], ['list', 'Lista punktów', 'ta'], ['price', 'Cena na kafelku']]],
        ]),
        $sec('realizacje', 'Realizacje (przed / po)', 'realizacje', [
            ['t', 'gal_eye', 'Mały nagłówek'], ['t', 'gal_h2', 'Tytuł'], ['ta', 'gal_lead', 'Tekst pod tytułem'],
        ], 'Zdjęcia psów dodajesz w zakładce „Galeria”.'),
        $sec('cennik', 'Cennik – napisy', 'cennik', [
            ['t', 'price_eye', 'Mały nagłówek'], ['t', 'price_h2', 'Tytuł'], ['t', 'price_title', 'Nagłówek tabeli'], ['t', 'price_sub', 'Dopisek pod nagłówkiem'],
            ['t', 'price_c0', 'Kolumna 1'], ['t', 'price_c1', 'Kolumna 2 (np. Małe rasy)'], ['t', 'price_c2', 'Kolumna 3 (np. Duże rasy)'],
            ['t', 'price_extra', 'Nagłówek usług dodatkowych'], ['t', 'price_cta', 'Przycisk pod cennikiem'],
        ], 'Same ceny zmieniasz w zakładce „Cennik”.'),
        $sec('wizyta', 'Jak wygląda wizyta', 'wizyta', [
            ['t', 'steps_eye', 'Mały nagłówek'], ['t', 'steps_h2', 'Tytuł'],
            ['rows', 'steps', 'Kroki (numerują się same)', '', [['t', 'Nazwa kroku'], ['d', 'Opis', 'ta']]],
        ]),
        $sec('rezerwacja', 'Rezerwacja online', 'rezerwacja', [
            ['t', 'book_eye', 'Mały nagłówek'], ['t', 'book_h2', 'Tytuł'], ['ta', 'book_lead', 'Tekst pod tytułem'],
            ['t', 'hours_h', 'Tytuł ramki z godzinami'],
            ['rows', 'hours', 'Godziny otwarcia (do wyświetlenia)', 'To tylko napis na stronie. Godziny, na które można się zapisać, ustawiasz w zakładce „Godziny pracy”.', [['d', 'Dni'], ['h', 'Godziny']]],
            ['t', 'before_h', 'Tytuł ramki „Przed wizytą”'], ['lines', 'before', 'Wskazówki przed wizytą', 'Jedna wskazówka w linii.'],
        ], 'Ukrycie tej sekcji wyłącza rezerwacje przez stronę – przyciski „Umów wizytę” prowadzą wtedy do kontaktu.'),
        $sec('certyfikaty', 'Certyfikaty – napisy', null, [
            ['t', 'certs_eye', 'Mały nagłówek'], ['t', 'certs_h2', 'Tytuł'], ['ta', 'certs_lead', 'Tekst pod tytułem'],
        ], 'Sekcja pokazuje się sama, gdy dodasz certyfikat w zakładce „Certyfikaty”.'),
        $sec('opinie', 'Opinie – napisy', 'opinie', [
            ['t', 'rev_eye', 'Mały nagłówek'], ['t', 'rev_h2', 'Tytuł'],
        ], 'Same opinie dodajesz w zakładce „Opinie”.'),
        $sec('regulamin', 'Regulamin', 'regulamin', [
            ['t', 'reg_eye', 'Mały nagłówek'], ['t', 'reg_h2', 'Tytuł'], ['ta', 'reg_lead', 'Tekst pod tytułem'],
            ['lines', 'reg', 'Punkty regulaminu', 'Jeden punkt w linii – numerują się same.', 14],
            ['t', 'reg_thanks', 'Podziękowanie pod regulaminem'],
        ]),
        $sec('faq', 'Najczęstsze pytania', 'faq', [
            ['t', 'faq_eye', 'Mały nagłówek'], ['t', 'faq_h2', 'Tytuł'],
            ['rows', 'faq', 'Pytania i odpowiedzi', '', [['q', 'Pytanie'], ['a', 'Odpowiedź', 'ta']]],
        ]),
        $sec('kontakt', 'Kontakt', 'kontakt', [
            ['t', 'contact_eye', 'Mały nagłówek'], ['t', 'contact_h2', 'Tytuł'], ['ta', 'contact_lead', 'Tekst pod tytułem'],
            ['t', 'c_tel', 'Dopisek pod telefonem'], ['t', 'c_sms', 'Dopisek pod SMS'], ['t', 'c_wa', 'Dopisek pod WhatsApp'], ['t', 'c_fb', 'Dopisek pod Facebookiem'],
        ], 'Numer telefonu i adres zmieniasz w „Dane salonu i kontakt”.'),
        $sec('dojazd', 'Dojazd i mapa', 'dojazd', [
            ['t', 'map_eye', 'Mały nagłówek'], ['t', 'map_h2', 'Tytuł'], ['ta', 'map_lead', 'Jak trafić (tekst pod tytułem)'],
            ['img', 'entry', 'Zdjęcie wejścia'], ['t', 'entry_b', 'Podpis zdjęcia wejścia – pogrubiony'], ['t', 'entry_cap', 'Podpis zdjęcia wejścia – tekst'],
            ['t', 'in2_b', 'Podpis zdjęcia wnętrza – pogrubiony'], ['t', 'in2_cap', 'Podpis zdjęcia wnętrza – tekst'],
        ], 'Mapa ustawia się sama według adresu z „Dane salonu i kontakt”.'),
    ];
}

const SITE_ICONS = ['i-cut' => '✂️ Nożyczki', 'i-comb' => '🪮 Grzebień', 'i-bath' => '🛁 Wanna', 'i-o3' => '🫧 Ozon (O₃)', 'i-care' => '🐾 Łapka', 'i-pup' => '💛 Serce z łapką'];

function site_val(array $s, array $f)
{
    [$type, $key] = $f;
    switch ($type) {
        case 'c': return $s['contact'][$key] ?? '';
        case 'seo': return $s['seo'][$key] ?? '';
        case 'img': return $s['img'][$key] ?? '';
        case 'lines': return implode("\n", (array)($s['l'][$key] ?? []));
        case 'rows': return (array)($s['l'][$key] ?? []);
        default: return $s['t'][$key] ?? '';
    }
}

/* ---------- ZAPIS ---------- */
function site_handle(string $a): ?string
{
    $schema = []; foreach (site_schema() as $x) $schema[$x['id']] = $x;
    $sid = (string)($_POST['sec'] ?? '');
    if (!isset($schema[$sid])) return null;
    $S = site_get(); $D = site_default(); $sec = $schema[$sid];
    $clean = fn($v, int $max = 3000) => mb_substr(trim(str_replace("\r", '', strip_tags((string)$v))), 0, $max);

    if ($a === 'site_save') {
        foreach ($sec['f'] as $f) {
            [$type, $key] = $f;
            if ($type === 'img') continue;
            if ($type === 'rows') {
                $cols = $f[4]; $raw = (array)($_POST['L'][$key] ?? []); $first = $cols[0][0];
                $n = count((array)($raw[$first] ?? [])); $rows = [];
                for ($i = 0; $i < $n; $i++) {
                    $r = []; $any = false;
                    foreach ($cols as $c) { $v = $clean($raw[$c[0]][$i] ?? '', 2000); if ($c[0] === 'icon' && !isset(SITE_ICONS[$v])) $v = 'i-care'; elseif ($v !== '' && $c[0] !== 'icon') $any = true; $r[$c[0]] = $v; }
                    if ($any) $rows[] = $r;
                }
                $S['l'][$key] = $rows; continue;
            }
            $v = $_POST['F'][$type][$key] ?? null; if ($v === null) continue;
            if ($type === 'lines') { $S['l'][$key] = array_values(array_filter(array_map(fn($x) => $clean($x, 400), explode("\n", (string)$v)), 'strlen')); continue; }
            $v = $clean($v, $type === 'seo' ? 320 : 3000);
            if ($type === 'c') {
                if ($key === 'phone' && $v !== '' && strlen(preg_replace('/\D/', '', $v)) < 9) return 'Numer telefonu wygląda na niepełny – nic nie zapisano.';
                if ($key === 'fb' && $v !== '' && !preg_match('#^https?://\S+$#i', $v)) return 'Link do Facebooka musi zaczynać się od https:// – nic nie zapisano.';
                $S['contact'][$key] = $v;
            } elseif ($type === 'seo') $S['seo'][$key] = $v;
            else $S['t'][$key] = $v;
        }
        if ($sec['toggle']) {
            $hide = array_values(array_diff($S['hide'] ?? [], [$sec['toggle']]));
            if (empty($_POST['show'])) $hide[] = $sec['toggle'];
            $S['hide'] = $hide;
        }
        site_save($S);
        return 'Zapisano „' . $sec['title'] . '”. Zmiany są już na stronie.';
    }
    if ($a === 'site_reset') {
        foreach ($sec['f'] as $f) {
            [$type, $key] = $f;
            $map = ['c' => 'contact', 'seo' => 'seo', 'img' => 'img', 'lines' => 'l', 'rows' => 'l'][$type] ?? 't';
            if ($type === 'img' && strncmp((string)($S['img'][$key] ?? ''), 'uploads/site/', 13) === 0) delete_upload($S['img'][$key]);
            $S[$map][$key] = $D[$map][$key] ?? '';
        }
        if ($sec['toggle']) $S['hide'] = array_values(array_diff($S['hide'] ?? [], [$sec['toggle']]));
        site_save($S);
        return 'Przywrócono oryginalne treści sekcji „' . $sec['title'] . '”.';
    }
    if ($a === 'site_img') {
        $key = (string)($_POST['key'] ?? '');
        $ok = false; foreach ($sec['f'] as $f) if ($f[0] === 'img' && $f[1] === $key) $ok = true;
        if (!$ok) return null;
        $path = save_jpeg_dataurl((string)($_POST['data'] ?? ''), 'site');
        if (!$path) return 'Nie udało się zapisać zdjęcia. Spróbuj innego pliku (JPG lub PNG).';
        $old = (string)($S['img'][$key] ?? '');
        if (strncmp($old, 'uploads/site/', 13) === 0 && !in_array($old, array_diff_key($S['img'], [$key => 1]), true)) delete_upload($old);
        $S['img'][$key] = $path; site_save($S);
        return 'Zdjęcie podmienione na stronie.';
    }
    return null;
}

/* ---------- WIDOK ---------- */
function site_editor_view(): void
{
    $S = site_get(); $hide = $S['hide'] ?? []; $open = (string)($_GET['s'] ?? '');
    ?>
  <section class="card">
    <h2>Treści strony</h2>
    <p class="hint">Każdą sekcję strony możesz tu zmienić sama: teksty, zdjęcia, listy, a także ukryć ją ze strony. Kliknij sekcję, popraw i naciśnij „Zapisz”.<br>
    ✨ <b>*słowo w gwiazdkach*</b> = złote wyróżnienie w tytule · nowa linia w polu = nowa linia na stronie.</p>
    <a class="btn sm ghost" href="../" target="_blank" rel="noopener">Otwórz stronę w nowej karcie ↗</a>
  </section>
  <?php foreach (site_schema() as $sec): $sid = $sec['id']; $hidden = $sec['toggle'] && in_array($sec['toggle'], $hide, true); ?>
  <details class="sx card" id="s-<?= h($sid) ?>" <?= $open === $sid ? 'open' : '' ?>>
    <summary><span><?= h($sec['title']) ?></span><?= $hidden ? '<em class="off">ukryta</em>' : '' ?><span class="hed">Edytuj</span></summary>
    <?php if ($sec['hint']): ?><p class="hint"><?= h($sec['hint']) ?></p><?php endif; ?>
    <?php foreach ($sec['f'] as $f): if ($f[0] !== 'img') continue; $v = site_val($S, $f); $fid = 'im-' . $f[1]; ?>
      <form method="post" class="imgf">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="site_img"><input type="hidden" name="sec" value="<?= h($sid) ?>"><input type="hidden" name="key" value="<?= h($f[1]) ?>"><input type="hidden" name="back" value="?t=site&amp;s=<?= h($sid) ?>"><input type="hidden" name="data" id="<?= $fid ?>">
        <img src="../<?= h($v) ?>" alt="" class="<?= ($f[4] ?? '') === 'sq' ? 'sq' : '' ?>">
        <div><b><?= h($f[2]) ?></b><?php if (!empty($f[3])): ?><small><?= h($f[3]) ?></small><?php endif; ?>
          <label class="filebtn btn sm ghost">📷 Zmień zdjęcie<input type="file" accept="image/*" hidden class="sifile" data-target="<?= $fid ?>" data-sq="<?= ($f[4] ?? '') === 'sq' ? 1 : 0 ?>"></label></div>
      </form>
    <?php endforeach; ?>
    <form method="post" class="sform">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="site_save"><input type="hidden" name="sec" value="<?= h($sid) ?>"><input type="hidden" name="back" value="?t=site&amp;s=<?= h($sid) ?>">
      <?php if ($sec['toggle']): ?>
        <label class="sw"><input type="checkbox" name="show" value="1" <?= $hidden ? '' : 'checked' ?>><span>Pokazuj tę sekcję na stronie</span></label>
      <?php endif; ?>
      <?php foreach ($sec['f'] as $f): [$type, $key, $lab] = $f; $hint = $f[3] ?? ''; $v = site_val($S, $f);
        if ($type === 'img') continue;
        if ($type === 'rows'): $cols = $f[4]; ?>
        <div class="fld"><span class="fl"><?= h($lab) ?></span><?php if ($hint): ?><small class="fh"><?= h($hint) ?></small><?php endif; ?>
          <div class="lrows" data-key="<?= h($key) ?>">
            <?php foreach (array_merge($v, [null]) as $i => $row): $tpl = $row === null; ?>
            <?= $tpl ? '<template>' : '' ?><div class="lrow">
              <?php foreach ($cols as $c): $cv = $row[$c[0]] ?? ''; $nm = 'L[' . h($key) . '][' . h($c[0]) . '][]'; ?>
                <label><?= h($c[1]) ?>
                <?php if (($c[2] ?? '') === 'icon'): ?><select name="<?= $nm ?>"><?php foreach (SITE_ICONS as $ik => $il): ?><option value="<?= h($ik) ?>" <?= $cv === $ik ? 'selected' : '' ?>><?= h($il) ?></option><?php endforeach; ?></select>
                <?php elseif (($c[2] ?? '') === 'ta'): ?><textarea name="<?= $nm ?>" rows="3"><?= h($cv) ?></textarea>
                <?php else: ?><input name="<?= $nm ?>" value="<?= h($cv) ?>"><?php endif; ?></label>
              <?php endforeach; ?>
              <span class="rb"><button type="button" class="mv" data-d="up" aria-label="W górę">↑</button><button type="button" class="mv" data-d="dn" aria-label="W dół">↓</button><button type="button" class="rm" aria-label="Usuń">✕</button></span>
            </div><?= $tpl ? '</template>' : '' ?>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn sm ghost addrow">+ Dodaj</button>
        </div>
        <?php else: $nm = $type === 'lines' ? 'F[lines][' . h($key) . ']' : 'F[' . h($type) . '][' . h($key) . ']'; ?>
        <label class="fld"><span class="fl"><?= h($lab) ?></span><?php if ($hint): ?><small class="fh"><?= h($hint) ?></small><?php endif; ?>
          <?php if ($type === 'ta' || $type === 'lines' || ($type === 'seo' && $key === 'desc') || $key === 'foot_p'): $rows = is_int($f[4] ?? null) ? $f[4] : ($type === 'lines' ? max(4, min(12, substr_count((string)$v, "\n") + 2)) : 3); ?>
            <textarea name="<?= $nm ?>" rows="<?= $rows ?>"><?= h($v) ?></textarea>
          <?php else: ?><input name="<?= $nm ?>" value="<?= h($v) ?>" <?= $key === 'phone' ? 'inputmode="tel"' : '' ?>><?php endif; ?>
        </label>
        <?php endif; ?>
      <?php endforeach; ?>
      <button class="btn full" style="width:100%;margin-top:6px">Zapisz</button>
    </form>
    <details class="confirm" style="margin-top:10px"><summary class="link">Przywróć oryginalne treści tej sekcji</summary>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="site_reset"><input type="hidden" name="sec" value="<?= h($sid) ?>"><input type="hidden" name="back" value="?t=site&amp;s=<?= h($sid) ?>"><button class="btn sm danger">Tak, przywróć oryginał</button></form></details>
  </details>
  <?php endforeach; ?>
  <script>
  (function(){
    const op=document.querySelector('details.sx[open]');if(op)setTimeout(()=>op.scrollIntoView({block:'start'}),50);
    document.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;
      if(b.classList.contains('addrow')){const box=b.previousElementSibling,t=box.querySelector('template');box.insertBefore(t.content.cloneNode(true),t);const r=t.previousElementSibling;const i=r&&r.querySelector('input,textarea');i&&i.focus();return}
      const r=b.closest('.lrow');if(!r)return;
      if(b.classList.contains('rm'))r.remove();
      else if(b.dataset.d==='up'&&r.previousElementSibling&&r.previousElementSibling.classList.contains('lrow'))r.parentNode.insertBefore(r,r.previousElementSibling);
      else if(b.dataset.d==='dn'&&r.nextElementSibling&&r.nextElementSibling.classList.contains('lrow'))r.parentNode.insertBefore(r.nextElementSibling,r);});
    /* zdjęcie: zmniejszenie w telefonie przed wysłaniem (kwadrat dla logo) */
    document.addEventListener('change',e=>{const f=e.target.closest('.sifile');if(!f||!f.files[0])return;
      const u=URL.createObjectURL(f.files[0]),im=new Image();
      im.onload=()=>{const c=document.createElement('canvas'),x=c.getContext('2d');let sx=0,sy=0,sw=im.width,sh=im.height;
        if(f.dataset.sq==='1'){const m=Math.min(sw,sh);sx=(sw-m)/2;sy=(sh-m)/2;sw=sh=m}
        const k=Math.min(1,(f.dataset.sq==='1'?900:1800)/Math.max(sw,sh));c.width=Math.round(sw*k);c.height=Math.round(sh*k);
        x.fillStyle='#fff';x.fillRect(0,0,c.width,c.height);x.drawImage(im,sx,sy,sw,sh,0,0,c.width,c.height);URL.revokeObjectURL(u);
        document.getElementById(f.dataset.target).value=c.toDataURL('image/jpeg',0.86);
        const fm=f.closest('form');fm.querySelector('img').src=c.toDataURL('image/jpeg',0.5);f.parentNode.firstChild.textContent='Zapisywanie… ';fm.submit()};
      im.onerror=()=>alert('Nie mogę odczytać tego zdjęcia. Wybierz plik JPG lub PNG.');im.src=u});
  })();
  </script>
<?php }

/* ---------- USTAWIENIA ---------- */
function settings_handle(string $a): ?string
{
    if ($a === 'set_pass') {
        $hash = setting('admin_hash');
        if (!$hash || !password_verify((string)($_POST['old'] ?? ''), $hash)) return 'Obecne hasło jest nieprawidłowe.';
        $p = (string)($_POST['p'] ?? '');
        if (mb_strlen($p) < 8) return 'Nowe hasło musi mieć co najmniej 8 znaków.';
        if ($p !== (string)($_POST['p2'] ?? '')) return 'Nowe hasła nie są takie same.';
        setting('admin_hash', password_hash($p, PASSWORD_DEFAULT));
        return 'Hasło zmienione.';
    }
    if ($a === 'set_cfg') {
        $o = json_decode((string)(setting('c_cfg') ?? ''), true); if (!is_array($o)) $o = [];
        $mail = trim((string)($_POST['notify_email'] ?? ''));
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) return 'Adres e-mail wygląda na niepoprawny – nic nie zapisano.';
        $ical = trim((string)($_POST['google_ical_url'] ?? ''));
        if ($ical !== '' && !preg_match('#^(https?|webcal)://\S+$#i', $ical)) return 'Adres kalendarza musi zaczynać się od https:// lub webcal:// – nic nie zapisano.';
        $o['notify_email'] = $mail; $o['google_ical_url'] = $ical;
        $o['days_ahead'] = max(7, min(365, (int)($_POST['days_ahead'] ?? 90)));
        $o['min_notice_minutes'] = max(0, min(72, (int)($_POST['min_notice_h'] ?? 2))) * 60;
        $o['manage_hours'] = max(0, min(168, (int)($_POST['manage_hours'] ?? 24)));
        $o['slot_minutes'] = max(15, min(480, (int)($_POST['slot_minutes'] ?? 120)));
        setting('c_cfg', json_encode($o, JSON_UNESCAPED_UNICODE));
        return 'Ustawienia zapisane.';
    }
    return null;
}

function settings_view(): void
{ ?>
  <section class="card">
    <h2>Rezerwacje online</h2>
    <form method="post" class="grid">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="set_cfg"><input type="hidden" name="back" value="?t=settings">
      <label class="full">E-mail do powiadomień o rezerwacjach<input type="email" name="notify_email" value="<?= h(cfg('notify_email')) ?>"></label>
      <label>Ile dni do przodu można rezerwować<input type="number" name="days_ahead" min="7" max="365" value="<?= (int)cfg('days_ahead') ?>"></label>
      <label>Najwcześniej ile godzin przed wizytą<input type="number" name="min_notice_h" min="0" max="72" value="<?= (int)round((int)cfg('min_notice_minutes') / 60) ?>"></label>
      <label>Klient może sam zmienić / odwołać wizytę do (godzin przed)<input type="number" name="manage_hours" min="0" max="168" value="<?= (int)cfg('manage_hours') ?>"></label>
      <label>Ile trwa jedna wizyta (minuty)<input type="number" name="slot_minutes" min="15" max="480" step="15" value="<?= (int)cfg('slot_minutes') ?>"></label>
      <label class="full">Kalendarz Google / iPhone (opcjonalnie)<small class="linkhint">Wklej <b>tajny adres w formacie iCal</b> z Kalendarza Google (Ustawienia kalendarza → Integracja) albo link webcal:// z iPhone'a. Każde wydarzenie w tym kalendarzu zajmie termin na stronie. Puste = wyłączone.</small><input name="google_ical_url" value="<?= h(cfg('google_ical_url')) ?>" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"></label>
      <button class="btn full">Zapisz ustawienia</button>
    </form>
  </section>
  <section class="card">
    <h2>Zmiana hasła do panelu</h2>
    <form method="post" class="grid">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="a" value="set_pass"><input type="hidden" name="back" value="?t=settings">
      <label class="full">Obecne hasło<input type="password" name="old" required autocomplete="current-password"></label>
      <label>Nowe hasło (min. 8 znaków)<input type="password" name="p" required autocomplete="new-password"></label>
      <label>Powtórz nowe hasło<input type="password" name="p2" required autocomplete="new-password"></label>
      <button class="btn full">Zmień hasło</button>
    </form>
  </section>
<?php }
