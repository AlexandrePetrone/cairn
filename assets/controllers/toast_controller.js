import { Controller } from '@hotwired/stimulus';

/*
 * A message that removes itself after `delay` milliseconds (default 2500),
 * or when its close button is clicked.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 2500 } };

    connect() {
        this.timeout = setTimeout(() => this.dismiss(), this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }

    dismiss() {
        this.element.remove();
    }
}
