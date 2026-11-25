<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* GET api/slots.php?from=YYYY-MM-DD&to=YYYY-MM-DD
   → godziny otwarcia i zajęte przedziały każdego dnia. Wolne godziny rozpoczęcia liczy przeglądarka
     dla czasu wybranej wizyty (rasa + usługa) tym samym algorytmem co serwer (lib.php → starts_for). */
try {
    $from = (string)($_GET['from'] ?? '');
    $to   = (string)($_GET['to'] ?? '');
    if (!valid_date($from) || !valid_date($to) || $from > $to) json_out(['error' => 'Zły zakres dat.'], 400);
    if ((new DateTime($from))->diff(new DateTime($to))->days > 100) json_out(['error' => 'Za duży zakres.'], 400);

    $bm = busy_map($from, $to);
    $days = [];
    for ($d = new DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) {
        $ds = $d->format('Y-m-d'); $w = work_for($ds);
        if (!$w || in_array($ds, $bm['closed'], true)) continue;
        $days[$ds] = ['o' => $w[0], 'c' => $w[1], 'g' => grid_for($ds), 'busy' => $bm['busy'][$ds] ?? [], 'big' => $bm['big'][$ds] ?? 0];
    }
    send_due_reminders();
    json_out([
        'days'       => (object)$days,
        'overrun'    => overrun_min(),
        'days_ahead' => (int)cfg('days_ahead'),
        'min_notice' => (int)cfg('min_notice_minutes'),
        'big'        => big_rules(),
        'now'        => date('Y-m-d H:i'),
    ]);
} catch (Throwable $e) {
    error_log('slots: ' . $e->getMessage());
    json_out(['error' => 'Błąd serwera.'], 500);
}
