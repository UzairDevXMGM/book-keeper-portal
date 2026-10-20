/*
 * Book Keeper Dashboard scripts. Plain JavaScript: edit this file directly, no build step.
 * Needs vendor/bootstrap/bootstrap.bundle.min.js loaded first (it provides window.bootstrap).
 * Chart.js is loaded only on pages that have a chart (see loadChart below).
 */
(() => {
const bootstrap = window.bootstrap;
// Where Chart.js lives, from <script src="js/app.js" data-chart-src="..."> in the layout
const chartSrc = document.currentScript ? document.currentScript.dataset.chartSrc : null;
const loadChart = () => new Promise((resolve, reject) => {
    if (window.Chart) return resolve(window.Chart);
    const script = document.createElement('script');
    script.src = chartSrc;
    script.onload = () => resolve(window.Chart);
    script.onerror = reject;
    document.head.append(script);
});

const money = (n) => n.toLocaleString('en-US', { style: 'currency', currency: 'USD' });

// Light / dark mode: <button data-theme-toggle>. The head script already set the theme before the page drew;
// this keeps the icon in step, saves the choice in this browser and tells charts to redraw.
const themeButtons = document.querySelectorAll('[data-theme-toggle]');
const showTheme = () => {
    const dark = document.documentElement.dataset.bsTheme === 'dark';
    themeButtons.forEach((button) => {
        const label = dark ? 'Switch to light mode' : 'Switch to dark mode';
        button.setAttribute('aria-label', label);
        button.title = label;
        button.querySelector('[data-theme-icon]').className = `bi ${dark ? 'bi-sun' : 'bi-moon-stars'} fs-5`;
    });
};
const setTheme = (theme, remember) => {
    document.documentElement.dataset.bsTheme = theme;
    if (remember) {
        try { localStorage.setItem('theme', theme); } catch (e) { /* private window: the choice lasts for this page only */ }
    }
    showTheme();
    document.dispatchEvent(new CustomEvent('themechange', { detail: theme }));
};
themeButtons.forEach((button) => button.addEventListener('click', () => {
    setTheme(document.documentElement.dataset.bsTheme === 'dark' ? 'light' : 'dark', true);
}));
showTheme();
// No saved choice: follow the device when it switches (e.g. dark mode at night)
window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', (event) => {
    let saved = null;
    try { saved = localStorage.getItem('theme'); } catch (e) { /* ignore */ }
    if (saved !== 'light' && saved !== 'dark') setTheme(event.matches ? 'dark' : 'light', false);
});

// Show / hide password: <button data-password-toggle="#password">
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;

    const input = document.querySelector(button.dataset.passwordToggle);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});

// <button data-print> prints the page (reports hide the app chrome in print CSS)
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print]')) window.print();
});

// Report print view (?print=1): open the print dialog once the page has loaded
if (document.querySelector('[data-auto-print]')) {
    window.addEventListener('load', () => window.print());
}

// <select data-autosubmit> submits its form on change
document.addEventListener('change', (event) => {
    if (event.target.matches('[data-autosubmit]')) event.target.form.requestSubmit();
});

