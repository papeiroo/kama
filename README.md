# KamaGrooming – strona salonu pielęgnacji psów

Strona salonu KamaGrooming (ul. Dworcowa 16B, Nakło nad Notecią) z kalendarzem rezerwacji online i panelem administratora.

## Co jest w środku

| Ścieżka | Opis |
|---|---|
| `index.html` | gotowa strona (generowana z `template.html`) |
| `template.html` | źródło strony – edytuj to, potem przebuduj |
| `api/` | backend PHP: rezerwacje, wolne terminy, treści, Google Calendar |
| `panel/` | panel administratora (`/panel/`) |
| `img/`, `ikony/` | zdjęcia, realizacje (przed/po), ikony PWA |
| `sw.js`, `manifest.webmanifest` | aplikacja na telefon (PWA) |
| `tools/build.py` | wstrzykuje galerię z `api/gallery-default.json` do `index.html` |

## Uruchomienie na hostingu (PHP 8 + MySQL)

1. Wgraj całą zawartość repo do katalogu domeny (np. `public_html`).
2. Skopiuj `api/config.example.php` → `api/config.php` i wpisz dane bazy, e-mail i adres strony.
3. Otwórz `https://twojadomena.pl/api/install.php` – utworzy tabele i ustawi hasło do panelu.
4. Po instalacji usuń lub zablokuj `api/install.php`.
5. Panel: `https://twojadomena.pl/panel/`.

`api/config.php` jest w `.gitignore` – hasła do bazy nie trafią do repozytorium.

## GitHub Pages

GitHub Pages nie obsługuje PHP, więc tam strona działa w **trybie podglądu**: kalendarz pokazuje przykładowe terminy, a rezerwacje nie są nigdzie wysyłane. Do prawdziwych rezerwacji potrzebny jest hosting z PHP.

## Edycja strony

```bash
# po zmianach w template.html lub api/gallery-default.json
python3 tools/build.py
```

Po większych zmianach podbij wersję cache w `sw.js` (`kama-v2` → `kama-v3`), żeby telefony pobrały nową wersję.
