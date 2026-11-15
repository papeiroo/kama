# KamaGrooming – strona salonu pielęgnacji psów

Strona salonu KamaGrooming z rezerwacją wizyt online i panelem, w którym salon sam zmienia wszystkie treści.

## Instalacja na hostingu (PHP 8 + MySQL)

1. W panelu hostingu podepnij domenę i załóż bazę **MySQL** (zapisz nazwę bazy, użytkownika i hasło).
2. Wgraj **całą zawartość** paczki do katalogu domeny (np. `public_html`) – razem z ukrytymi plikami `.htaccess`.
3. Wejdź na `https://twojadomena.pl/api/install.php` i przejdź 3 kroki: dane bazy → hasło do panelu → gotowe.
   Instalator sam zapisze `api/config.php` i potem sam się zablokuje.
4. Panel salonu: `https://twojadomena.pl/panel/`

## Co salon zmienia sam w panelu

| Zakładka | Co tam jest |
|---|---|
| Kalendarz, Nadchodzące | rezerwacje – **Akceptuj / Akceptuj + SMS / Odwołaj** (nowe rezerwacje wiszą na górze każdej zakładki) |
| Dodaj wizytę, Wolne dni, Godziny pracy, Historia | wizyty z telefonu, urlopy, godziny przyjęć |
| Cennik, Galeria, Certyfikaty, Opinie | ceny, zdjęcia przed/po, certyfikaty, opinie klientów |
| ✏️ Treści strony | każda sekcja: teksty, zdjęcia, listy (usługi, kroki, FAQ, regulamin…), telefon, adres, Facebook, tytuł w Google, ukrywanie sekcji |
| Ustawienia | e-mail do powiadomień, zasady rezerwacji, Kalendarz Google, zmiana hasła |

## Pliki

| Ścieżka | Opis |
|---|---|
| `index.php` | strona główna (wstawia treści z panelu) |
| `index.html` | ta sama strona bez PHP (np. GitHub Pages – tryb podglądu) |
| `template.html` | źródło strony; po zmianach: `python3 tools/build.py` |
| `api/` | rezerwacje, treści, instalator; `config.example.php` = wzór konfiguracji |
| `panel/` | panel salonu |

`api/config.php` (hasło do bazy) powstaje przy instalacji i jest w `.gitignore`.
