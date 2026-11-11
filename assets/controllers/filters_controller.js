import { Controller } from '@hotwired/stimulus';

/*
 * The filter bar of a list whose results load in a Turbo frame: a GET form
 * that is drawn once and submitted on every change, so its menus stay as
 * the user left them.
 *
 * <form method="get" data-turbo-frame="issues" data-view="list" data-controller="filters"
 *       data-action="change->filters#submit input->filters#search">
 *   <div data-filter-menu> … <span data-filter-menu-count hidden></span> … checkboxes … </div>
 *   <input type="search" name="q">
 *   <button type="button" data-filters-target="clear" data-action="filters#clear" hidden>
 *
 * Since the form is not redrawn, the controller keeps up to date what the
 * server drew first: the count and the highlight of each menu
 * (`data-active`), the "clear" button, and the view the form says it shows
 * (`data-view`, from the checked `view` radio). When the back button
 * brings the page back it also reads its controls again from the address:
 * the results are those of an earlier address, the form is as it was left
 * since. A page Turbo just fetched is left as the server drew it: the bare
 * address of a list is redirected to the view its reader keeps, and Turbo
 * renders that page before it puts the address it was redirected to in
 * the bar.
 * Empty fields are left out of the address. Typing in the search field submits after a pause and
 * replaces the address instead of adding one to the history per pause.
 */
/* How Turbo came to the page being shown: `restore` for the back and forward buttons, null for a page the browser loaded itself. */
let visitAction = null;
document.addEventListener('turbo:visit', (event) => {
    visitAction = event.detail.action;
});

export default class extends Controller {
    static targets = ['clear'];

    connect() {
        this.clean = (event) => {
            for (const [name, value] of [...event.formData.entries()]) {
                if ('' === value) {
                    event.formData.delete(name);
                }
            }
        };
        this.element.addEventListener('formdata', this.clean);
        if (null === visitAction || 'restore' === visitAction) {
            this.sync();
        }
        this.refresh();
    }

    disconnect() {
        this.element.removeEventListener('formdata', this.clean);
        clearTimeout(this.pause);
    }

    submit(event) {
        if ('search' === event?.target?.type) {
            return;
        }
        this.send('advance');
    }

    search(event) {
        if ('search' !== event.target.type) {
            return;
        }
        clearTimeout(this.pause);
        this.pause = setTimeout(() => this.send('replace'), 300);
    }

    /** Unchecks every filter and empties the search; the grouping, the view and open or all stay. */
    clear() {
        this.element.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            box.checked = false;
        });
        this.element.querySelectorAll('input[type="search"]').forEach((field) => {
            field.value = '';
        });
        this.send('advance');
    }

    /** Sets every control of the form to what the address says. */
    sync() {
        const chosen = new Map();
        for (const [name, value] of new URLSearchParams(window.location.search)) {
            const key = name.replace(/\[\d*\]$/, '[]');
            chosen.set(key, [...(chosen.get(key) || []), value]);
        }
        this.element.querySelectorAll('input[name]').forEach((input) => {
            const values = chosen.get(input.name) || [];
            if ('checkbox' === input.type) {
                input.checked = values.includes(input.value);
            } else if ('radio' === input.type) {
                input.checked = (values[0] || '') === input.value;
            } else if ('search' === input.type) {
                input.value = values[0] || '';
            }
        });
    }

    send(action) {
        clearTimeout(this.pause);
        this.refresh();
        this.element.dataset.turboAction = action;
        this.element.requestSubmit();
    }

    refresh() {
        const view = this.element.querySelector('input[name="view"]:checked');
        if (view) {
            this.element.dataset.view = view.value || 'list';
        }
        this.element.querySelectorAll('[data-filter-menu]').forEach((menu) => {
            const chosen = [...menu.querySelectorAll('input:checked')].filter((input) => '' !== input.value);
            const count = menu.querySelector('[data-filter-menu-count]');
            const counted = chosen.filter((input) => 'checkbox' === input.type && input.name.endsWith('[]')).length;
            if (count) {
                count.textContent = counted;
                count.hidden = 0 === counted;
            }
            menu.toggleAttribute('data-active', chosen.length > 0);
        });
        if (this.hasClearTarget) {
            const filtered = null !== this.element.querySelector('input[type="checkbox"]:checked')
                || [...this.element.querySelectorAll('input[type="search"]')].some((field) => '' !== field.value.trim());
            this.clearTarget.hidden = !filtered;
        }
    }
}
