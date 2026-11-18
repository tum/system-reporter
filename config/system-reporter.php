<?php

return [

    // Master-Schalter. Lokal und in CI aus, in Produktion an.
    'enabled' => env('SYSTEM_REPORTER_ENABLED', false),

    // Melde-Endpunkt des IT-Portals und das Token dieses Systems
    // (IT-Portal → IT-Systeme → System → Melde-Tokens).
    'url' => env('SYSTEM_REPORTER_URL', 'https://portal.it.tum.de/interface/systems/report'),
    'token' => env('SYSTEM_REPORTER_TOKEN'),

    // Version des Deployments (Git-Tag oder Commit). Leer: .git wird gelesen.
    'app_version' => env('APP_VERSION'),

    /*
     | Zeitplan. Erfordert den üblichen Cron für "php artisan schedule:run".
     | Ohne feste Zeit wählt das Paket je Installation eine Minute zwischen
     | 03:00 und 05:59 (aus app.url abgeleitet), damit nicht alle Systeme
     | gleichzeitig melden.
     */
    'schedule' => [
        'enabled' => env('SYSTEM_REPORTER_SCHEDULE', true),
        'time' => env('SYSTEM_REPORTER_TIME'), // z. B. "04:17"
        'timezone' => env('SYSTEM_REPORTER_TIMEZONE', 'Europe/Berlin'),
    ],

    /*
     | PHP-Version im Web-Kontext erfassen. Cron läuft mit der PHP-CLI, die
     | auf Shared Hosting eine andere Version sein kann als PHP-FPM. Eine
     | Middleware in der web-Gruppe schreibt einmal am Tag eine kleine Datei
     | nach storage/framework.
     */
    'record_web_runtime' => env('SYSTEM_REPORTER_RECORD_WEB_RUNTIME', true),

    // Ausstehende OS-Updates per "apt-get -s upgrade" ermitteln (ohne Root).
    // Auf Shared Hosting meist nicht möglich; dann steht es in collect_errors.
    'os_patch' => env('SYSTEM_REPORTER_OS_PATCH', true),

    'http_timeout' => 15,
];
