<?php
// POST endpoint: puts an entry in a bucket (or takes it out) and saves it to data/entries.json.
// Fields: id (entry id), bucket (a key from BUCKETS in config.php, or empty to clear).
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$id     = trim((string)($_POST['id'] ?? ''));
$bucket = trim((string)($_POST['bucket'] ?? ''));

if ($id === '') {
    json_response(['ok' => false, 'error' => 'Missing entry id.'], 422);
}
if ($bucket !== '' && !array_key_exists($bucket, BUCKETS)) {
    json_response(['ok' => false, 'error' => 'Unknown bucket.'], 422);
}

$entry = update_entry($id, function (array $entry) use ($bucket) {
    $entry['bucket'] = $bucket === '' ? null : $bucket;
    return $entry;
});

if ($entry === null) {
    json_response(['ok' => false, 'error' => 'Entry not found or could not be saved.'], 404);
}

json_response(['ok' => true, 'entry' => $entry]);
