import { Controller } from '@hotwired/stimulus';

/*
 * A modal: the first field gets the focus when it opens, Escape presses
 * the `close` target. Rendered by a live component, that target carries
 * the live action that closes it. Rendered by a page into the `modal`
 * Turbo frame, its closing links call `dismiss`, which empties the frame
 * without a request; when the page was opened at the address of the
 * dialog (the frame was never loaded), the link is followed instead.
 */
export default class extends Controller {
    static targets = ['close'];

    connect() {
        const field = this.element.querySelector('select, input:not([type=hidden]), textarea');
        (field ?? (this.hasCloseTarget ? this.closeTarget : null))?.focus();
    }

    close(event) {
        // a browser picking an autofill suggestion sends a keydown without a key, which the `esc` filter lets through
        if (event && event.key !== 'Escape') {
            return;
        }
        if (this.hasCloseTarget) {
            this.closeTarget.click();
        }
    }

    dismiss(event) {
        const frame = this.element.closest('turbo-frame');
        if (!frame || !frame.hasAttribute('src')) {
            return;
        }
        event.preventDefault();
        // without `src` the same link loads the frame again
        frame.removeAttribute('src');
        frame.replaceChildren();
    }
}