// <form data-confirm="Question?"> asks first in the app's own dialog (partials/confirm-modal), then submits;
// the submit button is then disabled to prevent double submits
const confirmModal = document.getElementById('confirmModal');
let pendingConfirm = null;
const askToConfirm = (form, submitter) => {
    const danger = form.dataset.confirmTone === 'danger';
    confirmModal.querySelector('[data-confirm-title]').textContent = form.dataset.confirmTitle || 'Please confirm';
    confirmModal.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
    const ok = confirmModal.querySelector('[data-confirm-ok]');
    ok.textContent = form.dataset.confirmButton || 'Yes, continue';
    ok.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;
    const icon = confirmModal.querySelector('[data-confirm-icon]');
    icon.classList.toggle('is-danger', danger);
    icon.innerHTML = `<i class="bi ${danger ? 'bi-exclamation-triangle' : 'bi-question-lg'}"></i>`;
    pendingConfirm = { form, submitter };
    bootstrap.Modal.getOrCreateInstance(confirmModal).show();
};
if (confirmModal) {
    confirmModal.querySelector('[data-confirm-ok]').addEventListener('click', () => {
        if (!pendingConfirm) return;
        const { form, submitter } = pendingConfirm;
        pendingConfirm = null;
        form.dataset.confirmed = '1';
        bootstrap.Modal.getOrCreateInstance(confirmModal).hide();
        // Keep the clicked button's name/value (e.g. action=approve)
        submitter && submitter.form === form ? form.requestSubmit(submitter) : form.requestSubmit();
    });
    confirmModal.addEventListener('shown.bs.modal', () => confirmModal.querySelector('[data-confirm-ok]').focus());
}
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.hasAttribute('data-js-submit')) return;   // the form sends itself with fetch (e.g. the payment page)
    if (form.dataset.confirm && !form.dataset.confirmed) {
        event.preventDefault();
        if (confirmModal) {
            askToConfirm(form, event.submitter);
        } else if (window.confirm(form.dataset.confirm)) {
            form.dataset.confirmed = '1';
            form.requestSubmit(event.submitter || undefined);
        }
        return;
    }
    delete form.dataset.confirmed;
    // Disable after the browser has read the clicked button's name/value (e.g. finish=1)
    const button = event.submitter ?? form.querySelector('[type="submit"]');
    if (button) {
        setTimeout(() => {
            button.disabled = true;
            button.querySelector('.spinner-border')?.classList.remove('d-none');
        }, 0);
    }
});

// "Select all" checkbox for tables: <input data-check-all> ticks every [data-row-check] in the same table
document.addEventListener('change', (event) => {
    if (!event.target.matches('[data-check-all]')) return;
    event.target.closest('table').querySelectorAll('[data-row-check]:not(:disabled)').forEach((box) => {
        box.checked = event.target.checked;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    });
});

// Statement upload: drag & drop onto the drop zone, and show the chosen file name
document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type="file"]');
    const label = zone.querySelector('[data-dropzone-label]');
    const show = () => { if (input.files.length) label.textContent = input.files[0].name; };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (e) => { e.preventDefault(); zone.classList.add('is-dragging'); }));
    ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, () => zone.classList.remove('is-dragging')));
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            show();
        }
    });
});

// Review queue bulk approve: send the category chosen in each ticked row's dropdown
document.querySelectorAll('[data-bulk-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelectorAll('input[name^="accounts["]').forEach((el) => el.remove());
        document.querySelectorAll('[data-row-check][form="bulk-form"]:checked').forEach((box) => {
            const select = document.querySelector(`[data-row-account="${box.value}"]`);
            if (!select) return;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = `accounts[${box.value}]`;
            hidden.value = select.value;
            form.append(hidden);
        });
    });
});

// Reconcile start: buttons that fill in a statement read from an upload
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fill-reconcile]');
    if (!button) return;
    const form = button.closest('form');
    form.querySelector('[name="account_id"]').value = button.dataset.account;
    form.querySelector('[name="statement_date"]').value = button.dataset.date;
    form.querySelector('[name="statement_balance"]').value = button.dataset.balance;
});

// Reconcile: live cleared balance and difference; Finish only when the difference is zero
const reconcile = document.querySelector('[data-reconcile]');
if (reconcile) {
    const cents = (v) => Math.round(parseFloat(v || 0) * 100);
    const opening = cents(reconcile.dataset.opening);
    const statement = cents(reconcile.dataset.statement);
    const recalc = () => {
        let cleared = opening;
        reconcile.querySelectorAll('[data-row-check]:checked').forEach((box) => { cleared += cents(box.dataset.effect); });
        const difference = statement - cleared;
        reconcile.querySelector('[data-cleared]').textContent = money(cleared / 100);
        const out = reconcile.querySelector('[data-difference]');
        out.textContent = money(difference / 100);
        out.classList.toggle('text-success', difference === 0);
        out.classList.toggle('text-danger', difference !== 0);
        const finish = reconcile.querySelector('[data-finish]');
        if (finish) finish.disabled = difference !== 0;
    };
    reconcile.addEventListener('change', recalc);
    recalc();
}

