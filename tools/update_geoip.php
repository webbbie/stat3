<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$release = $argv[1] ?? gmdate('Y-m');
if (!preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/', $release)) {
    fwrite(STDERR, "Verwendung: php tools/update_geoip.php [JJJJ-MM]\n");
    exit(2);
}

$targetDirectory = dirname(__DIR__) . '/data/geoip';
$target = $targetDirectory . '/dbip-country-lite.mmdb';
$metadata = $targetDirectory . '/dbip-country-lite.json';
$url = 'https://download.db-ip.com/free/dbip-country-lite-' . $release . '.mmdb.gz';

if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0750, true) && !is_dir($targetDirectory)) {
    throw new RuntimeException('Geo-IP-Verzeichnis konnte nicht angelegt werden.');
}

$compressed = tempnam($targetDirectory, 'dbip-');
$expanded = tempnam($targetDirectory, 'dbip-mmdb-');
if ($compressed === false || $expanded === false) {
    throw new RuntimeException('Temporaere Dateien konnten nicht angelegt werden.');
}

try {
    $input = @fopen($url, 'rb');
    $output = fopen($compressed, 'wb');
    if ($input === false || $output === false) {
        throw new RuntimeException('Download konnte nicht geoeffnet werden: ' . $url);
    }
    stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);

    $gzip = gzopen($compressed, 'rb');
    $database = fopen($expanded, 'wb');
    if ($gzip === false || $database === false) {
        throw new RuntimeException('Das DB-IP-Archiv konnte nicht entpackt werden.');
    }
    while (!gzeof($gzip)) {
        $chunk = gzread($gzip, 1024 * 1024);
        if ($chunk === false || fwrite($database, $chunk) === false) {
            throw new RuntimeException('Die MMDB-Datei konnte nicht geschrieben werden.');
        }
    }
    gzclose($gzip);
    fclose($database);

    $size = filesize($expanded);
    $tailLength = min((int)$size, 128 * 1024);
    $magic = file_get_contents($expanded, false, null, max(0, (int)$size - $tailLength), $tailLength);
    if ($size < 1024 * 1024 || !is_string($magic) || strpos($magic, "\xAB\xCD\xEFMaxMind.com") === false) {
        throw new RuntimeException('Die heruntergeladene Datei ist keine gueltige MMDB-Datenbank.');
    }

    chmod($expanded, 0640);
    if (!rename($expanded, $target)) {
        throw new RuntimeException('Die Geo-IP-Datenbank konnte nicht atomar ersetzt werden.');
    }

    $info = [
        'provider' => 'DB-IP Lite',
        'release' => $release,
        'source' => $url,
        'downloaded_at' => gmdate('c'),
        'sha256' => hash_file('sha256', $target),
        'size' => filesize($target),
        'license' => 'CC BY 4.0',
        'attribution' => 'IP Geolocation by DB-IP',
    ];
    file_put_contents($metadata, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    chmod($metadata, 0640);
    echo 'Aktualisiert: ' . $target . ' (' . number_format((int)$info['size'], 0, ',', '.') . " Bytes)\n";
    echo 'SHA-256: ' . $info['sha256'] . "\n";
} finally {
    if (is_file($compressed)) {
        unlink($compressed);
    }
    if (is_file($expanded)) {
        unlink($expanded);
    }
}
