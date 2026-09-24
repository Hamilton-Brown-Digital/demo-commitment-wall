<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Strategic priorities</title>
    <!-- <link rel="stylesheet" href="css/style.css"> -->
</head>
<body class="page-display">

<main class="wall">
    <h1>Strategic priorities</h1>
    <p class="wall-count"><span id="entry-count">0</span> entries</p>
    <ul id="entries" class="entries"></ul>
    <p id="empty-state" class="empty-state">Waiting for the first entry…</p>
</main>

<!-- Card template: edit markup here to change how each entry appears -->
<template id="entry-template">
    <li class="entry" draggable="false">
        <blockquote class="entry-priority"></blockquote>
        <p class="entry-meta">
            <span class="entry-name"></span>,
            <span class="entry-company"></span>
        </p>
        <time class="entry-date"></time>
    </li>
</template>

<script>
(function () {
    const POLL_MS = 3000;          // how often to check for new entries
    const NEWEST_FIRST = true;     // set false to add new entries at the bottom

    const list = document.getElementById('entries');
    const tpl = document.getElementById('entry-template');
    const countEl = document.getElementById('entry-count');
    const emptyEl = document.getElementById('empty-state');
    const seen = new Set();
    let etag = null;
    let firstLoad = true;

    function formatDate(iso) {
        const d = new Date(iso);
        return d.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function renderEntry(entry) {
        const node = tpl.content.firstElementChild.cloneNode(true);
        node.dataset.id = entry.id;                 // used later for drag-to-bucket
        node.querySelector('.entry-priority').textContent = entry.priority;
        node.querySelector('.entry-name').textContent = entry.name;
        node.querySelector('.entry-company').textContent = entry.company;
        const t = node.querySelector('.entry-date');
        t.dateTime = entry.createdAt;
        t.textContent = formatDate(entry.createdAt);

        if (!firstLoad) {
            node.classList.add('is-new');           // hook for an entrance animation
            setTimeout(() => node.classList.remove('is-new'), 4000);
        }
        return node;
    }

    function update(entries) {
        entries.forEach(entry => {
            if (seen.has(entry.id)) return;
            seen.add(entry.id);
            const node = renderEntry(entry);
            NEWEST_FIRST ? list.prepend(node) : list.append(node);
        });
        countEl.textContent = seen.size;
        emptyEl.hidden = seen.size > 0;
        firstLoad = false;
    }

    async function poll() {
        try {
            const headers = etag ? { 'If-None-Match': etag } : {};
            const res = await fetch('api/entries.php', { headers, cache: 'no-store' });
            if (res.status === 200) {
                etag = res.headers.get('ETag');
                const data = await res.json();
                if (data.ok) update(data.entries);
            }
            // 304 = nothing new, do nothing
        } catch (err) {
            console.warn('Polling failed, will retry', err);
        } finally {
            setTimeout(poll, POLL_MS);
        }
    }

    poll();
})();
</script>
</body>
</html>