// Report toolbar: the date inputs only show for "Custom dates"
document.querySelectorAll('[data-preset-select]').forEach((select) => {
    const dates = document.querySelectorAll('[data-custom-dates]');
    const toggle = () => dates.forEach((el) => el.classList.toggle('d-none', select.value !== 'custom'));
    select.addEventListener('change', toggle);
    toggle();
});

// Chart of accounts: one edit modal filled from the clicked row
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-edit-account]');
    if (!button) return;

    const form = document.querySelector('#editAccountForm');
    form.action = button.dataset.action;
    form.querySelector('[name="name"]').value = button.dataset.name;
    form.querySelector('[data-account-code]').textContent = button.dataset.code;
    const system = button.dataset.system === '1';
    const active = form.querySelector('[name="is_active"][type="checkbox"]');
    active.checked = button.dataset.active === '1';
    // System accounts can only be renamed: send no is_active at all for them
    active.disabled = system;
    form.querySelector('[name="is_active"][type="hidden"]').disabled = system;
    form.querySelector('[data-system-note]').classList.toggle('d-none', !system);
});

// Re-open a modal whose form came back with validation errors: <div class="modal" data-open-on-load>
document.querySelectorAll('.modal[data-open-on-load]').forEach((el) => bootstrap.Modal.getOrCreateInstance(el).show());

// Add account: bank and card accounts get their type and section automatically, so those fields hide
document.querySelectorAll('[data-account-kind]').forEach((select) => {
    const form = select.closest('form');
    const sync = () => {
        const regular = select.value === 'regular';
        form.querySelectorAll('[data-regular-only]').forEach((el) => { el.hidden = !regular; });
        form.querySelectorAll('[data-statement-only]').forEach((el) => { el.hidden = regular; });
    };
    select.addEventListener('change', sync);
    sync();
});

// Journal entry form: add / remove lines, live debit and credit totals, post only when balanced
const journal = document.querySelector('[data-journal-form]');
if (journal) {
    const body = journal.querySelector('[data-lines]');
    const template = journal.querySelector('template[data-line-template]');
    const amount = (value) => {
        const n = parseFloat(String(value).replace(/[,$\s]/g, ''));
        return Number.isFinite(n) ? Math.round(n * 100) : 0;   // work in cents
    };
    const renumber = () => body.querySelectorAll('tr').forEach((row, i) => {
        row.querySelectorAll('[data-name]').forEach((el) => { el.name = `lines[${i}][${el.dataset.name}]`; });
    });
    const recalc = () => {
        let debit = 0;
        let credit = 0;
        body.querySelectorAll('tr').forEach((row) => {
            debit += amount(row.querySelector('[data-name="debit"]').value);
            credit += amount(row.querySelector('[data-name="credit"]').value);
        });
        const balanced = debit > 0 && debit === credit;
        journal.querySelector('[data-total-debit]').textContent = money(debit / 100);
        journal.querySelector('[data-total-credit]').textContent = money(credit / 100);
        const status = journal.querySelector('[data-difference]');
        status.textContent = balanced ? 'Balanced' : (debit || credit ? `Out of balance by ${money(Math.abs(debit - credit) / 100)}` : 'Enter amounts');
        status.className = balanced ? 'text-success fw-semibold' : 'text-danger fw-semibold';
        journal.querySelector('[data-post]').disabled = !balanced;
    };

    journal.addEventListener('input', (event) => {
        const field = event.target.dataset.name;
        if ((field === 'debit' || field === 'credit') && event.target.value !== '') {
            // a line is either a debit or a credit: typing in one side clears the other
            event.target.closest('tr').querySelector(`[data-name="${field === 'debit' ? 'credit' : 'debit'}"]`).value = '';
        }
        recalc();
    });
    journal.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-line]')) {
            body.append(template.content.cloneNode(true));
            renumber();
        }
        const remove = event.target.closest('[data-remove-line]');
        if (remove && body.children.length > 2) {
            remove.closest('tr').remove();
            renumber();
            recalc();
        }
    });
    renumber();
    recalc();
}

