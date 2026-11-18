# tum/system-reporter

Laravel-Paket, mit dem eine Anwendung ihren **System- und Patchstand** selbst an
das IT-Inventar des TUM IT-Portals meldet (`/systems`). Gedacht für Umgebungen
ohne Agent und ohne CI, insbesondere **Shared Webhosting**.

Das Paket meldet nur Fakten. Bewertet wird zentral im Portal (Meldestatus,
später Support-Ende und Schwachstellen). Zuständigkeiten pflegt der Lehrstuhl
im Portal, nicht in der App.

## Was gemeldet wird

Einmal täglich ein Heartbeat im Format `tum.system-report/v2`:

| Bereich | Inhalt |
|---|---|
| `reporter` | Paketversion, Kontext (`cli`), Fähigkeiten |
| `instance` | Hostname, `APP_ENV`, `APP_URL` |
| `app` | Name, Version (`APP_VERSION` oder Commit aus `.git`), Laravel-Version, Fingerprint von `vendor/composer/installed.json` und `package-lock.json`, offene Migrationen |
| `runtime` | PHP-Version, SAPI und Extensions; Datenbank (Produkt + Version, MariaDB normalisiert); OpenSSL, cURL, ICU; `web_php`: PHP-Version im Web-Kontext |
| `os` | `/etc/os-release`, Kernel, Uptime; `patch`: ausstehende Updates und Sicherheitsupdates (`apt-get -s upgrade`), Neustart nötig, letztes Update |
| `signals` | `debug`, `https`, `secure_cookie`; aus dem Web-Kontext zusätzlich `display_errors`, `expose_php` |
| `collect_errors` | was nicht auslesbar war, z. B. `os.patch: exec disabled` |

Nicht gemeldet werden Secrets, Umgebungsvariablen oder personenbezogene Daten
(ein Test prüft das). Das Portal erkennt das System am Token. Der Payload
enthält deshalb keine System-ID.

**Warum `web_php`?** Der Cron läuft mit der PHP-CLI. Auf Shared Hosting ist das
oft eine andere Version als im Web (PHP-FPM). Eine kleine Middleware in der
`web`-Gruppe schreibt deshalb einmal am Tag
`storage/framework/system-reporter-web.json`. Pro Request kostet das nur ein
`stat()`.

## Installation

Das Repo ist privat. In der `composer.json` der App eintragen:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/tum/system-reporter.git" }
]
```

```bash
composer require tum/system-reporter:^2.0
```

Auf dem Server braucht Composer Lesezugriff auf das Repo. Am einfachsten geht
das über einen fein granulierten GitHub-Token, der nur `tum/system-reporter`
lesen darf (Contents: read), hinterlegt in `auth.json` neben der
`composer.json`, nicht im Git:

```json
{ "github-oauth": { "github.com": "github_pat_…" } }
```

Dann im Portal unter **IT-Systeme → System → Melde-Tokens** ein Token erzeugen
und in die `.env` der App eintragen:

```dotenv
SYSTEM_REPORTER_ENABLED=true
SYSTEM_REPORTER_TOKEN=itps_…
# optional:
SYSTEM_REPORTER_URL=https://portal.it.tum.de/interface/systems/report
APP_VERSION=          # z. B. vom Deploy gesetzt; leer: Commit aus .git
SYSTEM_REPORTER_TIME= # feste Uhrzeit "HH:MM"; leer: stabile Minute 02:00–05:59
```

Voraussetzung ist der übliche Cron für den Laravel-Scheduler:

```
* * * * * cd /pfad/zur/app && php artisan schedule:run >> /dev/null 2>&1
```

## Testen

```bash
php artisan system-reporter:report --dry-run   # Payload anzeigen, nichts senden
php artisan system-reporter:report             # jetzt melden
```

Bei Fehlern meldet der Befehl den HTTP-Status und die Antwort des Portals,
z. B. `HTTP 401: unauthorized` (Token falsch oder gesperrt) oder
`HTTP 422: {"os.patch.pending":[…]}`, und endet mit Exit-Code 1. Wiederholt
werden nur Verbindungsfehler und 5xx.

## Konfiguration

`php artisan vendor:publish --tag=system-reporter-config` legt
`config/system-reporter.php` an. Schalter:

| Variable | Default | |
|---|---|---|
| `SYSTEM_REPORTER_ENABLED` | `false` | Meldung und Zeitplan an/aus |
| `SYSTEM_REPORTER_SCHEDULE` | `true` | im Scheduler registrieren |
| `SYSTEM_REPORTER_RECORD_WEB_RUNTIME` | `true` | Middleware für `web_php` |
| `SYSTEM_REPORTER_OS_PATCH` | `true` | `apt-get -s upgrade` ausführen |

## Entwicklung

```bash
composer install
composer test      # PHPUnit + Orchestra Testbench
composer lint      # Pint
composer analyse   # PHPStan Level 8 (Larastan)
```

CI prüft PHP 8.2–8.5 gegen Laravel 11, 12 und 13.

## Änderungen gegenüber dem ersten Entwurf (v1)

- Format `tum.system-report/v2`: keine `system_id`/`owner` mehr, Identität per Token
- neu: `os.patch`, `runtime.web_php`, `collect_errors`, `reporter.context`
- Version aus `.git` auch bei `packed-refs`, detached HEAD und Worktrees
- MariaDB-Version normalisiert (`5.5.5-10.11.6-MariaDB` → `10.11.6`)
- Meldezeit je Installation gestreut statt fest 04:17
- Dependency-Track-Upload und Pull-Modus entfallen vorerst: das Portal hat
  dafür noch keine Gegenstelle (folgt mit der SBOM-Auswertung)
