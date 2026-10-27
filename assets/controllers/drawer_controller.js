import { Controller } from '@hotwired/stimulus';

/*
 * A panel sliding in from the right, with a backdrop. Escape closes it; the
 * `focus` target receives the focus when it opens.
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'focus'];

    connect() {
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    open() {
        this.panelTarget.classList.remove('translate-x-full');
        this.backdropTarget.classList.remove('hidden');
        if (this.hasFocusTarget) {
            this.focusTarget.focus();
        }
    }

    close() {
        this.panelTarget.classList.add('translate-x-full');
        this.backdropTarget.classList.add('hidden');
    }
}