// Dashboard trend chart. Chart.js is loaded only on pages that have a chart.
const trendCanvas = document.querySelector('[data-trend-chart]');
if (trendCanvas) {
    let trendChart = null;
    const drawTrend = (Chart) => {
        trendChart?.destroy();
        const data = JSON.parse(trendCanvas.dataset.trendChart);
        const dark = document.documentElement.dataset.bsTheme === 'dark';
        // Validated palettes (dataviz validator) for the light and the dark card: revenue green, costs violet, net income blue
        const c = dark
            ? { ink: '#aab4ac', grid: '#2b312a', surface: '#1a1e1a', revenue: '#8cbc43', costs: '#9387f2', net: '#5aa2f0' }
            : { ink: '#5f6b62', grid: '#eef1ea', surface: '#ffffff', revenue: '#6f9a35', costs: '#4a3aa7', net: '#2a78d6' };
        const ink = c.ink;
        const bar = (label, values, color) => ({
            type: 'bar', label, data: values, backgroundColor: color, borderColor: c.surface,
            borderWidth: { left: 1, right: 1, top: 0, bottom: 0 }, borderRadius: 4, borderSkipped: 'start',
            categoryPercentage: 0.72, barPercentage: 0.9, order: 2,
        });

        trendChart = new Chart(trendCanvas, {
            data: {
                labels: data.labels,
                datasets: [
                    bar('Revenue', data.revenue, c.revenue),
                    bar('Costs', data.costs, c.costs),
                    {
                        type: 'line', label: 'Net income', data: data.net, borderColor: c.net, backgroundColor: c.net,
                        borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, pointBorderColor: c.surface, pointBorderWidth: 2,
                        tension: 0.25, order: 1,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        // keep the series order (the line is drawn on top via `order`, which would otherwise list it first)
                        labels: { color: ink, usePointStyle: true, boxWidth: 8, padding: 16, sort: (a, b) => a.datasetIndex - b.datasetIndex },
                    },
                    tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${money(ctx.parsed.y)}` } },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: ink } },
                    y: {
                        grid: { color: c.grid }, border: { display: false },
                        ticks: { color: ink, callback: (v) => `$${Math.abs(v) >= 1000 ? `${v / 1000}k` : v}` },
                    },
                },
            },
        });
    };
    loadChart().then((Chart) => {
        drawTrend(Chart);
        document.addEventListener('themechange', () => drawTrend(Chart));
    });
}

// Transactions: clicking a category opens the "Change category" dialog for that transaction
const categoryModal = document.getElementById('changeCategoryModal');
if (categoryModal) {
    const form = categoryModal.querySelector('[data-change-category-form]');
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-change-category]');
        if (!button) return;
        form.action = button.dataset.changeCategory;
        form.reset();
        categoryModal.querySelector('[data-change-category-description]').textContent = button.dataset.description;
        categoryModal.querySelector('[data-change-category-current]').textContent = button.dataset.current || 'No category yet';
        bootstrap.Modal.getOrCreateInstance(categoryModal).show();
    });
}

// Chart of accounts: search box and "only accounts you use" switch
const accountSearch = document.querySelector('[data-account-search]');
const onlyUsed = document.querySelector('[data-account-only-used]');
if (accountSearch && onlyUsed) {
    const rows = [...document.querySelectorAll('[data-account-row]')];
    const counter = document.querySelector('[data-account-count]');
    const apply = () => {
        const term = accountSearch.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach((row) => {
            // Searching looks through every account; otherwise the switch hides the ones never used
            const visible = term ? row.dataset.search.includes(term) : (!onlyUsed.checked || row.dataset.used === '1');
            row.hidden = !visible;
            shown += visible ? 1 : 0;
        });
        document.querySelectorAll('[data-account-group]').forEach((group) => {
            const n = group.querySelectorAll('[data-account-row]:not([hidden])').length;
            group.hidden = n === 0;
            group.querySelector('[data-group-count]').textContent = n;
        });
        if (counter) counter.textContent = `Showing ${shown} of ${rows.length} accounts`;
    };
    accountSearch.addEventListener('input', apply);
    onlyUsed.addEventListener('change', apply);
    apply();
}
})();
