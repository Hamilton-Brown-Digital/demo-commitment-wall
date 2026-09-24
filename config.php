<?php
// Shared settings and helpers for the commitment wall demo.

date_default_timezone_set('Europe/London');

define('DATA_FILE', __DIR__ . '/data/entries.json');
define('MAX_NAME', 100);
define('MAX_COMPANY', 100);
define('MAX_PRIORITY', 280);

/**
 * Read all entries from the JSON file (shared lock so we never read a half-written file).
 */
function read_entries(): array
{
    if (!file_exists(DATA_FILE)) {
        return [];
    }
    $fp = fopen(DATA_FILE, 'r');
    if (!$fp) {
        return [];
    }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

/**
 * Append one entry atomically (exclusive lock so simultaneous submissions don't overwrite each other).
 */
function append_entry(array $entry): bool
{
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return false;
    }
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp) {
        return false;
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) {
        $data = [];
    }
    $data[] = $entry;

    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
