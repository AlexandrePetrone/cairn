import { Controller } from '@hotwired/stimulus';

/*
 * Copies `text` (a value) or the content of the `source` target (an element
 * or a <template>) to the clipboard, then toggles `copied-class` on the
 * element for a moment.
 *
 * <button data-controller="clipboard" data-clipboard-text-value="MD-SPT-128" data-action="clipboard#copy">
 */
export default class extends Controller {
    static targets = ['source'];
    static values = { text: String, copiedClass: { type: String, default: 'text-emerald-600' } };

    async copy() {
        const text = this.hasSourceTarget
            ? (this.sourceTarget.content?.textContent ?? this.sourceTarget.textContent)
            : this.textValue;

        await navigator.clipboard.writeText(text.trim());

        this.element.classList.add(this.copiedClassValue);
        setTimeout(() => this.element.classList.remove(this.copiedClassValue), 1500);
    }
}
