import { Controller } from '@hotwired/stimulus';

/*
 * Focuses the field as soon as it appears, the caret at the end: the
 * `autofocus` attribute only works on page load, not when a live
 * component redraws. A field without a caret to place (a number) only
 * gets the focus.
 */
export default class extends Controller {
    connect() {
        this.element.focus();
        if ('number' === typeof this.element.selectionStart) {
            const end = this.element.value.length;
            this.element.setSelectionRange(end, end);
        }
    }
}
