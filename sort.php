<?php require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>Strategic priorities – sort into buckets</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/float.css">
    <link rel="stylesheet" href="css/sort.css">
    <style>
        /* Bucket colours from config.php */
<?php foreach (BUCKETS as $key => $b): ?>
        [data-bucket="<?= htmlspecialchars($key) ?>"] { --bucket-color: <?= htmlspecialchars($b['color']) ?>; }
        .stage[data-filter="<?= htmlspecialchars($key) ?>"] .bubble[data-bucket="<?= htmlspecialchars($key) ?>"] { opacity: 1; }
<?php endforeach; ?>
    </style>
</head>
<body class="page-float page-sort">

<!-- Area the entries float around in (everything above the buckets) -->
<main id="stage" class="stage" aria-label="Strategic priorities"></main>

<p id="empty-state" class="float-empty">Waiting for the first entry…</p>

<!-- Drop targets -->
<section id="buckets" class="buckets" aria-label="Buckets">
<?php foreach (BUCKETS as $key => $b): ?>
    <button type="button" class="bucket" data-bucket="<?= htmlspecialchars($key) ?>" aria-pressed="false">
        <span class="bucket-label"><?= htmlspecialchars($b['label']) ?></span>
        <span class="bucket-count"><span class="bucket-num">0</span> <span class="bucket-unit">priorities</span></span>
    </button>
<?php endforeach; ?>
</section>

<!-- Overlay for the enlarged entry -->
<div id="focus-backdrop" class="focus-backdrop" hidden></div>

<!-- Small message for save errors -->
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>

<!-- Card template: small floating version -->
<template id="bubble-template">
    <button type="button" class="bubble">
        <span class="bubble-inner">
            <span class="bubble-tag" aria-hidden="true"></span>
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
        <div class="focus-buckets" role="group" aria-label="Choose a bucket"></div>
        <p class="focus-hint">Tap a bucket, or tap the card to close</p>
    </article>
</template>

