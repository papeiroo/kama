<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
/* JEDNORAZOWA INSTALACJA: tworzy tabele i ustawia hasło do panelu. Po użyciu USUŃ ten plik z serwera. */
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
$msg = ''; $ok = false;
try {
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
    try { db()->exec('CREATE INDEX idx_bdate ON bookings (bdate)'); } catch (Throwable $e) {}
    setting('schema_manage', '2');
    $has = setting('admin_hash') !== null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $p = (string)($_POST['p'] ?? '');
        if ($has && empty($_POST['reset'])) $msg = 'Hasło jest już ustawione.';
        elseif (mb_strlen($p) < 8) $msg = 'Hasło musi mieć co najmniej 8 znaków.';
        elseif ($p !== (string)($_POST['p2'] ?? '')) $msg = 'Hasła nie są takie same.';
        else { setting('admin_hash', password_hash($p, PASSWORD_DEFAULT)); $ok = true; $has = true; }
    }
} catch (Throwable $e) { $msg = 'Błąd bazy danych: sprawdź dane w api/config.php. (' . htmlspecialchars($e->getMessage()) . ')'; $has = true; }
?><!doctype html><html lang="pl"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalacja – KamaGrooming</title>
<body style="font:16px system-ui;max-width:440px;margin:40px auto;padding:0 16px;background:#faf6ee;color:#2b2218">
<h1 style="font-weight:500;color:#9a7536">Instalacja rezerwacji</h1>
<?php if ($ok): ?><p>✅ Gotowe. Tabele utworzone, hasło ustawione.</p><p><b>Teraz usuń plik <code>api/install.php</code> z serwera</b>, a potem wejdź do <a href="../panel/">panelu</a>.</p>
<?php else: ?>
<?php if ($msg): ?><p style="color:#a33"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if (!$has): ?><p>Tabele w bazie są gotowe. Ustaw hasło do panelu:</p>
<form method="post" style="display:grid;gap:10px"><input type="password" name="p" placeholder="Nowe hasło (min. 8 znaków)" required style="padding:10px"><input type="password" name="p2" placeholder="Powtórz hasło" required style="padding:10px"><button style="padding:12px;background:#c9a35c;border:0;border-radius:99px;font-weight:600">Zapisz hasło</button></form>
<?php else: ?><p>Instalacja była już wykonana. Usuń plik <code>api/install.php</code> z serwera.</p><?php endif; ?>
<?php endif; ?>
</body></html>
