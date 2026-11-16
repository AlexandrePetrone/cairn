import { Controller } from '@hotwired/stimulus';

/*
 * Folds the groups of a list in the browser: the rows of a group hide
 * under its header, without asking the server.
 *
 * <table data-controller="fold" data-fold-key-value="/MD/SPT/issues#place">
 *   <button data-fold-target="toggle" data-action="fold#toggle" data-fold-group-param="new" aria-expanded="true">
 *   <tr data-fold-target="row" data-fold-group="new">
 *
 * The folded groups are kept for the tab (sessionStorage) under `key`,
 * which names the list and its grouping: they stay folded while the list
 * reloads, and another grouping starts with every group open.
 */
export default class extends Controller {
    static targets = ['toggle', 'row'];
    static values = { key: String };

    connect() {
        this.folded = new Set(this.read());
        this.apply();
    }

    toggle(event) {
        // Stimulus reads a numeric param as a number; a group key is text, as the rows carry it.
        const group = String(event.params.group);
        this.folded.has(group) ? this.folded.delete(group) : this.folded.add(group);
        this.write();
        this.apply();
    }

    apply() {
        this.toggleTargets.forEach((toggle) => {
            toggle.setAttribute('aria-expanded', this.folded.has(toggle.dataset.foldGroupParam) ? 'false' : 'true');
        });
        this.rowTargets.forEach((row) => {
            row.hidden = this.folded.has(row.dataset.foldGroup);
        });
    }

    read() {
        try {
            return JSON.parse(sessionStorage.getItem(`fold:${this.keyValue}`)) || [];
        } catch {
            return [];
        }
    }

    write() {
        try {
            sessionStorage.setItem(`fold:${this.keyValue}`, JSON.stringify([...this.folded]));
        } catch {
            // Without storage the groups fold until the list reloads.
        }
    }
}
