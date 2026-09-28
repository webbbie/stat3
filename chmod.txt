#!/usr/bin/env bash
set -Eeuo pipefail

# Stats3/STAT4-Datei- und Ordnerrechte fuer einen Ubuntu/IONOS-vServer.
#
# Verwendung:
#   1. Diese Datei direkt in den produktiven stats3-Ordner hochladen.
#   2. Eines der beiden identischen Skripte im Terminal ausfuehren:
#        sudo bash ./chmod.txt
#        sudo bash ./stats3-ionos-apache-rechte.sh
#
# Falls die automatische Erkennung des PHP-Benutzers nicht passt:
#        sudo bash ./chmod.txt www-data
#
# Das Skript verwendet niemals chmod 777.

SCRIPT_NAME="$(basename -- "${BASH_SOURCE[0]}")"

if [[ ${EUID} -ne 0 ]]; then
    echo "FEHLER: Bitte als root ausfuehren: sudo bash ./$SCRIPT_NAME" >&2
    exit 1
fi

STATS3_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
cd -- "$STATS3_DIR"

if [[ ! -f count.js || ! -f pixl_config.php || ! -d stat4 || ! -f pixl_pushover.php ]]; then
    echo "FEHLER: Das Skript liegt nicht im echten stats3-Ordner: $STATS3_DIR" >&2
    echo "Erwartet werden count.js, pixl_config.php, pixl_pushover.php und stat4/." >&2
    exit 1
fi

PHP_WORKER_USER="${1:-}"
if [[ -z "$PHP_WORKER_USER" ]]; then
    PHP_WORKER_USER="$(ps -eo user=,comm= | awk '
        $2 ~ /^(php-fpm|php-fpm[0-9.]*|apache2)$/ && $1 != "root" { print $1; exit }
    ')"
fi
PHP_WORKER_USER="${PHP_WORKER_USER:-www-data}"

if ! id "$PHP_WORKER_USER" >/dev/null 2>&1; then
    echo "FEHLER: PHP-/Apache-Benutzer existiert nicht: $PHP_WORKER_USER" >&2
    echo "Pruefen mit: ps -eo user,group,comm | grep -E 'php-fpm|apache2'" >&2
    exit 1
fi

PHP_WORKER_GROUP="$(id -gn "$PHP_WORKER_USER")"

echo "Stats3-Ordner:  $STATS3_DIR"
echo "PHP-Benutzer:   $PHP_WORKER_USER"
echo "PHP-Gruppe:     $PHP_WORKER_GROUP"
echo

echo "[1/8] Normale Grundrechte setzen"
find . -type d -exec chmod 755 {} +
find . -type f -exec chmod 644 {} +
chmod 750 "${BASH_SOURCE[0]}"

echo "[2/8] Stats3-Konfigurator absichern und schreibbar machen"
chown root:"$PHP_WORKER_GROUP" .
chmod 2775 .
chown "$PHP_WORKER_USER":"$PHP_WORKER_GROUP" pixl_config.php
chmod 600 pixl_config.php
if [[ -f pixl_config.php.tmp ]]; then
    chown "$PHP_WORKER_USER":"$PHP_WORKER_GROUP" pixl_config.php.tmp
    chmod 600 pixl_config.php.tmp
fi
if [[ -f pixl_config_trash.php ]]; then
    chown root:"$PHP_WORKER_GROUP" pixl_config_trash.php
    chmod 600 pixl_config_trash.php
fi
if [[ -f pixl_config.ionos.example.php ]]; then
    chown root:"$PHP_WORKER_GROUP" pixl_config.ionos.example.php
    chmod 600 pixl_config.ionos.example.php
fi

