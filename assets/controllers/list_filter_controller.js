import { Controller } from '@hotwired/stimulus';

/*
 * Narrows a list as the user types: hides the items whose text does not
 * contain the words typed, and the groups left without a visible item.
 *
 * <div data-controller="list-filter">
 *   <input data-action="input->list-filter#filter">
 *   <div data-list-filter-target="group">
 *     <a data-list-filter-target="item" data-text="Site e-commerce MD-SPT">…</a>
 *   </div>
 *   <p data-list-filter-target="empty" hidden>…</p>
 * </div>
 */
export default class extends Controller {
    static targets = ['item', 'group', 'empty'];

    filter(event) {
        const words = event.target.value.toLowerCase().split(/\s+/).filter(Boolean);
        let visible = 0;
        for (const item of this.itemTargets) {
            const text = (item.dataset.text ?? item.textContent).toLowerCase();
            item.hidden = !words.every((word) => text.includes(word));
            visible += item.hidden ? 0 : 1;
        }
        for (const group of this.groupTargets) {
            group.hidden = this.itemTargets.every((item) => !group.contains(item) || item.hidden);
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible > 0;
        }
    }
}
