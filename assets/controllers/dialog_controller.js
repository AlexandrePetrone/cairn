import { Controller } from '@hotwired/stimulus';

/*
 * A modal a live component renders while something waits for a choice:
 * the first field gets the focus when it opens, Escape presses the `close`
 * target (which carries the live action that closes it).
 */
export default class extends Controller {
    static targets = ['close'];

    connect() {
        const field = this.element.querySelector('select, input:not([type=hidden]), textarea');
        (field ?? (this.hasCloseTarget ? this.closeTarget : null))?.focus();
    }

    close() {
        if (this.hasCloseTarget) {
            this.closeTarget.click();
        }
    }
}
