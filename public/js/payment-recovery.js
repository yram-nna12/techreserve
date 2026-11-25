document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.matches('.payment-recovery-form')) return;
    if (form.dataset.submitted === 'true') {
        event.preventDefault();
        return;
    }
    form.dataset.submitted = 'true';
    document.querySelectorAll('.payment-recovery-form button').forEach(button => {
        if (button.form === form) button.textContent = button.dataset.busy;
        button.disabled = true;
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('.payment-recovery-form').forEach(form => {
        delete form.dataset.submitted;
        const button = form.querySelector('button');
        if (button && button.dataset.originalText) button.textContent = button.dataset.originalText;
        if (button) button.disabled = false;
    });
});
document.querySelectorAll('.payment-recovery-form button').forEach(button => {
    button.dataset.originalText = button.textContent;
});
