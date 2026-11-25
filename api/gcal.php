<?php
declare(strict_types=1);
/*
 * Kalendarz Google → zajęte terminy na stronie.
 * Czyta „tajny adres w formacie iCal” kalendarza salonu (tylko odczyt), trzyma kopię przez 5 minut
 * i zamienia wydarzenia na zajęte godziny (wydarzenie całodniowe = cały dzień wolny).
 */

const GCAL_TTL = 300;

function gcal_cache_file(): string { return __DIR__ . '/cache/gcal.ics'; }

/** Pobiera plik .ics (z pamięcią podręczną). Zwraca treść albo null. */
function gcal_fetch(): ?string
{
    $url = trim((string)(cfg('google_ical_url') ?? ''));
    if ($url === '') return null;
    if (stripos($url, 'webcal://') === 0) $url = 'https://' . substr($url, 9);   // link z iPhone'a / iCloud
    $file = gcal_cache_file();
    if (is_file($file) && time() - filemtime($file) < GCAL_TTL) return (string)file_get_contents($file);

    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $r = curl_exec($ch);
        if ($r !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $body = (string)$r;
        curl_close($ch);
    } else {
        $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]]));
        if ($r !== false) $body = (string)$r;
    }

    if ($body !== null && strpos($body, 'BEGIN:VCALENDAR') !== false) {
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
        @file_put_contents($file, $body, LOCK_EX);
        @file_put_contents(dirname($file) . '/gcal.status', json_encode(['ok' => true, 'at' => time()]));
        return $body;
    }
    @file_put_contents(dirname($file) . '/gcal.status', json_encode(['ok' => false, 'at' => time()]));
    error_log('gcal: nie udało się pobrać kalendarza Google');
    return is_file($file) ? (string)file_get_contents($file) : null;   // awaria → ostatnia dobra kopia
}

function gcal_status(): array
{
    if (trim((string)(cfg('google_ical_url') ?? '')) === '') return ['state' => 'off'];
    $f = dirname(gcal_cache_file()) . '/gcal.status';
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$s) return ['state' => 'unknown'];
    return ['state' => $s['ok'] ? 'ok' : 'error', 'at' => (int)$s['at']];
}

/** Rozbija plik .ics na wydarzenia (tablice właściwości). */
function gcal_parse(string $ics): array
{
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $ics = preg_replace("/\n[ \t]/", '', $ics);
    $events = []; $cur = null; $depth = 0;
    foreach (explode("\n", $ics) as $line) {
        if ($line === 'BEGIN:VEVENT') { $cur = ['EXDATE' => []]; continue; }
        if ($line === 'END:VEVENT')   { if ($cur) $events[] = $cur; $cur = null; continue; }
        if ($cur === null) continue;
        if (strpos($line, 'BEGIN:') === 0) { $depth++; continue; }   // np. VALARM
        if (strpos($line, 'END:') === 0)   { $depth--; continue; }
        if ($depth > 0) continue;
        $p = strpos($line, ':'); if ($p === false) continue;
        $left = substr($line, 0, $p); $val = substr($line, $p + 1);
        $parts = explode(';', $left); $name = strtoupper(array_shift($parts)); $params = [];
        foreach ($parts as $pp) { [$k, $v] = array_pad(explode('=', $pp, 2), 2, ''); $params[strtoupper($k)] = trim($v, '"'); }
        if ($name === 'EXDATE') { foreach (explode(',', $val) as $x) $cur['EXDATE'][] = [$x, $params]; continue; }
        if (in_array($name, ['DTSTART', 'DTEND', 'DURATION', 'RRULE', 'TRANSP', 'STATUS', 'RECURRENCE-ID'], true)) $cur[$name] = [$val, $params];
    }
    return $events;
}