<script>
(function () {
    const BUCKETS = <?= json_encode(BUCKETS, JSON_UNESCAPED_SLASHES) ?>;

    const POLL_MS = 3000;       // how often to check for new entries and other people's changes
    const DRIFT_PX = 28;        // how far each card wanders from its spot
    const EDGE = 24;            // keep cards this far from the edge of the floating area
    const COVERAGE = 0.42;      // share of the floating area the cards together should fill
    const IS_TOUCH = window.matchMedia('(pointer: coarse)').matches;
    const MIN_FIT = IS_TOUCH ? 0.55 : 0.4;  // smallest the cards will get
    const MAX_FIT = 2.4;        // biggest the cards will get when there are only a few
    const DRAG_START_PX = 8;    // how far a finger/mouse must move before a tap becomes a drag

    const stage = document.getElementById('stage');
    const bucketBar = document.getElementById('buckets');
    const bucketEls = [...bucketBar.querySelectorAll('.bucket')];
    const backdrop = document.getElementById('focus-backdrop');
    const emptyEl = document.getElementById('empty-state');
    const toast = document.getElementById('toast');
    const bubbleTpl = document.getElementById('bubble-template');
    const focusTpl = document.getElementById('focus-template');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const seen = new Map();     // id -> { entry, el }
    const pending = new Map();  // id -> number of saves still in flight (poll won't overwrite these)
    let etag = null;
    let firstLoad = true;
    let focused = null;
    let fit = 1;
    let baseArea = 0;
    let drag = null;
    let filter = null;          // bucket key currently highlighted, or null

    const rand = (min, max) => min + Math.random() * (max - min);

    /* ---------- Sizing (same as the floating wall) ------------------------- */
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

    /* ---------- Placement (same as the floating wall) ---------------------- */
    function clampCentre(el, cx, cy) {
        const W = stage.clientWidth, H = stage.clientHeight;
        const drift = DRIFT_PX * fit;
        const halfW = el.offsetWidth / 2 + EDGE + drift;
        const halfH = el.offsetHeight / 2 + EDGE + drift;
        return {
            cx: Math.min(Math.max(cx, halfW), Math.max(halfW, W - halfW)),
            cy: Math.min(Math.max(cy, halfH), Math.max(halfH, H - halfH))
        };
    }

    function setPos(el, cx, cy) {
        const W = stage.clientWidth, H = stage.clientHeight;
        const pos = { cx: cx / W * 100, cy: cy / H * 100 };
        el.style.left = pos.cx + '%';
        el.style.top  = pos.cy + '%';
        el._pos = pos;
    }

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
                const d = Math.hypot((cx - o.cx * W / 100) / el.offsetWidth, (cy - o.cy * H / 100) / el.offsetHeight);
                if (d < score) score = d;
            }
            if (score > bestScore) { bestScore = score; best = { cx, cy }; }
        }
        setPos(el, best.cx, best.cy);
    }

    function placedPositions(except) {
        return [...seen.values()].map(s => s.el).filter(o => o !== except && o._pos).map(o => o._pos);
    }

    /* ---------- Buckets ---------------------------------------------------- */
    function applyBucket(el, bucket) {
        if (bucket && BUCKETS[bucket]) {
            el.dataset.bucket = bucket;
            el.querySelector('.bubble-tag').textContent = BUCKETS[bucket].label;
        } else {
            delete el.dataset.bucket;
            el.querySelector('.bubble-tag').textContent = '';
        }
    }

    function updateCounts() {
        const counts = {};
        for (const { entry } of seen.values()) {
            if (entry.bucket) counts[entry.bucket] = (counts[entry.bucket] || 0) + 1;
        }
        bucketEls.forEach(b => {
            const n = counts[b.dataset.bucket] || 0;
            b.querySelector('.bucket-num').textContent = n;
            b.querySelector('.bucket-unit').textContent = n === 1 ? 'priority' : 'priorities';
        });
    }

    function showToast(text) {
        toast.textContent = text;
        toast.hidden = false;
        clearTimeout(showToast.t);
        showToast.t = setTimeout(() => { toast.hidden = true; }, 4000);
    }

    // Set an entry's bucket on screen straight away, then save it to the server
    async function setBucket(id, bucket) {
        const item = seen.get(id);
        if (!item) return;
        const previous = item.entry.bucket || null;
        bucket = bucket || null;
        if (previous === bucket) return;

        item.entry.bucket = bucket;
        applyBucket(item.el, bucket);
        updateCounts();
        pulseBucket(bucket);

        pending.set(id, (pending.get(id) || 0) + 1);
        try {
            const body = new URLSearchParams({ id, bucket: bucket || '' });
            const res = await fetch('api/tag.php', { method: 'POST', body });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error(data.error || 'HTTP ' + res.status);
        } catch (err) {
            console.error('Could not save bucket', err);
            // Put it back the way it was
            item.entry.bucket = previous;
            applyBucket(item.el, previous);
            updateCounts();
            showToast('Couldn’t save that change – please try again.');
        } finally {
            const n = pending.get(id) - 1;
            n > 0 ? pending.set(id, n) : pending.delete(id);
        }
    }

    function pulseBucket(bucket) {
        const b = bucket && bucketEls.find(x => x.dataset.bucket === bucket);
        if (!b || reduceMotion) return;
        b.classList.remove('is-pulse');
        void b.offsetWidth;
        b.classList.add('is-pulse');
    }

    // Tap a bucket to highlight its cards; tap again to show all
    bucketEls.forEach(b => b.addEventListener('click', () => {
        if (drag) return;
        filter = filter === b.dataset.bucket ? null : b.dataset.bucket;
        if (filter) stage.dataset.filter = filter; else delete stage.dataset.filter;
        bucketEls.forEach(x => x.setAttribute('aria-pressed', String(x.dataset.bucket === filter)));
    }));

    function bucketAt(x, y) {
        return bucketEls.find(b => {
            const r = b.getBoundingClientRect();
            return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
        }) || null;
    }

    /* ---------- Dragging (mouse and touch, via pointer events) -------------- */
    function onPointerDown(e) {
        if (focused || drag || e.button > 0) return;
        const el = e.currentTarget;
        drag = { el, id: el.dataset.id, pointerId: e.pointerId, startX: e.clientX, startY: e.clientY, active: false };
        el.setPointerCapture(e.pointerId);
    }

    function startDrag(e) {
        const { el } = drag;
        const r = el.querySelector('.bubble-inner').getBoundingClientRect();

        // Float a copy above everything (the original is clipped to the floating area)
        const ghost = el.querySelector('.bubble-inner').cloneNode(true);
        ghost.classList.add('drag-ghost');
        if (el.dataset.bucket) ghost.dataset.bucket = el.dataset.bucket;
        ghost.style.fontSize = getComputedStyle(el).fontSize;
        ghost.style.width = r.width + 'px';
        ghost.style.left = r.left + 'px';
        ghost.style.top = r.top + 'px';
        document.body.appendChild(ghost);

        drag.active = true;
        drag.ghost = ghost;
        drag.offsetX = e.clientX - r.left;
        drag.offsetY = e.clientY - r.top;
        drag.rect = r;
        el.classList.add('is-source');
        document.body.classList.add('is-dragging');
    }

    function onPointerMove(e) {
        if (!drag || e.pointerId !== drag.pointerId) return;
        if (!drag.active) {
            if (Math.hypot(e.clientX - drag.startX, e.clientY - drag.startY) < DRAG_START_PX) return;
            startDrag(e);
        }
        const x = e.clientX - drag.offsetX, y = e.clientY - drag.offsetY;
        drag.ghost.style.transform = `translate(${x - drag.rect.left}px, ${y - drag.rect.top}px) rotate(-2deg) scale(1.06)`;

        const over = bucketAt(e.clientX, e.clientY);
        bucketEls.forEach(b => b.classList.toggle('is-over', b === over));
    }

    function onPointerUp(e) {
        if (!drag || e.pointerId !== drag.pointerId) return;
        const d = drag;
        drag = null;
        bucketEls.forEach(b => b.classList.remove('is-over'));
        document.body.classList.remove('is-dragging');

        if (!d.active) return;          // it was a tap: the click handler opens the card
        d.el._suppressClick = true;     // stop the click that follows a drag from opening it
        setTimeout(() => { d.el._suppressClick = false; }, 60);

        const over = e.type === 'pointerup' ? bucketAt(e.clientX, e.clientY) : null;
        if (over) {
            setBucket(d.id, over.dataset.bucket);
            finishGhost(d, over.getBoundingClientRect());
        } else if (e.type === 'pointerup' && e.clientY < stage.getBoundingClientRect().bottom) {
            // Dropped somewhere in the floating area: leave the card where it was dropped
            const s = stage.getBoundingClientRect();
            const c = clampCentre(d.el, e.clientX - d.offsetX + d.rect.width / 2 - s.left, e.clientY - d.offsetY + d.rect.height / 2 - s.top);
            setPos(d.el, c.cx, c.cy);
            d.ghost.remove();
            d.el.classList.remove('is-source');
        } else {
            finishGhost(d, null);
        }
    }

    // Shrink the dragged copy into the bucket (or glide it back home), then show the real card again
    function finishGhost(d, targetRect) {
        const done = () => { d.ghost.remove(); d.el.classList.remove('is-source'); };
        if (reduceMotion) return done();
        const g = d.ghost.getBoundingClientRect();
        let to;
        if (targetRect) {
            const dx = targetRect.left + targetRect.width / 2 - (g.left + g.width / 2);
            const dy = targetRect.top + targetRect.height / 2 - (g.top + g.height / 2);
            to = `${d.ghost.style.transform} translate(${dx}px, ${dy}px) scale(.2)`;
        } else {
            to = 'translate(0, 0)';
        }
        d.ghost.animate([{ transform: d.ghost.style.transform || 'none', opacity: 1 }, { transform: to, opacity: targetRect ? 0 : 1 }],
            { duration: 320, easing: 'cubic-bezier(.4,0,.2,1)', fill: 'forwards' }).onfinish = done;
    }

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerUp);

    /* ---------- Cards ------------------------------------------------------- */
    function makeBubble(entry) {
        const el = bubbleTpl.content.firstElementChild.cloneNode(true);
        el.dataset.id = entry.id;
        el.querySelector('.bubble-priority').textContent = entry.priority;
        el.querySelector('.bubble-name').textContent = entry.name + ', ' + entry.company;
        el.setAttribute('aria-label', entry.priority + ' – ' + entry.name + ', ' + entry.company);
        applyBucket(el, entry.bucket);

        el.style.setProperty('--dx1', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dy1', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dx2', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dy2', rand(-DRIFT_PX, DRIFT_PX) + 'px');
        el.style.setProperty('--dur', rand(9, 16).toFixed(1) + 's');
        el.style.setProperty('--delay', (-rand(0, 16)).toFixed(1) + 's');
        el.style.setProperty('--scale', rand(.88, 1.12).toFixed(2));

        el.addEventListener('pointerdown', onPointerDown);
        el.addEventListener('click', () => {
            if (el._suppressClick) { el._suppressClick = false; return; }
            openFocus(el, seen.get(entry.id).entry);
        });
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
    let lastToggle = 0;
    function tooSoon() {
        const now = Date.now();
        if (now - lastToggle < 400) return true;
        lastToggle = now;
        return false;
    }

    function renderFocusBuckets(card, entry) {
        const wrap = card.querySelector('.focus-buckets');
        wrap.innerHTML = '';
        const options = [...Object.entries(BUCKETS).map(([key, b]) => ({ key, label: b.label })), { key: '', label: 'None' }];
        options.forEach(({ key, label }) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'focus-bucket';
            if (key) btn.dataset.bucket = key; else btn.classList.add('focus-bucket--none');
            btn.textContent = label;
            btn.setAttribute('aria-pressed', String((entry.bucket || '') === key));
            btn.addEventListener('click', ev => {
                ev.stopPropagation();       // don't close the card
                setBucket(entry.id, key);
                renderFocusBuckets(card, entry);
                card.dataset.bucket = key;
                if (!key) delete card.dataset.bucket;
            });
            wrap.appendChild(btn);
        });
    }

    function openFocus(bubble, entry) {
        if (tooSoon()) return;
        if (focused) return closeFocus();

        const card = focusTpl.content.firstElementChild.cloneNode(true);
        card.querySelector('.focus-priority').textContent = entry.priority;
        card.querySelector('.focus-name').textContent = entry.name;
        card.querySelector('.focus-company').textContent = entry.company;
        const t = card.querySelector('.focus-date');
        t.dateTime = entry.createdAt;
        t.textContent = new Date(entry.createdAt).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        if (entry.bucket) card.dataset.bucket = entry.bucket;
        renderFocusBuckets(card, entry);
        document.body.appendChild(card);

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

    function closeFocus(e) {
        if (!focused) return;
        const viaKeyboard = e && e.type === 'keydown';
        if (!viaKeyboard && e && tooSoon()) return;
        const { bubble, card, from } = focused;
        focused = null;

        backdrop.classList.remove('is-visible');
        const done = () => {
            card.remove();
            bubble.classList.remove('is-source');
            backdrop.hidden = true;
            if (viaKeyboard) bubble.focus({ preventScroll: true });
        };

        if (reduceMotion) return done();
        card.animate([
            { transform: 'translate(-50%, -50%)', opacity: 1 },
            { transform: `translate(-50%, -50%) translate(${from.dx}px, ${from.dy}px) scale(${from.s})`, opacity: 0 }
        ], { duration: 350, easing: 'cubic-bezier(.4,0,.6,1)', fill: 'forwards' }).onfinish = done;
    }

    backdrop.addEventListener('click', closeFocus);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFocus(e); });

    /* ---------- Polling ------------------------------------------------------ */
    function update(entries) {
        // Pick up bucket changes made on other screens
        for (const e of entries) {
            const item = seen.get(e.id);
            if (!item || pending.has(e.id) || (drag && drag.id === e.id)) continue;
            if ((item.entry.bucket || null) !== (e.bucket || null)) {
                item.entry.bucket = e.bucket || null;
                applyBucket(item.el, item.entry.bucket);
            }
        }

        const added = entries.filter(e => !seen.has(e.id)).map(addEntry);
        if (added.length) {
            measureBaseArea();
            setFit(targetFit());
            if (!firstLoad) stage.classList.add('is-ready');
            added.forEach(el => { el.style.transition = 'none'; });
            added.forEach(el => place(el, placedPositions(el)));
            added.forEach(el => { void el.offsetWidth; el.style.transition = ''; reveal(el); });
        }
        updateCounts();
        emptyEl.hidden = seen.size > 0;
        firstLoad = false;
    }

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
<script src="js/touch.js"></script>
</body>
</html>
