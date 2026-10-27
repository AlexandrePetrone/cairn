import { Controller } from '@hotwired/stimulus';

/*
 * Toggles a class (default `hidden`) on its targets: reveal a field, show
 * or hide the technical events of a timeline…
 *
 * <div data-controller="toggle" data-toggle-class-value="hidden">
 *   <input type="checkbox" data-action="toggle#toggle">
 *   <div data-toggle-target="item">…</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['item'];
    static values = { class: { type: String, default: 'hidden' } };

    toggle() {
        for (const item of this.itemTargets) {
            item.classList.toggle(this.classValue);
        }
    }

    show() {
        for (const item of this.itemTargets) {
            item.classList.remove(this.classValue);
        }
    }

    hide() {
        for (const item of this.itemTargets) {
            item.classList.add(this.classValue);
        }
    }
}
