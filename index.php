<?php require __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Share your strategic priority</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-form">

<main class="form-wrap">
    <h1>Share your strategic priority</h1>

    <form id="entry-form" novalidate>
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="<?= MAX_NAME ?>" required autocomplete="off">
            <span class="field-error" data-for="name"></span>
        </div>

        <div class="field">
            <label for="company">Company</label>
            <input type="text" id="company" name="company" maxlength="<?= MAX_COMPANY ?>" required autocomplete="off">
            <span class="field-error" data-for="company"></span>
        </div>

        <div class="field">
            <label for="priority">Strategic priority</label>
            <textarea id="priority" name="priority" maxlength="<?= MAX_PRIORITY ?>" rows="5" required></textarea>
            <span class="char-count"><span id="char-remaining"><?= MAX_PRIORITY ?></span> characters remaining</span>
            <span class="field-error" data-for="priority"></span>
        </div>

        <button type="submit" id="submit-btn">Submit</button>
    </form>

    <div id="form-message" class="form-message" role="status" aria-live="polite" hidden></div>
</main>

<script>
(function () {
    const MAX = <?= MAX_PRIORITY ?>;
    const form = document.getElementById('entry-form');
    const btn = document.getElementById('submit-btn');
    const msg = document.getElementById('form-message');
    const priority = document.getElementById('priority');
    const remaining = document.getElementById('char-remaining');
    let msgTimer;

    function updateCount() {
        // Array.from counts emoji etc. as one character, matching PHP's mb_strlen
        const len = Array.from(priority.value).length;
        remaining.textContent = MAX - len;
        remaining.parentElement.classList.toggle('is-low', MAX - len <= 20);
    }
    priority.addEventListener('input', updateCount);

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => el.textContent = '');
        form.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
    }

    function showErrors(errors) {
        Object.entries(errors).forEach(([field, text]) => {
            const el = form.querySelector('.field-error[data-for="' + field + '"]');
            if (el) el.textContent = text;
            const input = form.elements[field];
            if (input) input.classList.add('has-error');
        });
    }

    function showMessage(text, type) {
        clearTimeout(msgTimer);
        msg.textContent = text;
        msg.className = 'form-message form-message--' + type;
        msg.hidden = false;
        msgTimer = setTimeout(() => { msg.hidden = true; }, 5000);
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearErrors();

        // Quick client-side check (server validates too)
        const errors = {};
        if (!form.elements.name.value.trim()) errors.name = 'Please enter your name.';
        if (!form.elements.company.value.trim()) errors.company = 'Please enter your company.';
        if (!priority.value.trim()) errors.priority = 'Please enter a strategic priority.';
        if (Object.keys(errors).length) { showErrors(errors); return; }

        btn.disabled = true;
        try {
            const res = await fetch('api/submit.php', { method: 'POST', body: new FormData(form) });
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseErr) {
                console.error('Non-JSON response from api/submit.php (HTTP ' + res.status + '):', text);
                showMessage('Server error (HTTP ' + res.status + '). See the browser console for details.', 'error');
                return;
            }

            if (data.ok) {
                form.reset();
                updateCount();
                showMessage('Thank you for submitting, ' + data.entry.name + '!', 'success');
                form.elements.name.focus();
            } else if (data.errors) {
                showErrors(data.errors);
            } else {
                showMessage(data.error || 'Something went wrong. Please try again.', 'error');
            }
        } catch (err) {
            console.error(err);
            showMessage('Could not connect to the server. Please try again.', 'error');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
</body>
</html>