/** Zamienia wartość daty z .ics na DateTime w strefie salonu. Zwraca [DateTime, czyCałodniowe]. */
function gcal_dt(array $v, DateTimeZone $tz): ?array
{
    [$val, $params] = $v;
    if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $val)) {
        $d = DateTime::createFromFormat('!Ymd', substr($val, 0, 8), $tz);
        return $d ? [$d, true] : null;
    }
    if (substr($val, -1) === 'Z') {
        $d = DateTime::createFromFormat('Ymd\THis', substr($val, 0, 15), new DateTimeZone('UTC'));
        if ($d) $d->setTimezone($tz);
    } else {
        try { $z = isset($params['TZID']) ? new DateTimeZone($params['TZID']) : $tz; } catch (Throwable $e) { $z = $tz; }
        $d = DateTime::createFromFormat('Ymd\THis', substr($val, 0, 15), $z);
        if ($d) $d->setTimezone($tz);
    }
    return $d ? [$d, false] : null;
}

/** Wszystkie wystąpienia wydarzeń przecinające zakres [from, to). Zwraca listę [start, end, allDay]. */
function gcal_occurrences(string $ics, DateTime $from, DateTime $to): array
{
    $tz = new DateTimeZone((string)(cfg('timezone') ?? 'Europe/Warsaw'));
    $out = [];
    foreach (gcal_parse($ics) as $ev) {
        if (!isset($ev['DTSTART'])) continue;
        if (strtoupper($ev['TRANSP'][0] ?? '') === 'TRANSPARENT') continue;   // „Dostępny” w Google – nie blokuje
        if (strtoupper($ev['STATUS'][0] ?? '') === 'CANCELLED') continue;
        $s = gcal_dt($ev['DTSTART'], $tz); if (!$s) continue;
        [$start, $allDay] = $s;
        if (isset($ev['DTEND']) && ($e = gcal_dt($ev['DTEND'], $tz))) $end = $e[0];
        elseif (isset($ev['DURATION'])) { $end = clone $start; try { $end->add(new DateInterval($ev['DURATION'][0])); } catch (Throwable $x) { $end->modify($allDay ? '+1 day' : '+1 hour'); } }
        else { $end = clone $start; $end->modify($allDay ? '+1 day' : '+1 hour'); }
        $len = $end->getTimestamp() - $start->getTimestamp(); if ($len <= 0) continue;

        $ex = [];
        foreach ($ev['EXDATE'] as [$x, $p]) { $d = gcal_dt([$x, $p], $tz); if ($d) $ex[$d[0]->format($allDay ? 'Y-m-d' : 'Y-m-d H:i')] = true; }

        foreach (gcal_expand($start, $ev['RRULE'][0] ?? null, $to, $tz) as $occ) {
            if (isset($ex[$occ->format($allDay ? 'Y-m-d' : 'Y-m-d H:i')])) continue;
            $oe = (clone $occ)->modify("+$len seconds");
            if ($oe <= $from || $occ >= $to) continue;
            $out[] = [$occ, $oe, $allDay];
        }
    }
    return $out;
}

/** Rozwija regułę powtarzania (DAILY/WEEKLY z BYDAY/MONTHLY/YEARLY, INTERVAL, COUNT, UNTIL). */
function gcal_expand(DateTime $start, ?string $rrule, DateTime $to, DateTimeZone $tz): array
{
    if (!$rrule) return [$start];
    $r = [];
    foreach (explode(';', $rrule) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $r[strtoupper($k)] = $v; }
    $freq = strtoupper($r['FREQ'] ?? ''); $int = max(1, (int)($r['INTERVAL'] ?? 1));
    $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null;
    $until = null;
    if (isset($r['UNTIL'])) { $u = gcal_dt([$r['UNTIL'], []], $tz); if ($u) { $until = $u[0]; if ($u[1]) $until->setTime(23, 59, 59); } }
    $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $byday = [];
    foreach (array_filter(explode(',', strtoupper($r['BYDAY'] ?? ''))) as $b) { $b = preg_replace('/^[+-]?\d+/', '', $b); if (isset($map[$b])) $byday[] = $map[$b]; }

    $res = []; $n = 0; $guard = 0;
    $emit = function (DateTime $d) use (&$res, &$n, $count, $until, $start, $to): bool {
        if ($d < $start) return true;
        if ($until && $d > $until) return false;
        if ($count !== null && $n >= $count) return false;
        if ($d > $to) return false;
        $n++; $res[] = clone $d; return true;
    };
    $step = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'][$freq] ?? null;
    if (!$step) return [$start];

    if ($freq === 'WEEKLY' && $byday) {
        sort($byday);
        $week = (clone $start)->modify('monday this week');
        while ($guard++ < 3000) {
            foreach ($byday as $wd) {
                $d = (clone $week)->modify('+' . ($wd - 1) . ' days')->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'));
                if (!$emit($d)) return $res;
            }
            $week->modify("+$int week");
        }
        return $res;
    }
    $d = clone $start; $i = 0;
    while ($guard++ < 5000) {
        if (!$emit($d)) return $res;
        $i++;
        $d = clone $start;
        $d->modify('+' . ($i * $int) . " $step");
        if (in_array($freq, ['MONTHLY', 'YEARLY'], true) && $d->format('d') !== $start->format('d')) continue; // np. 31. dzień miesiąca
    }
    return $res;
}

