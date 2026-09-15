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
