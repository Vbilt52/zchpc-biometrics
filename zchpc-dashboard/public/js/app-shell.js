(() => {
    const toggle = document.querySelector('.sidebar-toggle-btn');
    const sidebar = document.getElementById('app-navigation');
    const backdrop = document.querySelector('.nav-backdrop');
    const close = document.querySelector('.nav-close');
    const main = document.querySelector('.main-content');
    const mobile = window.matchMedia('(max-width: 900px)');
    if (!toggle || !sidebar) return;
    let collapsed = false;
    let mobileOpen = false;
    try { collapsed = localStorage.getItem('zchpc-navigation-collapsed') === 'true'; } catch (_) { /* Storage may be disabled. */ }
    function render() {
        const open = mobile.matches ? mobileOpen : !collapsed;
        document.body.classList.toggle('nav-collapsed', !mobile.matches && collapsed);
        document.body.classList.toggle('nav-open', mobile.matches && mobileOpen);
        toggle.setAttribute('aria-expanded', String(open));
        sidebar.inert = !open;
        main.inert = mobile.matches && mobileOpen;
        backdrop.hidden = !(mobile.matches && mobileOpen);
    }
    function dismiss() {
        mobileOpen = false;
        render();
        toggle.focus();
    }
    toggle.addEventListener('click', () => {
        if (mobile.matches) {
            mobileOpen = !mobileOpen;
        } else {
            collapsed = !collapsed;
            try { localStorage.setItem('zchpc-navigation-collapsed', String(collapsed)); } catch (_) { /* Optional preference. */ }
        }
        render();
        if (mobile.matches && mobileOpen) close.focus();
    });
    close.addEventListener('click', dismiss);
    backdrop.addEventListener('click', dismiss);
    mobile.addEventListener('change', () => { mobileOpen = false; render(); });
    document.addEventListener('keydown', event => {
        if (!mobile.matches || !mobileOpen) return;
        if (event.key === 'Escape') { dismiss(); return; }
        if (event.key !== 'Tab') return;
        const controls = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled])')).filter(el => el.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    // Wide data grids remain keyboard-scrollable as well as swipeable.
    document.querySelectorAll('.table-wrapper, .matrix-wrapper').forEach(region => {
        region.tabIndex = 0;
        if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
        if (!region.hasAttribute('aria-label')) region.setAttribute('aria-label', 'Scrollable records');
    });
    render();
})();
