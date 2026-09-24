# Commitment Wall demo

A PHP + JSON demo. There's no database.

- `index.php`: the form (name, company, strategic priority up to 280 characters). It submits by Ajax and clears after sending.
- `display.php`: the live wall. It checks `api/entries.php` every 3 seconds and adds new entries without a page refresh.
- `api/submit.php`: checks the fields and saves each entry to `data/entries.json`, using file locking.
- `config.php`: character limits, timezone and the data file path.

## Setup
Upload the files to a PHP 7.4+ host. PHP needs permission to write to the `data/` folder, and `entries.json` is created on the first submission. `data/.htaccess` blocks direct access to the data file on Apache. On Nginx, deny `/data/` in the server config.

## Entry format
```json
{ "id": "e_<16 hex>", "name": "", "company": "", "priority": "",
  "createdAt": "ISO 8601 (Europe/London)", "bucket": null }
```
Each card on the wall has `data-id` set to its entry's `id`. `bucket` is reserved for the drag-into-buckets page, which will be added later.
