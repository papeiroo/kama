<?php
declare(strict_types=1);
/* INSTALATOR (jednorazowy): 1) dane bazy z panelu hostingu → zapis api/config.php, 2) tabele, 3) hasło do panelu.
   Po zakończeniu blokuje się sam – nie trzeba go usuwać. */
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$bare = preg_replace('/^www\./i', '', preg_replace('/:\d+$/', '', $host));
$msg = ''; $step = 'db'; $done = false;

/* KROK 1: dane bazy danych */
if (!is_configured()) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $dh = trim((string)($_POST['dh'] ?? 'localhost')) ?: 'localhost';
        $dn = trim((string)($_POST['dn'] ?? '')); $du = trim((string)($_POST['du'] ?? '')); $dp = (string)($_POST['dp'] ?? '');
        $mail = trim((string)($_POST['mail'] ?? ''));
        if ($dn === '' || $du === '') $msg = 'Wpisz nazwę bazy i użytkownika.';
        elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) $msg = 'Wpisz poprawny adres e-mail do powiadomień.';
        else {
            try {
                new PDO('mysql:host=' . $dh . ';dbname=' . $dn . ';charset=utf8mb4', $du, $dp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
                $tpl = (string)file_get_contents(__DIR__ . '/config.example.php');
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                $rep = [
                    "'host' => 'localhost'" => "'host' => " . var_export($dh, true),
                    "'NAZWA_BAZY'" => var_export($dn, true), "'UZYTKOWNIK_BAZY'" => var_export($du, true), "'HASLO_BAZY'" => var_export($dp, true),
                    "'notify_email' => 'kontakt@kamagrooming.pl'" => "'notify_email' => " . var_export($mail, true),
                    "'from_email'   => 'kontakt@kamagrooming.pl'" => "'from_email'   => " . var_export('rezerwacje@' . $bare, true),
                    "'site_url'     => 'https://www.kamagrooming.pl'" => "'site_url'     => " . var_export(($https ? 'https://' : 'http://') . $host, true),
                ];
                $cfg = strtr($tpl, $rep);
                if (@file_put_contents(__DIR__ . '/config.php', $cfg) === false) $msg = 'Nie mogę zapisać pliku api/config.php – nadaj folderowi „api” prawo zapisu (755) albo wgraj config.php ręcznie.';
                else { if (function_exists('opcache_invalidate')) @opcache_invalidate(__DIR__ . '/config.php', true); header('Location: install.php'); exit; }
            } catch (Throwable $ex) { $msg = 'Nie udało się połączyć z bazą. Sprawdź dane (często nazwa bazy i użytkownika mają przedrostek konta, np. „srv12345_kama”). Szczegóły: ' . $ex->getMessage(); }
        }
    }
} else {
    /* KROK 2: tabele, KROK 3: hasło */
    $step = 'pass';
    try {
        if (setting_safe('admin_hash') !== null) { try { setting('installed', '1'); } catch (Throwable $ex) {} $done = true; }
        else {
            $sqlite = is_sqlite();
            $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            db()->exec("CREATE TABLE IF NOT EXISTS bookings (
                id $id, bdate VARCHAR(10) NOT NULL, btime VARCHAR(5) NOT NULL, name VARCHAR(120) NOT NULL,
                phone VARCHAR(20) NULL, email VARCHAR(160) NULL, service VARCHAR(160) NULL, pet VARCHAR(160) NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending', slot_key VARCHAR(20) NULL UNIQUE, source VARCHAR(10) NOT NULL DEFAULT 'www',
                ip VARCHAR(45) NULL, created_at VARCHAR(20) NOT NULL,
                mtoken VARCHAR(64) NULL, chg VARCHAR(10) NULL, chg_from VARCHAR(20) NULL, chg_at VARCHAR(20) NULL, chg_seen INT NOT NULL DEFAULT 1
            )$tail");
            db()->exec("CREATE TABLE IF NOT EXISTS blocks (id $id, bdate VARCHAR(10) NOT NULL, btime VARCHAR(5) NULL, note VARCHAR(160) NULL, created_at VARCHAR(20) NOT NULL)$tail");
            db()->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v MEDIUMTEXT NULL)$tail");
            try { db()->exec('CREATE INDEX idx_bdate ON bookings (bdate)'); } catch (Throwable $ex) {}
            setting('schema_manage', '2');
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $p = (string)($_POST['p'] ?? '');
                if (mb_strlen($p) < 8) $msg = 'Hasło musi mieć co najmniej 8 znaków.';
                elseif ($p !== (string)($_POST['p2'] ?? '')) $msg = 'Hasła nie są takie same.';
                else { setting('admin_hash', password_hash($p, PASSWORD_DEFAULT)); setting('installed', '1'); $done = true; }
            }
        }
    } catch (Throwable $ex) { $msg = 'Błąd bazy danych: ' . $ex->getMessage(); }
}
function setting_safe(string $k): ?string { try { return setting($k); } catch (Throwable $e) { return null; } }
?><!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Instalacja – KamaGrooming</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:460px;margin:40px auto;padding:0 18px;background:#faf6ee;color:#2b2218}h1{font-weight:500;color:#9a7536;margin-bottom:4px}
.st{color:#8a7a62;font-size:.9rem;margin:0 0 18px}form{display:grid;gap:12px}label{display:grid;gap:4px;font-size:.88rem;color:#6b5a42}input{padding:11px 12px;border:1px solid #d8c7a5;border-radius:10px;font-size:16px;background:#fff}
button{padding:13px;background:linear-gradient(120deg,#ecd590,#cda55c 52%,#ab823f);border:0;border-radius:99px;font-weight:600;font-size:1rem;cursor:pointer}.err{background:#fbe3e3;color:#8a2d2d;padding:10px 12px;border-radius:10px;font-size:.92rem}.ok{background:#e6f3e6;padding:14px;border-radius:12px}small{color:#8a7a62}</style></head>
<body><h1>Instalacja strony</h1>
<?php if ($done): ?>
  <p class="st">Krok 3 z 3 · gotowe</p>
  <div class="ok"><p style="margin-top:0">✅ <b>Strona jest zainstalowana.</b></p><p>Panel salonu: <a href="../panel/"><?= $e(($host) . '/panel/') ?></a><br>Zaloguj się ustawionym hasłem. Wszystkie treści, zdjęcia, ceny i godziny zmienisz w panelu.</p><p style="margin-bottom:0"><small>Instalator jest już zablokowany – nie trzeba go usuwać.</small></p></div>
<?php elseif ($step === 'db'): ?>
  <p class="st">Krok 1 z 3 · baza danych</p>
  <p>Załóż w panelu hostingu bazę <b>MySQL</b> i przepisz tu jej dane.</p>
  <?php if ($msg): ?><p class="err"><?= $e($msg) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <label>Serwer bazy <small>(zwykle „localhost”)</small><input name="dh" value="<?= $e($_POST['dh'] ?? 'localhost') ?>"></label>
    <label>Nazwa bazy<input name="dn" value="<?= $e($_POST['dn'] ?? '') ?>" required></label>
    <label>Użytkownik bazy<input name="du" value="<?= $e($_POST['du'] ?? '') ?>" required></label>
    <label>Hasło do bazy<input name="dp" type="password"></label>
    <label>E-mail salonu <small>(tu przychodzą powiadomienia o rezerwacjach)</small><input name="mail" type="email" value="<?= $e($_POST['mail'] ?? '') ?>" required></label>
    <button>Połącz i zapisz</button>
  </form>
<?php else: ?>
  <p class="st">Krok 2 z 3 · hasło do panelu</p>
  <?php if ($msg): ?><p class="err"><?= $e($msg) ?></p><?php endif; ?>
  <p>Baza połączona, tabele gotowe. Ustaw hasło, którym Kama będzie logować się do panelu.</p>
  <form method="post"><label>Hasło (min. 8 znaków)<input type="password" name="p" required autocomplete="new-password"></label><label>Powtórz hasło<input type="password" name="p2" required autocomplete="new-password"></label><button>Zapisz hasło i zakończ</button></form>
<?php endif; ?>
</body></html>
