import { Controller } from '@hotwired/stimulus';

/*
 * A file input inside a live component, sent as soon as files are chosen.
 * The live controller registers the chosen files when `change` reaches the
 * component's root, after any action on the input itself; so this
 * dispatches `file-picker:chosen` once `change` has gone all the way up,
 * and the input sends its files on that event:
 *
 * <input type="file" name="files[]" data-controller="file-picker"
 *        data-action="change->file-picker#chosen file-picker:chosen->live#action"
 *        data-live-action-param="files|upload">
 */
export default class extends Controller {
    chosen() {
        if (this.element.files.length > 0) {
            setTimeout(() => this.dispatch('chosen'));
        }
    }
}
