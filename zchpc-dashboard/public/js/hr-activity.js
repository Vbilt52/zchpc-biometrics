(() => {
    'use strict';
    const root = document.querySelector('[data-hr-activity]');
    if (!root) return;
    const status = root.querySelector('[data-activity-status]');
    const button = root.querySelector('[data-activity-refresh]');
    let busy = false;
    let timer;
    let stopped = false;
    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    const badge = value => {
        const element = node('span', value, 'hr-feed-badge');
        element.dataset.state = value;
        return element;
    };
    const render = data => {
        const rows = document.createDocumentFragment();
        data.events.forEach(event => {
            const row = node('tr');
            const values = [['Time', event.time], ['Employee', event.name, event.employee_id], ['Door / terminal', event.terminal, event.door], ['Method', event.method], ['Result', event.outcome], ['Direction', event.direction]];
            values.forEach(([label, value, detail]) => {
                const cell = node('td');
                cell.dataset.label = label;
                if (label === 'Result') cell.append(badge(value));
                else if (detail !== undefined) cell.append(node('strong', value), node('small', detail));
                else cell.textContent = value;
                row.append(cell);
            });
            rows.append(row);
        });
        if (!data.events.length) {
            const row = node('tr');
            const cell = node('td', 'No personnel activity received today.', 'hr-activity-empty');
            cell.colSpan = 6;
            row.append(cell);
            rows.append(row);
        }
        root.querySelector('[data-activity-rows]').replaceChildren(rows);
        const terminals = data.terminals.map(terminal => {
            const element = node('div', undefined, 'hr-terminal');
            element.append(node('strong', terminal.name), badge(terminal.status), node('small', `Logs through ${terminal.through || 'not yet collected'}`));
            return element;
        });
        root.querySelector('[data-activity-terminals]').replaceChildren(...terminals);
        root.querySelector('.hr-activity-heading p').textContent = `${data.date} · ${data.timezone} · Latest 50 recorded events`;
        status.textContent = `Updated ${data.updated_at}. Refreshes every 10 seconds.`;
    };
    const refresh = async () => {
        if (busy || stopped || document.hidden) return;
        clearTimeout(timer);
        busy = true;
        button.disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(root.dataset.url, {headers: {'Accept': 'application/json'}, cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
            if ([401, 403, 419].includes(response.status)) {
                stopped = true;
                status.textContent = 'Activity refresh stopped. Sign in again or check your access.';
                return;
            }
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Unavailable');
            const data = await response.json();
            if (!Array.isArray(data.events) || !Array.isArray(data.terminals)) throw new Error('Invalid response');
            render(data);
        } catch {
            status.textContent = 'Refresh unavailable. Showing the last received activity; retrying automatically.';
        } finally {
            clearTimeout(timeout);
            busy = false;
            button.disabled = stopped;
            if (!stopped && !document.hidden) timer = setTimeout(refresh, 10000);
        }
    };
    button.addEventListener('click', refresh);
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) refresh();
    });
    timer = setTimeout(refresh, 10000);
})();
