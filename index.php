<?php
/* Strona główna: index.html + treści zapisane w panelu (wstawiane od razu, bez mrugania i widoczne dla Google). */
declare(strict_types=1);
$html = (string)file_get_contents(__DIR__ . '/index.html');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
try {
    require __DIR__ . '/api/lib.php';
    if (!is_configured()) throw new RuntimeException('not configured');
    $s = site_get();
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $fmt = fn($v) => nl2br(preg_replace('/\*([^*\n]+)\*/u', '<em>$1</em>', $e($v)), false);
    // teksty: <tag ... data-t="klucz" ...>…</tag>
    $html = preg_replace_callback('#<(\w+)(\b[^>]*\sdata-t="(\w+)"[^>]*)>(.*?)</\1>#s', function ($m) use ($s, $fmt) {
        $v = $s['t'][$m[3]] ?? null;
        return is_string($v) ? "<{$m[1]}{$m[2]}>" . str_replace("\n", '', $fmt($v)) . "</{$m[1]}>" : $m[0];
    }, $html);
    $html = preg_replace_callback('#<div data-tp="(\w+)">(.*?)</div>#s', function ($m) use ($s, $fmt) {
        $v = $s['t'][$m[1]] ?? null; if (!is_string($v)) return $m[0];
        $ps = array_filter(array_map('trim', preg_split('/\n\s*\n/u', $v)));
        return '<div data-tp="' . $m[1] . '">' . implode('', array_map(fn($p) => '<p>' . str_replace("\n", '', $fmt($p)) . '</p>', $ps)) . '</div>';
    }, $html);
    // zdjęcia
    $html = preg_replace_callback('#data-img="(\w+)" src="[^"]*"#', function ($m) use ($s, $e) {
        $v = $s['img'][$m[1]] ?? ''; return $v ? 'data-img="' . $m[1] . '" src="' . $e($v) . '"' : $m[0];
    }, $html);
    // tytuł i opis dla Google / Facebooka
    $title = trim((string)($s['seo']['title'] ?? '')); $desc = trim((string)($s['seo']['desc'] ?? ''));
    if ($title !== '') {
        $html = preg_replace('#<title>.*?</title>#s', '<title>' . $e($title) . '</title>', $html, 1);
        $html = preg_replace('#<meta property="og:title" content="[^"]*">#', '<meta property="og:title" content="' . $e($title) . '">', $html, 1);
    }
    if ($desc !== '') {
        $html = preg_replace('#<meta name="description" content="[^"]*">#', '<meta name="description" content="' . $e($desc) . '">', $html, 1);
        $html = preg_replace('#<meta property="og:description" content="[^"]*">#', '<meta property="og:description" content="' . $e($desc) . '">', $html, 1);
    }
    // pełne dane dla skryptu strony (listy, kontakt, ukryte sekcje)
    $json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $html = preg_replace('#<script>\s*document\.documentElement#', "<script>window.__SITE=$json;</script>\n$0", $html, 1);
} catch (Throwable $ex) { /* bez bazy: zwykła strona z domyślnymi treściami */ }
echo $html;
