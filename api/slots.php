<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/* GET api/slots.php?from=YYYY-MM-DD&to=YYYY-MM-DD → zajęte godziny i zablokowane dni */
try {
    $from = (string)($_GET['from'] ?? '');
    $to   = (string)($_GET['to'] ?? '');
    if (!valid_date($from) || !valid_date($to) || $from > $to) json_out(['error' => 'Zły zakres dat.'], 400);
    if ((new DateTime($from))->diff(new DateTime($to))->days > 100) json_out(['error' => 'Za duży zakres.'], 400);

    $taken = [];
    $st = db()->prepare("SELECT bdate, btime FROM bookings WHERE status <> 'cancelled' AND bdate BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st as $r) $taken[$r['bdate']][] = $r['btime'];

    $blocked = [];
    $st = db()->prepare('SELECT bdate, btime FROM blocks WHERE bdate BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    foreach ($st as $r) {
        if ($r['btime'] === null || $r['btime'] === '') $blocked[] = $r['bdate'];
        else $taken[$r['bdate']][] = $r['btime'];
    }
    // Wydarzenia z Kalendarza Google salonu
    $g = gcal_busy($from, $to);
    foreach ($g['taken'] as $d => $times) foreach ($times as $t) $taken[$d][] = $t;
    $blocked = array_merge($blocked, $g['blocked_days']);

    foreach ($taken as &$list) $list = array_values(array_unique($list));
    unset($list);

    json_out([
        'taken'        => (object)$taken,
        'blocked_days' => array_values(array_unique($blocked)),
        'hours'        => (object)weekly_hours(),
        'day_hours'    => (object)array_filter(day_hours_map(), fn($k) => $k >= $from && $k <= $to, ARRAY_FILTER_USE_KEY),
        'days_ahead'   => (int)cfg('days_ahead'),
        'min_notice'   => (int)cfg('min_notice_minutes'),
    ]);
} catch (Throwable $e) {
    error_log('slots: ' . $e->getMessage());
    json_out(['error' => 'Błąd serwera.'], 500);
}
