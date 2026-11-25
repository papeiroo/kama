<?php
/* Dokumenty prawne strony: polityka prywatności (RODO + cookies) i regulamin rezerwacji online (usługi drogą elektroniczną).
   Dane firmy pochodzą z panelu → Treści strony → „Dane salonu i kontakt”. Plik dołączany przez polityka-prywatnosci.php i regulamin-rezerwacji.php. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

function legal_data(): array
{
    $s = null;
    try { if (is_configured()) $s = site_get(); } catch (Throwable $e) {}
    if (!$s) $s = site_default();
    $c = $s['contact'] ?? [];
    $brand = trim((string)($s['t']['brand'] ?? 'KamaGrooming')) ?: 'KamaGrooming';
    $salon = trim(implode(', ', array_filter([$c['street'] ?? '', $c['city'] ?? ''])));
    $hq = trim((string)($c['hq'] ?? '')) ?: $salon;
    $mail = trim((string)($c['email'] ?? ''));
    if ($mail === '') { try { $mail = is_configured() ? (string)cfg('notify_email') : ''; } catch (Throwable $e) { $mail = ''; } }
    $b = ['from' => '15:00', 'max' => 1]; $mh = 24; $km = 12; $site = '';
    try { if (is_configured()) { $b = big_rules(); $mh = manage_hours(); $km = (int)(cfg('keep_months') ?? 12); $site = (string)cfg('site_url'); } } catch (Throwable $e) {}
    return [
        'brand' => $brand, 'company' => trim((string)($c['company'] ?? '')) ?: $brand, 'owner' => trim((string)($c['owner'] ?? '')),
        'nip' => trim((string)($c['nip'] ?? '')), 'regon' => trim((string)($c['regon'] ?? '')), 'hq' => $hq, 'salon' => $salon,
        'phone' => trim((string)($c['phone'] ?? '')), 'email' => $mail, 'fb' => trim((string)($c['fb'] ?? '')),
        'big' => $b, 'manage_h' => $mh, 'keep_m' => $km, 'site' => $site ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? '')),
        'logo' => (string)($s['img']['icon'] ?? 'ikony/icon-192.png'), 'date' => '15 listopada 2026',
    ];
}

function legal_admin_line(array $d, callable $e): string
{
    $x = '<b>' . $e($d['company']) . '</b>';
    if ($d['owner'] !== '' && mb_stripos($d['company'], $d['owner']) === false) $x .= ', prowadzona przez ' . $e($d['owner']);
    $x .= ', ' . $e($d['hq']);
    if ($d['nip'] !== '') $x .= ', NIP ' . $e($d['nip']);
    if ($d['regon'] !== '') $x .= ', REGON ' . $e($d['regon']);
    return $x;
}

function legal_contact(array $d, callable $e): string
{
    $p = [];
    if ($d['email'] !== '') $p[] = 'e-mail: <a href="mailto:' . $e($d['email']) . '">' . $e($d['email']) . '</a>';
    if ($d['phone'] !== '') $p[] = 'telefon: <a href="tel:+48' . $e(substr(preg_replace('/\D/', '', $d['phone']), -9)) . '">' . $e($d['phone']) . '</a>';
    $p[] = 'listownie lub osobiście: ' . $e($d['salon']);
    return implode(', ', $p);
}

function legal_page(string $which): void
{
    $d = legal_data();
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $title = $which === 'privacy' ? 'Polityka prywatności i plików cookies' : 'Regulamin rezerwacji online';
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($title . ' – ' . $d['brand']) ?></title><meta name="robots" content="noindex,follow"><link rel="icon" href="ikony/favicon-32.png"><link rel="stylesheet" href="fonts/fonts.css">
<style>
body{margin:0;background:#faf6ee;color:#2d241b;font:300 1.02rem/1.75 Jost,system-ui,sans-serif}
.top{display:flex;align-items:center;gap:.8rem;max-width:860px;margin:0 auto;padding:1.4rem 1.4rem .6rem}.top img{width:44px;height:44px;border-radius:50%;object-fit:cover}
.top a{display:flex;align-items:center;gap:.7rem;text-decoration:none;color:#9c7638;font:500 1.5rem 'EB Garamond',Georgia,serif}
main{max-width:860px;margin:0 auto;padding:0 1.4rem 4rem}
h1{font:500 clamp(2rem,6vw,2.8rem)/1.15 'EB Garamond',Georgia,serif;color:#9c7638;margin:1.2rem 0 .3rem}
.meta{color:#76664f;font-size:.9rem;margin:0 0 2rem}
h2{font:500 1.45rem/1.3 'EB Garamond',Georgia,serif;color:#2d241b;margin:2.2rem 0 .6rem;padding-top:1rem;border-top:1px solid rgba(184,145,79,.3)}
a{color:#9c7638}b{font-weight:500}ul,ol{padding-left:1.3rem}li{margin:.25rem 0}
.box{background:#fffdf8;border:1px solid rgba(184,145,79,.35);border-radius:16px;padding:1rem 1.2rem;margin:1rem 0}
.back{display:inline-block;margin-top:2.5rem;padding:.8rem 1.4rem;border-radius:99px;background:linear-gradient(120deg,#ecd590,#cda55c 52%,#ab823f);color:#2b2015;text-decoration:none;font-weight:500;font-size:.9rem;letter-spacing:.08em;text-transform:uppercase}
</style></head><body>
<div class="top"><a href="./"><img src="<?= $e($d['logo']) ?>" alt=""><?= $e($d['brand']) ?></a></div>
<main>
<h1><?= $e($title) ?></h1>
<p class="meta">Obowiązuje od <?= $e($d['date']) ?> · dotyczy strony <?= $e(preg_replace('#^https?://#', '', $d['site'])) ?></p>
<?php if ($which === 'privacy'): ?>

<div class="box">W skrócie: zbieramy tylko dane potrzebne do umówienia wizyty Twojego psa, nie sprzedajemy ich, nie wysyłamy reklam, nie używamy ciasteczek reklamowych ani statystycznych. Rezerwacje kasujemy automatycznie po <?= (int)$d['keep_m'] ?> miesiącach.</div>

<h2>1. Kto jest administratorem Twoich danych</h2>
<p>Administratorem danych osobowych jest <?= legal_admin_line($d, $e) ?> (dalej: „Salon”, „my”). Salon <?= $e($d['brand']) ?> mieści się pod adresem: <?= $e($d['salon']) ?>.</p>
<p>W każdej sprawie dotyczącej danych osobowych skontaktujesz się z nami: <?= legal_contact($d, $e) ?>. Nie powołaliśmy inspektora ochrony danych – nie mamy takiego obowiązku.</p>

<h2>2. Jakie dane zbieramy i skąd</h2>
<ul>
<li><b>Rezerwacja online</b> – imię (i nazwisko), numer telefonu, opcjonalnie adres e-mail, informacje o psie (imię, rasa, waga, wielkość), wybrana usługa i termin, opcjonalnie zdjęcie psa. Zapisujemy też datę wysłania rezerwacji i adres IP urządzenia (ochrona przed nadużyciami).</li>
<li><b>Kontakt telefoniczny, SMS, e-mail, WhatsApp lub Messenger</b> – dane, które sam(a) nam przekażesz w rozmowie lub wiadomości.</li>
<li><b>Wizyta w salonie</b> – informacje o zdrowiu i zachowaniu psa, które nam przekażesz, żeby pielęgnacja była bezpieczna. Dotyczą zwierzęcia, ale zapisujemy je razem z Twoimi danymi kontaktowymi.</li>
<li><b>Opinie</b> – jeśli wystawisz nam opinię (np. na Facebooku), możemy ją pokazać na stronie z Twoim imieniem i pierwszą literą nazwiska. Jeśli nie chcesz, napisz – usuniemy ją.</li>
</ul>

<h2>3. Po co i na jakiej podstawie</h2>
<ul>
<li>przyjęcie, potwierdzenie, zmiana lub odwołanie rezerwacji oraz wykonanie usługi pielęgnacji psa – <b>art. 6 ust. 1 lit. b RODO</b> (działania przed zawarciem umowy i jej wykonanie);</li>
<li>kontakt w sprawie wizyty (telefon, SMS, e-mail z potwierdzeniem) – art. 6 ust. 1 lit. b RODO;</li>
<li>wystawienie rachunku lub faktury i prowadzenie księgowości – <b>art. 6 ust. 1 lit. c RODO</b> (obowiązek prawny);</li>
<li>ochrona strony przed spamem i nadużyciami (adres IP, limit rezerwacji) oraz ewentualne dochodzenie roszczeń lub obrona przed nimi – <b>art. 6 ust. 1 lit. f RODO</b> (nasz prawnie uzasadniony interes);</li>
<li>pokazanie opinii klientów na stronie – art. 6 ust. 1 lit. f RODO (prezentowanie salonu);</li>
<li>wyświetlenie mapy Google – <b>art. 6 ust. 1 lit. a RODO</b> (Twoja zgoda wyrażona kliknięciem „Pokaż mapę”).</li>
</ul>
<p>Podanie imienia, numeru telefonu i informacji o psie jest dobrowolne, ale bez nich nie przyjmiemy rezerwacji online – możesz wtedy umówić się telefonicznie. E-mail i zdjęcie psa są całkowicie opcjonalne. Nie podejmujemy decyzji w sposób zautomatyzowany i nie profilujemy klientów.</p>

<h2>4. Jak długo przechowujemy dane</h2>
<ul>
<li>rezerwacje (wraz z adresem IP i zdjęciem psa) – <b><?= (int)$d['keep_m'] ?> miesięcy od daty wizyty</b>, potem system usuwa je automatycznie; wcześniej, jeśli o to poprosisz i nie ma przeszkód prawnych;</li>
<li>dokumenty księgowe – przez okres wymagany przepisami podatkowymi (co do zasady 5 lat od końca roku podatkowego);</li>
<li>korespondencja – do zakończenia sprawy, a jeśli może być potrzebna do obrony przed roszczeniami – do ich przedawnienia;</li>
<li>opinie – dopóki są widoczne na stronie lub do Twojego sprzeciwu.</li>
</ul>

<h2>5. Komu przekazujemy dane</h2>
<p>Nie sprzedajemy ani nie udostępniamy danych w celach marketingowych. Dostęp do nich mogą mieć wyłącznie firmy, które pomagają nam prowadzić stronę, na podstawie umów powierzenia przetwarzania:</p>
<ul>
<li>dostawca hostingu, serwera i poczty e-mail, na którym działa strona i system rezerwacji (serwery w Polsce);</li>
<li>biuro rachunkowe – jeśli wystawiamy dokument księgowy;</li>
<li>operatorzy telekomunikacyjni – przy połączeniach i SMS-ach.</li>
</ul>
<p>Gdy klikniesz link do Facebooka, Messengera lub WhatsAppa albo włączysz mapę Google, przechodzisz do usług Meta Platforms Ireland Ltd. lub Google Ireland Ltd., które są odrębnymi administratorami danych i mogą przekazywać dane do USA (na podstawie decyzji Komisji Europejskiej o odpowiednim stopniu ochrony – EU-US Data Privacy Framework). Zasady tych firm znajdziesz w ich politykach prywatności.</p>

<h2>6. Twoje prawa</h2>
<p>Masz prawo do: dostępu do swoich danych i otrzymania ich kopii, sprostowania, usunięcia, ograniczenia przetwarzania, przeniesienia danych, wniesienia sprzeciwu wobec przetwarzania opartego na naszym prawnie uzasadnionym interesie, a także do cofnięcia zgody (np. na mapę – usuwając dane strony w przeglądarce) w dowolnym momencie, bez wpływu na zgodność z prawem wcześniejszego przetwarzania.</p>
<p>Żeby skorzystać z praw, napisz lub zadzwoń (dane w punkcie 1). Odpowiemy w ciągu miesiąca. Masz też prawo wnieść skargę do <b>Prezesa Urzędu Ochrony Danych Osobowych</b>, ul. Stawki 2, 00-193 Warszawa (uodo.gov.pl).</p>

<h2>7. Pliki cookies i pamięć przeglądarki</h2>
<p>Strona <b>nie używa</b> ciasteczek reklamowych, statystycznych ani śledzących i nie korzysta z Google Analytics ani pikseli reklamowych. Czcionki są wczytywane z naszego serwera, a nie od Google. Korzystamy wyłącznie z mechanizmów niezbędnych do działania funkcji, o które prosisz:</p>
<ul>
<li><b>pamięć przeglądarki (localStorage)</b> – zapamiętuje Twoje rezerwacje (sekcja „Twoje rezerwacje”, żeby można było je zmienić lub odwołać) oraz to, czy włączyłeś(-aś) mapę;</li>
<li><b>pamięć podręczna aplikacji (service worker)</b> – przyspiesza wczytywanie strony i pozwala zainstalować ją na telefonie;</li>
<li><b>ciasteczko sesyjne panelu</b> – tylko dla obsługi salonu po zalogowaniu do panelu administracyjnego.</li>
</ul>
<p>Mapa Google wczytuje się dopiero po kliknięciu „Pokaż mapę”. Wtedy Google może zapisać na Twoim urządzeniu własne pliki cookies. Wszystkie te dane możesz w każdej chwili usunąć w ustawieniach przeglądarki („Wyczyść dane witryny”) – strona nadal będzie działać, ale zniknie lista „Twoje rezerwacje” na tym urządzeniu.</p>

<h2>8. Bezpieczeństwo</h2>
<p>Strona działa w szyfrowanym połączeniu HTTPS. Dostęp do rezerwacji ma tylko obsługa salonu po zalogowaniu hasłem, a zdjęcia psów nie są publicznie dostępne. Stosujemy limity rezerwacji i blokadę logowania po nieudanych próbach.</p>

<h2>9. Zmiany polityki</h2>
<p>Politykę możemy aktualizować, np. gdy zmienią się przepisy lub funkcje strony. Aktualna wersja jest zawsze dostępna na tej stronie wraz z datą obowiązywania.</p>

<?php else: $b = $d['big']; ?>

<h2>§ 1. Postanowienia ogólne</h2>
<ol>
<li>Regulamin określa zasady korzystania z rezerwacji wizyt online na stronie <?= $e(preg_replace('#^https?://#', '', $d['site'])) ?> (dalej: „Strona”) i jest regulaminem, o którym mowa w art. 8 ustawy z dnia 18 lipca 2002 r. o świadczeniu usług drogą elektroniczną.</li>
<li>Usługodawcą jest <?= legal_admin_line($d, $e) ?>, prowadzący salon pielęgnacji psów <?= $e($d['brand']) ?> pod adresem: <?= $e($d['salon']) ?> (dalej: „Salon”). Kontakt: <?= legal_contact($d, $e) ?>.</li>
<li>Klient – osoba, która korzysta ze Strony, w szczególności rezerwuje wizytę dla swojego psa. Klientem może być osoba pełnoletnia; osoba niepełnoletnia może zarezerwować wizytę za zgodą rodzica lub opiekuna.</li>
<li>Korzystanie ze Strony i rezerwacji online jest bezpłatne. Przed rezerwacją należy zapoznać się z Regulaminem, Regulaminem salonu (sekcja „Regulamin” na Stronie) oraz <a href="polityka-prywatnosci.php">Polityką prywatności</a>.</li>
</ol>

<h2>§ 2. Usługi świadczone na Stronie</h2>
<ol>
<li>Salon świadczy drogą elektroniczną następujące usługi: a) przeglądanie treści Strony (oferta, cennik, realizacje), b) formularz rezerwacji terminu wizyty, c) „Twoje rezerwacje” – podgląd, zmiana terminu i odwołanie rezerwacji, d) przesłanie potwierdzenia rezerwacji na e-mail (jeśli Klient go poda).</li>
<li>Umowa o świadczenie usługi z pkt 1 lit. a zawierana jest z chwilą wejścia na Stronę, a z pkt 1 lit. b–d – z chwilą wysłania formularza rezerwacji; rozwiązuje się z chwilą opuszczenia Strony lub zakończenia / odwołania rezerwacji. Klient może w każdej chwili zrezygnować z usług, odwołując rezerwację.</li>
<li>Wymagania techniczne: urządzenie z dostępem do internetu i aktualną przeglądarką internetową z włączoną obsługą JavaScript; do otrzymania potwierdzenia e-mail – aktywne konto poczty elektronicznej.</li>
<li>Korzystanie z internetu wiąże się z typowymi zagrożeniami (np. złośliwe oprogramowanie, przechwycenie danych w niezabezpieczonej sieci). Strona korzysta z szyfrowanego połączenia HTTPS; Klient powinien używać aktualnego oprogramowania i zaufanych sieci.</li>
<li>Klienta obowiązuje zakaz dostarczania treści o charakterze bezprawnym, w tym podawania cudzych danych bez zgody oraz przesyłania zdjęć niezwiązanych z psem lub naruszających prawa osób trzecich.</li>
</ol>

<h2>§ 3. Rezerwacja wizyty</h2>
<ol>
<li>Aby zarezerwować wizytę, Klient wybiera w kalendarzu dzień i wolną godzinę, zaznacza wielkość psa, podaje imię, numer telefonu i informacje o psie, opcjonalnie e-mail, usługę i zdjęcie psa, akceptuje Regulamin i wysyła formularz.</li>
<li>Wysłana rezerwacja ma status „do potwierdzenia”. Salon potwierdza ją telefonicznie, SMS-em lub e-mailem i wtedy ustala z Klientem zakres usługi oraz orientacyjną cenę według cennika. Ostateczna cena zależy od wielkości psa, rodzaju i stanu sierści.</li>
<li><?php if ($b['max'] > 0): ?>Ze względu na organizację pracy wizyty <b>dużych psów</b> są możliwe wyłącznie od godz. <?= $e($b['from']) ?>, w liczbie maksymalnie <?= (int)$b['max'] ?> dziennie. Jeśli limit na dany dzień jest wyczerpany, formularz poprosi o wybór innego terminu.<?php else: ?>Wizyty <b>dużych psów</b> są możliwe wyłącznie od godz. <?= $e($b['from']) ?>.<?php endif; ?> Podanie nieprawdziwej wielkości psa może skutkować koniecznością zmiany terminu.</li>
<li>Salon może odmówić przyjęcia rezerwacji lub ją odwołać z ważnych przyczyn (np. choroba, awaria, zdarzenie losowe, podejrzenie fałszywej rezerwacji), niezwłocznie informując o tym Klienta i – w miarę możliwości – proponując inny termin.</li>
<li>Rezerwacja online jest bezpłatna i nie wymaga przedpłaty. Umowa o wykonanie usługi pielęgnacji psa zostaje zawarta w Salonie, w chwili przyjęcia psa na wizytę; do tej chwili Klient może bez żadnych kosztów odwołać rezerwację. Z tego względu rezerwacja nie jest umową zawieraną na odległość w rozumieniu ustawy o prawach konsumenta.</li>
<li>W celu ochrony przed nadużyciami system ogranicza liczbę rezerwacji wysyłanych w krótkim czasie i liczbę jednoczesnych przyszłych rezerwacji na jeden numer telefonu. W takim przypadku wizytę można umówić telefonicznie.</li>
</ol>

<h2>§ 4. Zmiana i odwołanie rezerwacji</h2>
<ol>
<li>Klient może zmienić termin lub odwołać rezerwację w sekcji „Twoje rezerwacje” na Stronie (na urządzeniu, z którego rezerwował, lub przez link z e-maila) najpóźniej <b><?= (int)$d['manage_h'] ?> godz. przed wizytą</b>. Później – telefonicznie lub SMS-em.</li>
<li>Zmieniony termin wymaga ponownego potwierdzenia przez Salon.</li>
<li>Zasady dotyczące spóźnień, przygotowania psa i przebiegu wizyty określa Regulamin salonu.</li>
</ol>

<h2>§ 5. Reklamacje</h2>
<ol>
<li>Reklamacje dotyczące działania Strony i rezerwacji online można składać e-mailem, telefonicznie, SMS-em lub osobiście (dane w § 1). Reklamacja powinna zawierać dane kontaktowe i opis problemu.</li>
<li>Salon rozpatruje reklamację w terminie 14 dni od jej otrzymania i informuje Klienta o wyniku w sposób, w jaki ją złożył, chyba że Klient wskaże inny.</li>
<li>Reklamacje dotyczące samej usługi pielęgnacji psa rozpatrywane są na zasadach przewidzianych w przepisach prawa, w szczególności Kodeksu cywilnego i ustawy o prawach konsumenta.</li>
<li>Klient będący konsumentem może skorzystać z pozasądowych sposobów rozwiązywania sporów, m.in. z pomocy miejskiego lub powiatowego rzecznika konsumentów albo Wojewódzkiego Inspektoratu Inspekcji Handlowej. Więcej informacji: uokik.gov.pl.</li>
</ol>

<h2>§ 6. Dane osobowe</h2>
<p>Zasady przetwarzania danych osobowych i wykorzystania pamięci przeglądarki opisuje <a href="polityka-prywatnosci.php">Polityka prywatności i plików cookies</a>.</p>

<h2>§ 7. Postanowienia końcowe</h2>
<ol>
<li>Regulamin jest dostępny bezpłatnie na Stronie w formie, która umożliwia jego pobranie, utrwalenie i wydrukowanie.</li>
<li>Salon może zmienić Regulamin z ważnych przyczyn (np. zmiana przepisów, zmiana funkcji Strony). Zmiany nie dotyczą rezerwacji złożonych przed ich wejściem w życie.</li>
<li>Regulamin nie wyłącza ani nie ogranicza praw konsumentów wynikających z bezwzględnie obowiązujących przepisów. W sprawach nieuregulowanych stosuje się prawo polskie.</li>
<li>Regulamin obowiązuje od <?= $e($d['date']) ?>.</li>
</ol>

<?php endif; ?>
<a class="back" href="./">← Wróć na stronę</a>
</main></body></html>
<?php }
