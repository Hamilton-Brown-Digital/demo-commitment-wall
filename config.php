<?php
// Shared settings and helpers for the commitment wall demo.

date_default_timezone_set('Europe/London');

// Never let PHP warnings leak into JSON responses; report fatal errors as JSON instead.
ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')']);
    }
});

/**
 * Character count that works with or without the mbstring extension.
 */
function str_length(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return preg_match_all('/./us', $s);
}

define('DATA_FILE', __DIR__ . '/data/entries.json');
define('MAX_NAME', 100);
define('MAX_COMPANY', 100);
define('MAX_PRIORITY', 140);

// Buckets for the sorting page (sort.php). Keys are what's saved in each entry's "bucket" field.
// Change the labels and colours here; the page and the save endpoint both read this list.
const BUCKETS = [
    'A' => ['label' => 'A', 'color' => '#fd5108'],  // PwC orange
    'B' => ['label' => 'B', 'color' => '#0e7c86'],  // teal
    'C' => ['label' => 'C', 'color' => '#7b3fa0'],  // purple
];

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
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }
    $fp = @fopen(DATA_FILE, 'c+');
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

/**
 * Change one entry in place under an exclusive lock.
 * $change receives the entry array and returns the updated one.
 * Returns the updated entry, or null if the id wasn't found / the file couldn't be written.
 */
function update_entry(string $id, callable $change): ?array
{
    $fp = @fopen(DATA_FILE, 'c+');
    if (!$fp) {
        return null;
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true);
    $updated = null;

    if (is_array($data)) {
        foreach ($data as $i => $entry) {
            if (($entry['id'] ?? null) === $id) {
                $data[$i] = $updated = $change($entry);
                break;
            }
        }
    }

    if ($updated !== null) {
        ftruncate($fp, 0);
        rewind($fp);
        if (fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            $updated = null;
        }
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $updated;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
