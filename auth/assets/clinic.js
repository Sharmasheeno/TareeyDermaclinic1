(() => {
    const trigger = document.querySelector('.profile-trigger');
    const panel = document.getElementById('profile-panel');
    if (!trigger || !panel) return;
    const close = () => { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); };
    trigger.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        trigger.setAttribute('aria-expanded', String(!panel.hidden));
        document.querySelectorAll('[data-menu].open').forEach(item => item.classList.remove('open'));
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.profile-area')) close();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) { close(); trigger.focus(); }
    });
    document.querySelectorAll('[data-menu] .icon-btn').forEach(button => {
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('title', button.getAttribute('aria-label') || 'Notifications');
        new MutationObserver(() => button.setAttribute('aria-expanded', String(button.parentElement.classList.contains('open'))))
            .observe(button.parentElement, { attributes: true, attributeFilter: ['class'] });
    });
})();

document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    if (!form.noValidate && !form.checkValidity()) return;
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    window.setTimeout(() => {
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(control => {
            control.disabled = true;
        });
    }, 0);
});

/* ------------------------------------------------------------------
   Global UI layer: modal scroll-lock, Escape, focus trap, focus return.
   Runs for every page. Pages keep their own open/close helpers; this
   only adds the behaviour they are missing, and stays idempotent.
   ------------------------------------------------------------------ */
(() => {
    const OVERLAY = '.modal-overlay';
    const openOverlays = () => Array.from(document.querySelectorAll(OVERLAY + '.show, ' + OVERLAY + '.is-open'));
    const focusables = root => Array.from(root.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(node => node.offsetParent !== null || node === document.activeElement);

    let lastFocus = null;

    const lock = () => {
        const open = openOverlays();
        document.body.classList.toggle('modal-open', open.length > 0);
        if (open.length === 0) return;
        if (!lastFocus || !document.body.contains(lastFocus)) lastFocus = document.activeElement;
        const panel = open[open.length - 1].querySelector('.modal-box, [role="dialog"]');
        if (panel && !panel.hasAttribute('role')) panel.setAttribute('role', 'dialog');
        if (panel && !panel.hasAttribute('aria-modal')) panel.setAttribute('aria-modal', 'true');
        if (panel && !panel.contains(document.activeElement)) {
            const items = focusables(panel);
            (items[0] || panel).focus?.({ preventScroll: true });
        }
    };

    const closeTop = overlay => {
        overlay.classList.remove('show', 'is-open');
        overlay.setAttribute('aria-hidden', 'true');
        const panel = overlay.querySelector('.modal-box');
        if (panel) panel.setAttribute('aria-hidden', 'true');
        if (openOverlays().length === 0) {
            document.body.classList.remove('modal-open');
            const target = lastFocus;
            lastFocus = null;
            if (target && document.body.contains(target)) target.focus({ preventScroll: true });
        }
    };

    new MutationObserver(lock).observe(document.body, {
        subtree: true, attributes: true, attributeFilter: ['class']
    });

    document.addEventListener('keydown', event => {
        const open = openOverlays();
        if (open.length === 0) return;

        if (event.key === 'Escape') {
            const top = open[open.length - 1];
            if (top.dataset.static === 'true') return;
            if (!document.body.classList.contains('modal-open')) return;
            event.preventDefault();
            closeTop(top);
            return;
        }

        if (event.key !== 'Tab') return;
        const panel = open[open.length - 1].querySelector('.modal-box');
        if (!panel) return;
        const items = focusables(panel);
        if (items.length === 0) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }, true);

    document.addEventListener('click', event => {
        const overlay = event.target.closest(OVERLAY);
        if (!overlay || event.target !== overlay) return;
        if (overlay.dataset.static === 'true') return;
        if (!document.body.classList.contains('modal-open')) return;
        closeTop(overlay);
    });
})();
/* =====================================================================
   Shared UI behaviors (auth/includes/ui.php components)
   Action menus, date-range validation, print, toast helpers.
   ===================================================================== */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    function closeAllMenus(except) {
        document.querySelectorAll('.action-menu.open').forEach(function (menu) {
            if (menu === except) return;
            menu.classList.remove('open');
            var trigger = menu.querySelector('.action-menu-trigger');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    function initActionMenus(root) {
        (root || document).querySelectorAll('.action-menu').forEach(function (menu) {
            if (menu.dataset.bound === '1') return;
            menu.dataset.bound = '1';
            var trigger = menu.querySelector('.action-menu-trigger');
            if (!trigger) return;
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var willOpen = !menu.classList.contains('open');
                closeAllMenus(menu);
                menu.classList.toggle('open', willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
            menu.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    menu.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
            });
        });
    }

    document.addEventListener('click', function () { closeAllMenus(null); });

    function initDateRanges(root) {
        (root || document).querySelectorAll('[data-date-range]').forEach(function (form) {
            if (form.dataset.bound === '1') return;
            form.dataset.bound = '1';
            var from = form.querySelector('input[type="date"][name="from_date"]');
            var to = form.querySelector('input[type="date"][name="to_date"]');
            var error = form.querySelector('.date-range-error');
            if (!from || !to || !error) return;
            function validate() {
                var message = '';
                if (from.value && to.value && from.value > to.value) {
                    message = 'From Date must be on or before To Date.';
                }
                error.textContent = message;
                error.hidden = message === '';
                to.setCustomValidity(message);
                return message === '';
            }
            [from, to].forEach(function (input) {
                input.addEventListener('change', validate);
                input.addEventListener('input', validate);
            });
            form.addEventListener('submit', function (event) {
                if (!validate()) {
                    event.preventDefault();
                    error.scrollIntoView({ block: 'nearest' });
                }
            });
            validate();
        });
    }

    function initPrintButtons(root) {
        (root || document).querySelectorAll('[data-print-page]').forEach(function (button) {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';
            button.addEventListener('click', function () { window.print(); });
        });
    }

    window.tdcInitUi = function (root) {
        initActionMenus(root);
        initDateRanges(root);
        initPrintButtons(root);
    };

    ready(function () { window.tdcInitUi(document); });

    if (window.MutationObserver) {
        var observer = new MutationObserver(function (mutations) {
            var touched = false;
            mutations.forEach(function (mutation) {
                if (mutation.addedNodes && mutation.addedNodes.length) touched = true;
            });
            if (touched) window.tdcInitUi(document);
        });
        ready(function () {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }
})();