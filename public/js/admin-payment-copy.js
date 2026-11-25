document.addEventListener('click', async event => {
    const button = event.target.closest('.copy-reference');
    if (!button) return;
    const feedback = button.parentElement.querySelector('.copy-feedback');
    try {
        if (!navigator.clipboard || !window.isSecureContext) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(button.dataset.reference);
        feedback.textContent = 'Copied';
        window.setTimeout(() => { if (feedback.textContent === 'Copied') feedback.textContent = ''; }, 3000);
    } catch (_) {
        feedback.textContent = 'Copy unavailable. Full reference: ' + button.dataset.reference;
    }
});
