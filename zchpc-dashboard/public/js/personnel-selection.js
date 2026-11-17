(() => {
    const form = document.getElementById('bulk-delete-form');
    if (!form) return;
    const rows = Array.from(document.querySelectorAll('[data-person-selection]'));
    const selectPage = document.querySelector('[data-select-page]');
    const count = form.querySelector('[data-selection-count]');
    const clear = form.querySelector('[data-clear-selection]');
    const remove = form.querySelector('[data-delete-selected]');
    let submitting = false;
    const selected = () => rows.filter(row => row.checked);

    function update() {
        const total = selected().length;
        count.textContent = `${total} ${total === 1 ? 'user' : 'users'} selected`;
        remove.disabled = clear.disabled = total === 0 || submitting;
        selectPage.disabled = rows.length === 0 || submitting;
        selectPage.checked = rows.length > 0 && total === rows.length;
        selectPage.indeterminate = total > 0 && total < rows.length;
    }

    rows.forEach(row => row.addEventListener('change', update));
    selectPage.addEventListener('change', () => {
        rows.forEach(row => { row.checked = selectPage.checked; });
        update();
    });
    clear.addEventListener('click', () => {
        rows.forEach(row => { row.checked = false; });
        update();
    });
    form.addEventListener('submit', event => {
        const people = selected();
        if (submitting || people.length === 0) {
            event.preventDefault();
            return;
        }
        const names = people.map(row => row.dataset.personLabel).join('\n');
        if (!window.confirm(`Delete ${people.length} selected user(s)?\n\n${names}\n\nThey will be removed locally and device deletions will be queued. Offline devices may retain access until they reconnect. History is retained.`)) {
            event.preventDefault();
            return;
        }
        form.elements.namedItem('confirm').value = '1';
        submitting = true;
        remove.textContent = 'Deleting selected users…';
        update();
    });
    // Clear restored checkboxes when returning through browser history.
    window.addEventListener('pageshow', () => {
        submitting = false;
        form.elements.namedItem('confirm').value = '0';
        rows.forEach(row => { row.checked = false; });
        remove.textContent = 'Delete selected';
        update();
    });
    update();
})();
