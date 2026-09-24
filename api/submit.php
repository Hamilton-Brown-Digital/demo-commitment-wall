<?php
// POST endpoint: validates a submission and appends it to data/entries.json.
require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$name     = trim((string)($_POST['name'] ?? ''));
$company  = trim((string)($_POST['company'] ?? ''));
$priority = trim((string)($_POST['priority'] ?? ''));

$errors = [];
if ($name === '')     $errors['name'] = 'Please enter your name.';
if ($company === '')  $errors['company'] = 'Please enter your company.';
if ($priority === '') $errors['priority'] = 'Please enter a strategic priority.';

if (str_length($name) > MAX_NAME)         $errors['name'] = 'Name is too long.';
if (str_length($company) > MAX_COMPANY)   $errors['company'] = 'Company name is too long.';
if (str_length($priority) > MAX_PRIORITY) $errors['priority'] = 'Strategic priority must be ' . MAX_PRIORITY . ' characters or fewer.';

if ($errors) {
    json_response(['ok' => false, 'errors' => $errors], 422);
}

$entry = [
    'id'        => 'e_' . bin2hex(random_bytes(8)), // unique ID for later drag-to-bucket work
    'name'      => $name,
    'company'   => $company,
    'priority'  => $priority,
    'createdAt' => date('c'),                       // ISO 8601, e.g. 2026-09-24T14:05:12+01:00
    'bucket'    => null,                            // placeholder for the future bucket page
];

if (!append_entry($entry)) {
    json_response(['ok' => false, 'error' => 'Could not save your entry: PHP cannot write to ' . DATA_FILE . '. Check the data folder is writable.'], 500);
}

json_response(['ok' => true, 'entry' => $entry], 201);