/** Zajęte godziny i całe dni z kalendarza Google w zakresie dat (Y-m-d, włącznie). */
/** Wydarzenia jako przedziały minut w każdym dniu: ['busy' => [data => [[od, do], ...]], 'blocked_days' => [...]] */
function gcal_intervals(string $fromDate, string $toDate): array
{
    $result = ['busy' => [], 'blocked_days' => []];
    $ics = gcal_fetch(); if ($ics === null) return $result;
    $tz = new DateTimeZone((string)(cfg('timezone') ?? 'Europe/Warsaw'));
    $from = new DateTime($fromDate . ' 00:00', $tz);
    $to   = (new DateTime($toDate . ' 00:00', $tz))->modify('+1 day');
    $occ  = gcal_occurrences($ics, $from, $to);
    for ($day = clone $from; $day < $to; $day->modify('+1 day')) {
        $ds = $day->format('Y-m-d'); $dayEnd = (clone $day)->modify('+1 day');
        foreach ($occ as [$s, $e, $all]) {
            if (!($s < $dayEnd && $e > $day)) continue;
            if ($all) { $result['blocked_days'][] = $ds; continue 2; }
            $a = $s < $day ? 0 : ((int)$s->format('G')) * 60 + (int)$s->format('i');
            $b = $e >= $dayEnd ? 1440 : ((int)$e->format('G')) * 60 + (int)$e->format('i');
            if ($b > $a) $result['busy'][$ds][] = [$a, $b];
        }
    }
    return $result;
}

function gcal_busy(string $fromDate, string $toDate): array
{
    $result = ['taken' => [], 'blocked_days' => []];
    $ics = gcal_fetch(); if ($ics === null) return $result;
    $tz = new DateTimeZone((string)(cfg('timezone') ?? 'Europe/Warsaw'));
    $from = new DateTime($fromDate . ' 00:00', $tz);
    $to   = (new DateTime($toDate . ' 00:00', $tz))->modify('+1 day');
    $occ  = gcal_occurrences($ics, $from, $to);
    if (!$occ) return $result;
    $slotLen = (int)(cfg('slot_minutes') ?? 150);

    for ($day = clone $from; $day < $to; $day->modify('+1 day')) {
        $ds = $day->format('Y-m-d');
        $dayEnd = (clone $day)->modify('+1 day');
        foreach ($occ as [$s, $e, $all]) {
            if ($all && $s < $dayEnd && $e > $day) { $result['blocked_days'][] = $ds; continue 2; }
        }
        foreach (hours_for($ds) as $t) {
            $ss = new DateTime("$ds $t", $tz); $se = (clone $ss)->modify("+$slotLen minutes");
            foreach ($occ as [$s, $e, $all]) {
                if ($s < $se && $e > $ss) { $result['taken'][$ds][] = $t; break; }
            }
        }
    }
    return $result;
}
