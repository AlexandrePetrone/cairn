import { Controller } from '@hotwired/stimulus';

/*
 * Copies the text of the `source` target when the button is used, and
 * says so for a moment by showing the `done` target in place of the
 * `idle` one.
 */
export default class extends Controller {
    static targets = ['source', 'idle', 'done'];

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
        this.toggle(true);
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.toggle(false), 2000);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }

    toggle(done) {
        this.idleTargets.forEach((element) => { element.hidden = done; });
        this.doneTargets.forEach((element) => { element.hidden = !done; });
    }
}
