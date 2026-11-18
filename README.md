# tum/system-reporter

Laravel-Paket, mit dem eine Anwendung ihren **System- und Patchstand** selbst an
das IT-Inventar des TUM IT-Portals meldet (`/systems`). Gedacht für Umgebungen
ohne Agent und ohne CI, insbesondere **Shared Webhosting**.

Für **Server ohne Laravel-Anwendung** liegt ein Shell-Skript bei
([`bin/tum-system-report`](#server)), das denselben Heartbeat ohne PHP schickt.

Das Paket meldet nur Fakten. Bewertet wird zentral im Portal (Meldestatus,
Support-Ende, bekannte Schwachstellen in den Paketen). Zuständigkeiten pflegt der Lehrstuhl
im Portal, nicht in der App.

## Was gemeldet wird

Einmal täglich ein Heartbeat im Format `tum.system-report/v2`:

| Bereich | Inhalt |
|---|---|
| `reporter` | Paketversion, Kontext (`cli`), Fähigkeiten |
| `instance` | Hostname, `APP_ENV`, `APP_URL` |
| `app` | Name, Version (`APP_VERSION` oder Commit aus `.git`), Laravel-Version, Fingerprint von `vendor/composer/installed.json` und `package-lock.json`, offene Migrationen |
| `runtime` | PHP-Version, SAPI und Extensions; Datenbank (Produkt + Version, MariaDB normalisiert); OpenSSL, cURL, ICU; `web_php`: PHP-Version im Web-Kontext |
| `os` | `/etc/os-release`, Kernel, Uptime; `patch`: ausstehende Updates und Sicherheitsupdates (`apt-get -s dist-upgrade`, zählt auch neue Kernel-Pakete), Neustart nötig (`null` = unbekannt, wenn das System keinen update-notifier hat), letztes Update |
| `signals` | `debug`, `https`, `secure_cookie`; aus dem Web-Kontext zusätzlich `display_errors`, `expose_php` (Werte aus der php.ini) |
| `packages` | installierte Produktionspakete, nur Name und Version: `composer` aus `vendor/composer/installed.json` (ohne dev), `npm` aus `package-lock.json` (ohne dev). Das Portal gleicht sie mit [OSV](https://osv.dev) ab |
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

Das Paket liegt nicht auf Packagist. In der `composer.json` der App eintragen:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/tum/system-reporter.git" }
]
```

```bash
composer require tum/system-reporter:^2.0
```

Das Repo ist öffentlich, Composer braucht also keine Zugangsdaten.

Dann im Portal unter **IT-Systeme → System → Melde-Tokens** ein Token erzeugen
und in die `.env` der App eintragen:

```dotenv
SYSTEM_REPORTER_ENABLED=true
SYSTEM_REPORTER_TOKEN=itps_…
# optional:
SYSTEM_REPORTER_URL=https://portal.it.tum.de/interface/systems/report
APP_VERSION=          # z. B. vom Deploy gesetzt; leer: Commit aus .git
SYSTEM_REPORTER_TIME= # feste Uhrzeit "HH:MM"; leer: stabile Minute 03:00–05:59
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

`SYSTEM_REPORTER_URL` muss `https://` sein (`http://` nur für localhost);
Redirects werden nicht verfolgt.

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
| `SYSTEM_REPORTER_OS_PATCH` | `true` | `apt-get -s dist-upgrade` ausführen |
| `SYSTEM_REPORTER_PACKAGES` | `true` | Paketliste (Composer, npm) mitsenden |

## Server

Für Server ohne Laravel-Anwendung (Datenbank-, Mail-, Reverse-Proxy-Server
usw.) meldet `bin/tum-system-report` Betriebssystem und Patchstand. Es braucht
nur `bash` und `curl`, keine Root-Rechte und kein PHP. Unterstützt werden
`apt` (Debian, Ubuntu) und `dnf` (RHEL, Rocky, Alma, Fedora).

```bash
# Version festlegen, Skript holen und prüfen
curl -fsSLo /usr/local/bin/tum-system-report \
  https://raw.githubusercontent.com/tum/system-reporter/v2.1.0/bin/tum-system-report
chmod 755 /usr/local/bin/tum-system-report
tum-system-report --dry-run        # zeigt den Payload, sendet nichts

# Token aus dem Portal (IT-Systeme → System → Melde-Tokens)
install -m 600 /dev/null /etc/tum-system-report.conf
echo 'TOKEN=itps_…' > /etc/tum-system-report.conf

# täglich melden, gestreut über eine Stunde
echo '0 4 * * * root /usr/local/bin/tum-system-report --random-delay 3600 >/dev/null' \
  > /etc/cron.d/tum-system-report
```

- Das Token steht nur in der Konfigurationsdatei. Das Skript liest sie, ohne
  sie auszuführen, und übergibt das Token per stdin an curl. So erscheint es
  nicht in der Prozessliste.
- `URL=` in derselben Datei überschreibt den Melde-Endpunkt; nur `https://`
  (`http://` nur für localhost). Redirects werden nicht verfolgt.
- Fehler gehen auf stderr (Cron mailt sie), Exit-Code 1 bei abgelehnter oder
  fehlgeschlagener Meldung, 2 bei falscher Konfiguration.
- Was nicht ermittelbar ist (z. B. kein unterstützter Paketmanager), steht in
  `collect_errors`; gemeldet wird trotzdem.

## Entwicklung

```bash
composer install
composer test      # PHPUnit + Orchestra Testbench
composer lint      # Pint
composer analyse   # PHPStan Level 8 (Larastan)
```

CI prüft PHP 8.2–8.5 gegen Laravel 12 und 13 und das Server-Skript mit
`shellcheck`; `tests/Unit/ServerScriptTest.php` führt es gegen ein
Fixture-Verzeichnis und einen lokalen Testserver aus. Laravel 11 wird nicht
unterstützt: Die Linie ist EOL, und Composer blockiert alle 11.x-Versionen
wegen offener Sicherheitslücken.

## Änderungen

### 2.1.0

- `packages`: Composer- und npm-Produktionspakete für den Schwachstellen-Abgleich
  im Portal (abschaltbar mit `SYSTEM_REPORTER_PACKAGES=false`)
- `bin/tum-system-report`: Melde-Skript für Server ohne Laravel (apt, dnf)

### Gegenüber dem ersten Entwurf (v1)

- Format `tum.system-report/v2`: keine `system_id`/`owner` mehr, Identität per Token
- neu: `os.patch`, `runtime.web_php`, `collect_errors`, `reporter.context`
- Version aus `.git` auch bei `packed-refs`, detached HEAD und Worktrees
- MariaDB-Version normalisiert (`5.5.5-10.11.6-MariaDB` → `10.11.6`)
- Meldezeit je Installation gestreut (03:00–05:59) statt fest 04:17
- robust auf Shared Hosting: `open_basedir`, gesperrtes `exec`/`php_uname`
  führen zu `collect_errors`-Einträgen statt zum Abbruch
- Dependency-Track-Upload und Pull-Modus entfallen: das Portal gleicht die
  gemeldeten Pakete selbst mit OSV ab (seit 2.1.0)
