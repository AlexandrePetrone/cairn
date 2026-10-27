import { Controller } from '@hotwired/stimulus';

/*
 * Mobile navigation: the sidebar slides in, a backdrop covers the page.
 * Both are targets of a controller placed on the page shell.
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop'];

    open() {
        this.panelTarget.classList.remove('-translate-x-full');
        this.backdropTarget.classList.remove('hidden');
    }

    close() {
        this.panelTarget.classList.add('-translate-x-full');
        this.backdropTarget.classList.add('hidden');
    }
}
