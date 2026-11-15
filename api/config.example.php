<?php
/*
 * KONFIGURACJA SYSTEMU REZERWACJI KAMAGROOMING
 * 1) Uzupełnij 4 pola bazy danych (dane z panelu hostingu: MySQL → nazwa bazy, użytkownik, hasło).
 * 2) Wpisz adres strony i skrzynkę e-mail w domenie strony.
 * Ten plik jest zablokowany dla przeglądarek (api/.htaccess), widzi go tylko serwer.
 */
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'NAZWA_BAZY',
        'user' => 'UZYTKOWNIK_BAZY',
        'pass' => 'HASLO_BAZY',
    ],

    // Na ten adres przychodzą powiadomienia o nowych rezerwacjach
    'notify_email' => 'kontakt@kamagrooming.pl',
    // Nadawca maili (musi być skrzynką w domenie strony)
    'from_email'   => 'kontakt@kamagrooming.pl',
    'from_name'    => 'KamaGrooming – Salon Pielęgnacji Psów',
    'site_url'     => 'https://www.kamagrooming.pl',
    'phone'        => '799 013 911',
    'address'      => 'ul. Dworcowa 16B, 89-100 Nakło nad Notecią',

    // Godziny rozpoczęcia wizyt: 1 = poniedziałek ... 6 = sobota, 0 = niedziela (brak = nieczynne).
    // Później zmienisz je bez kodu: panel → „Godziny pracy”.
    'hours' => [
        1 => ['09:00', '11:00', '13:00', '15:00'],
        2 => ['09:00', '11:00', '13:00', '15:00'],
        3 => ['09:00', '11:00', '13:00', '15:00'],
        4 => ['09:00', '11:00', '13:00', '15:00'],
        5 => ['09:00', '11:00', '13:00', '15:00'],
        6 => ['09:00', '11:00'],
    ],
    // Kalendarz salonu: wklej tu „Tajny adres w formacie iCal” z Kalendarza Google
    // ALBO link do kalendarza iCloud z iPhone'a (zaczyna się od webcal://).
    // Każde wydarzenie w tym kalendarzu zajmuje termin na stronie; wydarzenie „cały dzień” blokuje cały dzień.
    // Wydarzenia ustawione jako „Dostępny” (zamiast „Zajęty”) nie blokują terminów. Puste = integracja wyłączona.
    'google_ical_url' => '',
    'slot_minutes'    => 120,  // ile trwa jedna wizyta (do sprawdzania, czy nachodzi na wydarzenie z kalendarza)

    'days_ahead'         => 90,   // na ile dni do przodu można rezerwować
    'min_notice_minutes' => 120,  // najwcześniej ile minut przed wizytą
    'manage_hours'       => 24,   // do ilu godzin przed wizytą klient może sam ją zmienić / odwołać
    'keep_months'        => 12,   // po ilu miesiącach usuwać stare rezerwacje (RODO)
    'timezone'           => 'Europe/Warsaw',
];
