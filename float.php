<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Strategic priorities – floating wall</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/float.css">
</head>
<body class="page-float">

<!-- Full-screen stage the entries float around in -->
<main id="stage" class="stage" aria-label="Strategic priorities"></main>

<p id="empty-state" class="float-empty">Waiting for the first entry…</p>

<!-- Overlay for the enlarged entry -->
<div id="focus-backdrop" class="focus-backdrop" hidden></div>

<!-- Card template: small floating version -->
<template id="bubble-template">
    <button type="button" class="bubble">
        <span class="bubble-inner">
            <span class="bubble-priority"></span>
            <span class="bubble-name"></span>
        </span>
    </button>
</template>

<!-- Card template: enlarged version shown in the centre -->
<template id="focus-template">
    <article class="focus-card" role="dialog" aria-modal="true" tabindex="-1">
        <blockquote class="focus-priority"></blockquote>
        <p class="focus-meta"><span class="focus-name"></span>, <span class="focus-company"></span></p>
        <time class="focus-date"></time>
        <p class="focus-hint">Click to close</p>
    </article>
</template>

<script>
(function () {
    const POLL_MS = 3000;       // how often to check for new entries
    const DRIFT_PX = 28;        // how far each bubble wanders from its spot
    const EDGE = 24;            // keep bubbles this far from the screen edge

    const stage = document.getElementById('stage');
    const backdrop = document.getElementById('focus-backdrop');
    const emptyEl = document.getElementById('empty-state');
    const bubbleTpl = document.getElementById('bubble-template');
    const focusTpl = document.getElementById('focus-template');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const seen = new Map();     // id -> { entry, el }
    let etag = null;
    let firstLoad = true;
    let focused = null;         // { bubble, card }

    const rand = (min, max) => min + Math.random() * (max - min);

    /* ---------- Placement ---------------------------------------------------
       "Best candidate" sampling: try a few random spots and keep the one
       furthest from existing bubbles, so they spread out evenly but randomly. */
    function place(el) {
        const W = stage.clientWidth, H = stage.clientHeight;
        const w = el.offsetWidth, h = el.offsetHeight;
        const maxX = Math.max(EDGE, W - w - EDGE - DRIFT_PX);
        const maxY = Math.max(EDGE, H - h - EDGE - DRIFT_PX);

        const others = [...seen.values()].map(s => s.el).filter(o => o !== el && o.dataset.cx);
        let best = null, bestScore = -1;

        for (let i = 0; i < 30; i++) {
            const x = rand(EDGE + DRIFT_PX, maxX);
            const y = rand(EDGE + DRIFT_PX, maxY);
            const cx = x + w / 2, cy = y + h / 2;
            let score = Infinity;
            for (const o of others) {
                const d = Math.hypot(cx - o.dataset.cx * W / 100, cy - o.dataset.cy * H / 100);
                if (d < score) score = d;
            }
            if (score > bestScore) { bestScore = score; best = { x, y, cx, cy }; }
        }

        // Store as percentages so the layout survives window resizes
        el.style.left = (best.x / W * 100) + '%';
        el.style.top  = (best.y / H * 100) + '%';
        el.dataset.cx = best.cx / W * 100;
        el.dataset.cy = best.cy / H * 100;
    }

    /* ---------- Bubbles ----------------------------------------------------- */
    function makeBubble(entry) {
        const el = bubbleTpl.content.firstElementChild.cloneNode(true);
        el.dataset.id = entry.id;               // used later for drag-to-bucket
        el.querySelector('.bubble-priority').textContent = entry.priority;
        el.querySelector('.bubble-name').textContent = entry.name + ', ' + entry.company;
        el.setAttribute('aria-label', entry.priority + ' – ' + entry.name + ', ' + entry.company);

        // Random drift path, speed and slight size variation for depth
        el.style.setProperty('--dx1', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dy1', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dx2', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dy2', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dur', rand(9, 16).toFixed(1) + 's');
        el.style.setProperty('--delay', (-rand(0, 16)).toFixed(1) + 's');
        el.style.setProperty('--scale', rand(.88, 1.12).toFixed(2));

        el.addEventListener('click', () => openFocus(el, entry));
        return el;
    }

    function addEntry(entry) {
        const el = makeBubble(entry);
        el.style.visibility = 'hidden';
        stage.appendChild(el);
        place(el);
        el.style.visibility = '';
        if (!firstLoad && !reduceMotion) {
            el.classList.add('is-new');
            setTimeout(() => el.classList.remove('is-new'), 6000);
        }
        seen.set(entry.id, { entry, el });
    }

    /* ---------- Enlarge / close -------------------------------------------- */
    function openFocus(bubble, entry) {
        if (focused) return closeFocus();

        const card = focusTpl.content.firstElementChild.cloneNode(true);
        card.querySelector('.focus-priority').textContent = entry.priority;
        card.querySelector('.focus-name').textContent = entry.name;
        card.querySelector('.focus-company').textContent = entry.company;
        const t = card.querySelector('.focus-date');
        t.dateTime = entry.createdAt;
        t.textContent = new Date(entry.createdAt).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        document.body.appendChild(card);

        // Grow out of the bubble's current position into the centre (FLIP)
        const from = bubble.getBoundingClientRect();
        const to = card.getBoundingClientRect();
        const dx = (from.left + from.width / 2) - (to.left + to.width / 2);
        const dy = (from.top + from.height / 2) - (to.top + to.height / 2);
        const s = Math.min(from.width / to.width, from.height / to.height);

        bubble.classList.add('is-source');
        backdrop.hidden = false;
        requestAnimationFrame(() => backdrop.classList.add('is-visible'));

        if (!reduceMotion) {
            card.animate([
                { transform: `translate(-50%, -50%) translate(${dx}px, ${dy}px) scale(${s})`, opacity: .4 },
                { transform: 'translate(-50%, -50%)', opacity: 1 }
            ], { duration: 450, easing: 'cubic-bezier(.2,.8,.2,1)' });
        }

        card.addEventListener('click', closeFocus);
        card.focus({ preventScroll: true });
        focused = { bubble, card, from: { dx, dy, s } };
    }

    function closeFocus() {
        if (!focused) return;
        const { bubble, card, from } = focused;
        focused = null;

        backdrop.classList.remove('is-visible');
        const done = () => {
            card.remove();
            bubble.classList.remove('is-source');
            backdrop.hidden = true;
            bubble.focus({ preventScroll: true });
        };

        if (reduceMotion) return done();
        card.animate([
            { transform: 'translate(-50%, -50%)', opacity: 1 },
            { transform: `translate(-50%, -50%) translate(${from.dx}px, ${from.dy}px) scale(${from.s})`, opacity: 0 }
        ], { duration: 350, easing: 'cubic-bezier(.4,0,.6,1)', fill: 'forwards' }).onfinish = done;
    }

    backdrop.addEventListener('click', closeFocus);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFocus(); });

    /* ---------- Polling ------------------------------------------------------ */
    function update(entries) {
        entries.forEach(entry => { if (!seen.has(entry.id)) addEntry(entry); });
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
