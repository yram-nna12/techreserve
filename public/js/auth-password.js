document.addEventListener('click', (event) => {
    const control = event.target.closest('[data-password-toggle]');
    if (!control) return;
    const field = document.getElementById(control.dataset.passwordToggle);
    const icon = control.querySelector('.material-symbols-outlined');
    if (!field || !icon) return;
    const visible = field.type === 'text';
    field.type = visible ? 'password' : 'text';
    icon.textContent = visible ? 'visibility' : 'visibility_off';
    control.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
});
