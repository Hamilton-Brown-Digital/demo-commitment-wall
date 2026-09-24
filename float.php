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
    const COVERAGE = 0.42;      // share of the screen the cards together should fill; sets the card size
    const MIN_FIT = 0.4;        // smallest the cards will get (0.4 = 40% of normal size)
    const MAX_FIT = 2.4;        // biggest the cards will get when there are only a few (2.4 = 240%)

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
    let fit = 1;                // current card scale, applied as --fit on the stage
    let baseArea = 0;           // average card area (px²) at full size

    const rand = (min, max) => min + Math.random() * (max - min);

    /* ---------- Sizing ------------------------------------------------------
       Cards shrink as the wall fills up: the scale is picked so all cards
       together cover roughly COVERAGE of the screen. */
    function measureBaseArea() {
        const els = [...seen.values()].map(s => s.el);
        if (!els.length) return;
        const total = els.reduce((sum, el) => sum + el.offsetWidth * el.offsetHeight, 0);
        baseArea = total / els.length / (fit * fit);
    }

    function targetFit() {
        const n = seen.size;
        if (!n || !baseArea) return 1;
        const f = Math.sqrt(COVERAGE * stage.clientWidth * stage.clientHeight / (n * baseArea));
        return Math.max(MIN_FIT, Math.min(MAX_FIT, f));
    }

    function setFit(f) {
        fit = f;
        stage.style.setProperty('--fit', f.toFixed(3));
    }

    /* ---------- Placement ---------------------------------------------------
       "Best candidate" sampling: try random spots and keep the one furthest
       from existing cards, so they spread out evenly but randomly.
       Positions are card centres, stored as % so they survive resizes. */
    function place(el, others) {
        const W = stage.clientWidth, H = stage.clientHeight;
        const drift = DRIFT_PX * fit;
        const halfW = el.offsetWidth / 2 + EDGE + drift;
        const halfH = el.offsetHeight / 2 + EDGE + drift;
        let best = null, bestScore = -1;

        for (let i = 0; i < 40; i++) {
            const cx = rand(halfW, Math.max(halfW, W - halfW));
            const cy = rand(halfH, Math.max(halfH, H - halfH));
            let score = Infinity;
            for (const o of others) {
                // Compare in "card units" so wide screens don't bias spacing
                const d = Math.hypot((cx - o.cx * W / 100) / el.offsetWidth, (cy - o.cy * H / 100) / el.offsetHeight);
                if (d < score) score = d;
            }
            if (score > bestScore) { bestScore = score; best = { cx, cy }; }
        }

        const pos = { cx: best.cx / W * 100, cy: best.cy / H * 100 };
        el.style.left = pos.cx + '%';
        el.style.top  = pos.cy + '%';
        el._pos = pos;
        return pos;
    }

    function placedPositions(except) {
        return [...seen.values()].map(s => s.el).filter(o => o !== except && o._pos).map(o => o._pos);
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
        seen.set(entry.id, { entry, el });
        return el;
    }

    function reveal(el) {
        el.style.visibility = '';
        if (!firstLoad && !reduceMotion) {
            el.classList.add('is-new');
            setTimeout(() => el.classList.remove('is-new'), 6000);
        }
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
        const added = entries.filter(e => !seen.has(e.id)).map(addEntry);
        if (added.length) {
            // Work out the new card size, then position the new cards at that size
            measureBaseArea();
            const prevFit = fit;
            setFit(targetFit());
            if (!firstLoad) stage.classList.add('is-ready');

            // Existing cards shrink in place (CSS transition); new cards need their
            // final size for placement, so measure them without the transition.
            added.forEach(el => { el.style.transition = 'none'; });
            added.forEach(el => place(el, placedPositions(el)));
            added.forEach(el => { void el.offsetWidth; el.style.transition = ''; reveal(el); });
        }
        emptyEl.hidden = seen.size > 0;
        firstLoad = false;
    }

    // Re-check sizing when the window changes size (e.g. moving to a bigger screen)
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { measureBaseArea(); setFit(targetFit()); }, 250);
    });

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
