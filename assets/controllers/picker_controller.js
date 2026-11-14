import { Controller } from '@hotwired/stimulus';

/*
 * A form field chosen in a Ui:Picker: picking an item writes its value
 * into the hidden field and its label on the button that opens the list.
 *
 * <div data-controller="picker">
 *   <input type="hidden" name="project" data-picker-target="input">
 *   … <span data-picker-target="label">Choose…</span> …
 *   <button data-action="picker#choose dropdown#close" data-picker-value-param="12" data-picker-label-param="MD-SPT · Shop">…</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['input', 'label'];

    choose(event) {
        const { value, label } = event.params;
        this.inputTarget.value = value;
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.labelTarget.textContent = label;
        this.labelTarget.closest('button')?.classList.replace('text-faint', 'text-body');
    }
}
