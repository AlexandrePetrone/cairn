import { Controller } from '@hotwired/stimulus';

/*
 * Focuses the field as soon as it appears, the caret at the end: the
 * `autofocus` attribute only works on page load, not when a live
 * component redraws.
 */
export default class extends Controller {
    connect() {
        this.element.focus();
        const end = this.element.value.length;
        this.element.setSelectionRange?.(end, end);
    }
}
