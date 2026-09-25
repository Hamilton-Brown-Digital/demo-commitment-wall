<?php
// GET endpoint: returns all entries. The display page polls this.
// Returns 304 Not Modified when nothing has changed, so polling stays cheap.
require __DIR__ . '/../config.php';

clearstatcache(true, DATA_FILE);
// Hash the contents (not just time + size) so quick edits of the same length,
// e.g. changing a bucket from "A" to "B", are always picked up.
$etag = '"' . (file_exists(DATA_FILE) ? md5_file(DATA_FILE) : 'empty') . '"';

header('ETag: ' . $etag);
header('Cache-Control: no-cache');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

json_response(['ok' => true, 'entries' => read_entries()]);
