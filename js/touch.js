/* ==========================================================================
   Touch-screen / kiosk helpers – loaded on every page
   - stops pinch and double-tap zoom
   - stops long-press text selection and the copy/share menu
   - on the form page: keeps the focused field and Submit button visible
     above the on-screen keyboard
   ========================================================================== */
(function () {
    const isFormField = el => el && el.closest && el.closest('input, textarea, select, [contenteditable]');

    /* ---- No zooming ---- */
    // iOS Safari ignores user-scalable=no, so block its pinch gesture events directly
    ['gesturestart', 'gesturechange', 'gestureend'].forEach(type =>
        document.addEventListener(type, e => e.preventDefault(), { passive: false }));

    // Two-finger pinch on browsers that send it as touchmove
    document.addEventListener('touchmove', e => {
        if (e.touches.length > 1) e.preventDefault();
    }, { passive: false });

    // Trackpad pinch / Ctrl + scroll on a desktop kiosk
    document.addEventListener('wheel', e => {
        if (e.ctrlKey) e.preventDefault();
    }, { passive: false });

    // Ctrl/Cmd + plus/minus/zero keyboard zoom
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && ['+', '=', '-', '_', '0'].includes(e.key)) e.preventDefault();
    });

    /* ---- No long-press menu (still allowed in form fields for paste) ---- */
    document.addEventListener('contextmenu', e => {
        if (!isFormField(e.target)) e.preventDefault();
    });

    /* ---- On-screen keyboard (form page only) ---- */
    if (!document.body.classList.contains('page-form')) return;

    // Scroll the field being typed in to the middle of what's still visible
    document.addEventListener('focusin', e => {
        if (!isFormField(e.target)) return;
        setTimeout(() => e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }), 300);
    });

    // Where supported, measure the keyboard and add room below the form for it
    if (window.visualViewport) {
        const vv = window.visualViewport;
        const onResize = () => {
            const keyboard = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
            const open = keyboard > 80;
            document.body.classList.toggle('keyboard-open', open);
            document.body.style.paddingBottom = open ? (keyboard + 24) + 'px' : '';
        };
        vv.addEventListener('resize', onResize);
        vv.addEventListener('scroll', onResize);
    }
})();
