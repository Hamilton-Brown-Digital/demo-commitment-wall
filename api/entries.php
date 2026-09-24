<?php
// GET endpoint: returns all entries. The display page polls this.
// Returns 304 Not Modified when nothing has changed, so polling stays cheap.
require __DIR__ . '/../config.php';

clearstatcache(true, DATA_FILE);
$mtime = file_exists(DATA_FILE) ? filemtime(DATA_FILE) : 0;
$size  = file_exists(DATA_FILE) ? filesize(DATA_FILE) : 0;
$etag  = '"' . md5($mtime . '-' . $size) . '"';

header('ETag: ' . $etag);
header('Cache-Control: no-cache');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

json_response(['ok' => true, 'entries' => read_entries()]);
