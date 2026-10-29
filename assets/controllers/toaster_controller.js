import { Controller } from '@hotwired/stimulus';

/*
 * Shows a toast for every `toast:show` event, whose detail carries the
 * `message`, by cloning the toast template of the Ui:Toaster component.
 * The toast removes itself (toast controller).
 */
export default class extends Controller {
    static targets = ['template'];

    show(event) {
        const toast = this.templateTarget.content.firstElementChild.cloneNode(true);
        toast.querySelector('[data-toaster-message]').textContent = event.detail.message;
        this.element.append(toast);
    }
}
