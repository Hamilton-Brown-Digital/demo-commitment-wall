<?php require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en" class="vote-root">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>Cast your commitment</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/vote.css">
</head>
<body class="page-form page-vote">

<!-- Thank-you message shown after each slip goes in -->
<div id="vote-thanks" class="vote-thanks" role="status" aria-live="polite" hidden></div>

<main class="vote-scene">

    <!-- Intro (shown on taller screens) -->
    <header class="vote-intro">
        <h2 class="vote-intro-title">Make your commitment</h2>
        <p class="vote-intro-text">Fill in your slip and drop it in the box.</p>
    </header>

    <!-- The voting slip (a normal form underneath) -->
    <div class="slip-holder" id="slip-holder">
        <form id="slip" class="slip" novalidate>
            <header class="slip-head">
                <span class="slip-kicker">Strategic priorities</span>
                <h1 class="slip-title">Commitment slip</h1>
                <span class="slip-no">No. <span class="slip-no-value">0001</span></span>
            </header>

            <div class="slip-body">
                <div class="field slip-field">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" maxlength="<?= MAX_NAME ?>" required autocomplete="off">
                    <span class="field-error" data-for="name"></span>
                </div>

                <div class="field slip-field">
                    <label for="company">Company</label>
                    <input type="text" id="company" name="company" maxlength="<?= MAX_COMPANY ?>" required autocomplete="off">
                    <span class="field-error" data-for="company"></span>
                </div>

                <!-- Dashed fold guide across the middle of the slip -->
                <div class="slip-fold-line" aria-hidden="true"></div>

                <div class="field slip-field">
                    <label for="priority">My strategic priority</label>
                    <textarea id="priority" name="priority" maxlength="<?= MAX_PRIORITY ?>" rows="3" required></textarea>
                    <span class="char-count"><span class="char-remaining"><?= MAX_PRIORITY ?></span> characters remaining</span>
                    <span class="field-error" data-for="priority"></span>
                </div>
            </div>

            <button type="submit" class="slip-submit">Cast my commitment</button>
            <div class="form-message form-message--error slip-error" role="alert" hidden></div>
        </form>
    </div>

    <!-- Ballot box behind the slip -->
    <div class="ballot-box" id="ballot-box" aria-hidden="true">
        <div class="box-lid">
            <div class="box-slot" id="box-slot"></div>
        </div>
        <div class="box-front">
            <span class="box-label">Commitments</span>
            <span class="box-count"><span id="box-count">0</span> cast</span>
        </div>
    </div>
</main>

