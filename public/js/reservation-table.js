document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const row = event.target.closest('tr[data-row-link]');
    if (!row || event.target.closest('a, button, input, select, textarea, summary, [contenteditable="true"]')) {
        return;
    }

    const url = row.dataset.rowLink;
    if (event.ctrlKey || event.metaKey || event.shiftKey) {
        window.open(url, '_blank', 'noopener');
        return;
    }

    window.location.assign(url);
});

document.addEventListener('auxclick', (event) => {
    if (event.button !== 1 || !(event.target instanceof Element)) {
        return;
    }

    const row = event.target.closest('tr[data-row-link]');
    if (!row || event.target.closest('a, button, input, select, textarea, summary, [contenteditable="true"]')) {
        return;
    }

    window.open(row.dataset.rowLink, '_blank', 'noopener');
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || !(event.target instanceof HTMLElement)) {
        return;
    }

    const row = event.target.closest('tr[data-row-link]');
    if (row !== event.target) {
        return;
    }

    event.preventDefault();
    window.location.assign(row.dataset.rowLink);
});