echo "[3/8] Mind-Konfiguration absichern und schreibbar machen"
if [[ -d mind ]]; then
    chown root:"$PHP_WORKER_GROUP" mind
    chmod 2775 mind
    if [[ -f mind/config.local.php ]]; then
        chown "$PHP_WORKER_USER":"$PHP_WORKER_GROUP" mind/config.local.php
        chmod 600 mind/config.local.php
    fi
    if [[ -f mind/config.local.php.tmp ]]; then
        chown "$PHP_WORKER_USER":"$PHP_WORKER_GROUP" mind/config.local.php.tmp
        chmod 600 mind/config.local.php.tmp
    fi
    [[ ! -f mind/config.php ]] || chmod 644 mind/config.php
fi

echo "[4/8] STAT4-Konfiguration absichern und schreibbar machen"
chown root:"$PHP_WORKER_GROUP" stat4
chmod 2775 stat4
[[ ! -f stat4/config.php ]] || chmod 644 stat4/config.php
if [[ -f stat4/config.local.php ]]; then
    chown "$PHP_WORKER_USER":"$PHP_WORKER_GROUP" stat4/config.local.php
    chmod 600 stat4/config.local.php
fi
if [[ -f stat4/config.local.php.tmp ]]; then
    chown "$PHP_WORKER_USER":"$PHP_WORKER_GROUP" stat4/config.local.php.tmp
    chmod 600 stat4/config.local.php.tmp
fi

echo "[5/8] Alte SQLite-Dateien als unveraenderte root-Backups absichern"
if [[ -d stat ]]; then
    chown root:"$PHP_WORKER_GROUP" stat
    chmod 755 stat
fi
if [[ -d stat/data ]]; then
    chown root:root stat/data
    chmod 700 stat/data
fi
if [[ -d stat ]]; then
    find stat -type f \( -name '*.sqlite' -o -name '*.sqlite3' -o -name '*.db' \) \
        -exec chown root:root {} +
    find stat -type f \( -name '*.sqlite' -o -name '*.sqlite3' -o -name '*.db' \) \
        -exec chmod 600 {} +
fi

echo "[6/8] Lokale DB-IP-Laenderdatenbank lesbar machen"
if [[ -d data ]]; then
    chown root:"$PHP_WORKER_GROUP" data
    chmod 755 data
fi
if [[ -d data/geoip ]]; then
    chown root:"$PHP_WORKER_GROUP" data/geoip
    chmod 2750 data/geoip
fi
for GEOIP_FILE in data/geoip/dbip-country-lite.mmdb data/geoip/dbip-country-lite.json; do
    if [[ -f "$GEOIP_FILE" ]]; then
        chown root:"$PHP_WORKER_GROUP" "$GEOIP_FILE"
        chmod 640 "$GEOIP_FILE"
    fi
done

echo "[7/8] Gemeinsame Pushover-Sperrdatei reparieren"
if ! command -v php >/dev/null 2>&1; then
    echo "FEHLER: php wurde im Terminal-PATH nicht gefunden." >&2
    exit 1
fi

PUSHOVER_LOCK_FILE="$(php -r '
    require getcwd() . "/pixl_pushover.php";
    echo pixl_pushover_throttle_file();
')"

