import { Controller } from '@hotwired/stimulus';

/*
 * Shows a part of a form only while a field has one of the values it is
 * for: the choices of a field, while its type is one that has choices.
 * What is hidden is disabled too, so the form does not send it.
 *
 * <form data-controller="reveal">
 *   <select data-reveal-target="source" data-action="reveal#update">…</select>
 *   <div data-reveal-target="panel" data-reveal-values="select choice">…</div>
 * </form>
 */
export default class extends Controller {
    static targets = ['source', 'panel'];

    connect() {
        this.update();
    }

    update() {
        const value = this.sourceTarget.value;
        for (const panel of this.panelTargets) {
            const hidden = !panel.dataset.revealValues.split(' ').includes(value);
            panel.hidden = hidden;
            for (const field of panel.querySelectorAll('input, select, textarea')) {
                field.disabled = hidden;
            }
        }
    }
}