<script>
(function () {
    const MAX = <?= MAX_PRIORITY ?>;
    const holder = document.getElementById('slip-holder');
    const form = document.getElementById('slip');
    const btn = form.querySelector('.slip-submit');
    const errorBox = form.querySelector('.slip-error');
    const priority = form.elements.priority;
    const remaining = form.querySelector('.char-remaining');
    const thanks = document.getElementById('vote-thanks');
    const box = document.getElementById('ballot-box');
    const slot = document.getElementById('box-slot');
    const countEl = document.getElementById('box-count');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isTouch = window.matchMedia('(pointer: coarse)').matches;

    let count = 0;

    /* ---------- Counter / slip number ------------------------------------- */
    function setCount(n) {
        count = n;
        countEl.textContent = n;
        form.querySelector('.slip-no-value').textContent = String(n + 1).padStart(4, '0');
    }
    fetch('api/entries.php', { cache: 'no-store' })
        .then(r => r.json()).then(d => { if (d.ok) setCount(d.entries.length); })
        .catch(() => {});

    /* ---------- Form behaviour (same as index.php) ------------------------ */
    function updateCount() {
        const len = Array.from(priority.value).length;
        remaining.textContent = MAX - len;
        remaining.parentElement.classList.toggle('is-low', MAX - len <= 20);
    }
    priority.addEventListener('input', updateCount);

    // Clear a field's error as soon as it's corrected
    form.addEventListener('input', e => {
        const field = e.target.name;
        if (!field || !e.target.value.trim()) return;
        e.target.classList.remove('has-error');
        const el = form.querySelector('.field-error[data-for="' + field + '"]');
        if (el) el.textContent = '';
    });

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => el.textContent = '');
        form.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
        errorBox.hidden = true;
    }

    function showErrors(errors) {
        Object.entries(errors).forEach(([field, text]) => {
            const el = form.querySelector('.field-error[data-for="' + field + '"]');
            if (el) el.textContent = text;
            if (form.elements[field]) form.elements[field].classList.add('has-error');
        });
        if (!reduceMotion) form.animate(
            [{ transform: 'translateX(0)' }, { transform: 'translateX(-10px)' }, { transform: 'translateX(10px)' },
             { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }],
            { duration: 420, easing: 'ease-in-out' });
    }

    function showError(text) {
        errorBox.textContent = text;
        errorBox.hidden = false;
    }

    function showThanks(name) {
        thanks.textContent = 'Thank you, ' + name + ' – your commitment is in the box.';
        thanks.hidden = false;
        thanks.classList.remove('is-visible');
        void thanks.offsetWidth;
        thanks.classList.add('is-visible');
        clearTimeout(showThanks.t);
        showThanks.t = setTimeout(() => { thanks.classList.remove('is-visible'); }, 3500);
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (btn.disabled) return;
        clearErrors();

        const errors = {};
        if (!form.elements.name.value.trim()) errors.name = 'Please enter your name.';
        if (!form.elements.company.value.trim()) errors.company = 'Please enter your company.';
        if (!priority.value.trim()) errors.priority = 'Please enter a strategic priority.';
        if (Object.keys(errors).length) { showErrors(errors); return; }

        btn.disabled = true;
        if (document.activeElement) document.activeElement.blur();   // close the on-screen keyboard

        let data;
        try {
            const res = await fetch('api/submit.php', { method: 'POST', body: new FormData(form) });
            const text = await res.text();
            try { data = JSON.parse(text); }
            catch (_) {
                console.error('Non-JSON response from api/submit.php (HTTP ' + res.status + '):', text);
                showError('Server error (HTTP ' + res.status + '). See the browser console for details.');
                btn.disabled = false;
                return;
            }
        } catch (err) {
            console.error(err);
            showError('Could not connect to the server. Please try again.');
            btn.disabled = false;
            return;
        }

        if (!data.ok) {
            if (data.errors) showErrors(data.errors); else showError(data.error || 'Something went wrong. Please try again.');
            btn.disabled = false;
            return;
        }

        // Saved – now play the fold-and-drop, then bring in a fresh slip
        await castSlip();
        setCount(count + 1);
        showThanks(data.entry.name);
        form.reset();
        updateCount();
        await newSlip();
        btn.disabled = false;
        if (!isTouch) form.elements.name.focus({ preventScroll: true });
    });

    /* ---------- Animation -------------------------------------------------- */
    const wait = ms => new Promise(r => setTimeout(r, ms));
    const run = (el, frames, opts) => el.animate(frames, Object.assign({ fill: 'forwards' }, opts)).finished;

    // A static copy of the filled-in slip, used for the animation
    function snapshot() {
        const copy = form.cloneNode(true);
        copy.removeAttribute('id');
        copy.classList.add('is-print');
        copy.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
        copy.querySelector('[name="name"]').value = form.elements.name.value;
        copy.querySelector('[name="company"]').value = form.elements.company.value;
        copy.querySelector('[name="priority"]').value = priority.value;
        copy.querySelectorAll('input, textarea, button').forEach(el => { el.tabIndex = -1; el.readOnly = true; });
        copy.setAttribute('aria-hidden', 'true');
        return copy;
    }

    function abs(el, r) {
        el.style.position = 'fixed';
        el.style.left = r.left + 'px';
        el.style.top = r.top + 'px';
        el.style.width = r.width + 'px';
        el.style.height = r.height + 'px';
        el.style.margin = '0';
    }

    async function castSlip() {
        const r = form.getBoundingClientRect();

        if (reduceMotion) {
            await run(holder, [{ opacity: 1 }, { opacity: 0 }], { duration: 250 });
            return;
        }

        // 1. Fold the top half down over the bottom half
        const rig = document.createElement('div');
        rig.className = 'fold-rig';
        abs(rig, r);

        const bottom = snapshot();
        bottom.classList.add('fold-bottom');

        const flap = document.createElement('div');
        flap.className = 'fold-flap';
        const flapFront = snapshot();
        flapFront.classList.add('fold-front');
        const flapBack = document.createElement('div');
        flapBack.className = 'fold-back';
        flap.append(flapFront, flapBack);

        rig.append(bottom, flap);
        document.body.appendChild(rig);
        holder.style.visibility = 'hidden';

        await run(flap, [
            { transform: 'rotateX(0deg)' },
            { transform: 'rotateX(-180deg)' }
        ], { duration: 700, easing: 'cubic-bezier(.55,.05,.35,1)' });

        // Swap the 3D rig for a simple folded slip (the back of the paper is now facing us)
        const folded = document.createElement('div');
        folded.className = 'folded-slip';
        folded.innerHTML = '<span class="folded-mark">Commitment</span>';
        abs(folded, { left: r.left, top: r.top + r.height / 2, width: r.width, height: r.height / 2 });
        document.body.appendChild(folded);
        rig.remove();
        await wait(120);

        // 2. Lift it above the slot and shrink it to fit
        const s = slot.getBoundingClientRect();
        const slotY = s.top + s.height / 2;
        const scale = Math.min(1, (s.width * 0.86) / r.width);
        const fw = r.width * scale, fh = (r.height / 2) * scale;
        const target = { left: s.left + s.width / 2 - fw / 2, top: slotY - fh - 18 };
        const fr = folded.getBoundingClientRect();
        const dx = target.left + fw / 2 - (fr.left + fr.width / 2);
        const dy = target.top + fh / 2 - (fr.top + fr.height / 2);

        await run(folded, [
            { transform: 'translate(0, 0) scale(1) rotate(0deg)' },
            { transform: `translate(${dx * .6}px, ${dy * .6 - 30}px) scale(${(1 + scale) / 2}) rotate(-4deg)`, offset: .6 },
            { transform: `translate(${dx}px, ${dy}px) scale(${scale}) rotate(0deg)` }
        ], { duration: 650, easing: 'cubic-bezier(.3,.7,.3,1)' });

        // 3. Drop it through the slot (a mask hides everything below the slot line)
        const mask = document.createElement('div');
        mask.className = 'slot-mask';
        mask.style.height = slotY + 'px';
        document.body.appendChild(mask);

        const paper = document.createElement('div');
        paper.className = 'folded-slip';
        paper.innerHTML = folded.innerHTML;
        paper.style.position = 'absolute';
        paper.style.left = target.left + 'px';
        paper.style.top = target.top + 'px';
        paper.style.width = fw + 'px';
        paper.style.height = fh + 'px';
        paper.style.fontSize = (scale * 100) + '%';
        mask.appendChild(paper);
        folded.remove();

        await run(paper, [
            { transform: 'translateY(0)' },
            { transform: 'translateY(-6px)', offset: .2 },
            { transform: `translateY(${fh + 30}px)` }
        ], { duration: 520, easing: 'cubic-bezier(.5,0,.8,.4)' });
        mask.remove();

        // 4. The box gives a little bump as it lands
        box.animate([
            { transform: 'translateY(0) scaleY(1)' },
            { transform: 'translateY(4px) scaleY(.985)' },
            { transform: 'translateY(0) scaleY(1)' }
        ], { duration: 320, easing: 'ease-out' });
        slot.animate([{ boxShadow: '0 0 0 0 rgba(253,81,8,.6)' }, { boxShadow: '0 0 0 14px rgba(253,81,8,0)' }], { duration: 600 });
        await wait(250);
    }

    // Bring in the next blank slip from above
    async function newSlip() {
        holder.getAnimations().forEach(a => a.cancel());
        holder.style.visibility = '';
        if (reduceMotion) {
            await run(holder, [{ opacity: 0 }, { opacity: 1 }], { duration: 250 });
            holder.getAnimations().forEach(a => a.cancel());
            return;
        }
        await run(holder, [
            { transform: 'translateY(-110vh) rotate(-3deg)', opacity: 1 },
            { transform: 'translateY(12px) rotate(.5deg)', offset: .8 },
            { transform: 'translateY(0) rotate(0deg)' }
        ], { duration: 700, easing: 'cubic-bezier(.2,.8,.2,1)' });
        holder.getAnimations().forEach(a => a.cancel());
    }
})();
</script>
<script src="js/touch.js"></script>
</body>
</html>