if [[ -z "$PUSHOVER_LOCK_FILE" || "$PUSHOVER_LOCK_FILE" != /* ]]; then
    echo "FEHLER: Pushover-Sperrdatei konnte nicht eindeutig bestimmt werden." >&2
    exit 1
fi

PUSHOVER_LOCK_DIR="$(dirname -- "$PUSHOVER_LOCK_FILE")"
if [[ ! -d "$PUSHOVER_LOCK_DIR" ]]; then
    echo "FEHLER: Temporaerer PHP-Ordner existiert nicht: $PUSHOVER_LOCK_DIR" >&2
    exit 1
fi
if ! runuser -u "$PHP_WORKER_USER" -- test -w "$PUSHOVER_LOCK_DIR"; then
    echo "FEHLER: PHP-Benutzer kann nicht in $PUSHOVER_LOCK_DIR schreiben." >&2
    exit 1
fi

if [[ -L "$PUSHOVER_LOCK_FILE" ]]; then
    echo "FEHLER: Unsichere Pushover-Sperrdatei: $PUSHOVER_LOCK_FILE" >&2
    exit 1
fi
if [[ -e "$PUSHOVER_LOCK_FILE" && ! -f "$PUSHOVER_LOCK_FILE" ]]; then
    echo "FEHLER: Pushover-Sperrpfad ist keine normale Datei: $PUSHOVER_LOCK_FILE" >&2
    exit 1
fi
if [[ ! -e "$PUSHOVER_LOCK_FILE" ]]; then
    runuser -u "$PHP_WORKER_USER" -- touch "$PUSHOVER_LOCK_FILE"
fi
chown "$PHP_WORKER_USER":"$PHP_WORKER_GROUP" "$PUSHOVER_LOCK_FILE"
chmod 600 "$PUSHOVER_LOCK_FILE"

echo "[8/8] Rechte als PHP-Benutzer kontrollieren"
FAILED=0
check_access() {
    local DESCRIPTION="$1"
    shift
    if runuser -u "$PHP_WORKER_USER" -- test "$@"; then
        echo "OK:     $DESCRIPTION"
    else
        echo "FEHLER: $DESCRIPTION" >&2
        FAILED=1
    fi
}

check_access "pixl_config.php ist lesbar" -r "$STATS3_DIR/pixl_config.php"
check_access "Stats3-Ordner ist schreibbar" -w "$STATS3_DIR"
check_access "STAT4-Ordner ist schreibbar" -w "$STATS3_DIR/stat4"
[[ ! -d mind ]] || check_access "Mind-Ordner ist schreibbar" -w "$STATS3_DIR/mind"
[[ ! -f mind/config.local.php ]] || check_access "Mind-Konfiguration ist lesbar" -r "$STATS3_DIR/mind/config.local.php"
[[ ! -d data/geoip ]] || check_access "Geo-IP-Ordner ist lesbar" -r "$STATS3_DIR/data/geoip"
[[ ! -f data/geoip/dbip-country-lite.mmdb ]] || check_access "Geo-IP-Datenbank ist lesbar" -r "$STATS3_DIR/data/geoip/dbip-country-lite.mmdb"
check_access "Pushover-Sperrdatei ist lesbar" -r "$PUSHOVER_LOCK_FILE"
check_access "Pushover-Sperrdatei ist schreibbar" -w "$PUSHOVER_LOCK_FILE"

if [[ -d stat ]]; then
    while IFS= read -r -d '' SQLITE_BACKUP; do
        if runuser -u "$PHP_WORKER_USER" -- test -w "$SQLITE_BACKUP"; then
            echo "FEHLER: SQLite-Backup ist fuer PHP schreibbar: $SQLITE_BACKUP" >&2
            FAILED=1
        else
            echo "OK:     SQLite-Backup ist fuer PHP schreibgeschuetzt: $SQLITE_BACKUP"
        fi
    done < <(find stat -type f \( -name '*.sqlite' -o -name '*.sqlite3' -o -name '*.db' \) -print0)
fi

echo
stat -c '%U %G %a %n' . pixl_config.php stat4 "$PUSHOVER_LOCK_FILE"
[[ ! -d mind ]] || stat -c '%U %G %a %n' mind
[[ ! -f mind/config.local.php ]] || stat -c '%U %G %a %n' mind/config.local.php
[[ ! -f stat4/config.local.php ]] || stat -c '%U %G %a %n' stat4/config.local.php

if [[ "$FAILED" -ne 0 ]]; then
    echo
    echo "Mindestens eine Rechtepruefung ist fehlgeschlagen." >&2
    exit 1
fi

echo
echo "FERTIG: Zentrale MySQL-, Konfigurations-, SQLite-Backup-, Geo-IP- und Pushover-Rechte sind gesetzt."
echo "Pushover-Sperrdatei: $PUSHOVER_LOCK_FILE"
