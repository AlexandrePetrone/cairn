import { Controller } from '@hotwired/stimulus';

/*
 * Filters items by the text typed in the input. Items declare their text in
 * `data-list-filter-text`; a group is hidden when none of its items match;
 * the `empty` target is shown when nothing matches.
 *
 * <div data-controller="list-filter">
 *   <input data-action="input->list-filter#filter">
 *   <div data-list-filter-target="group">
 *     <a data-list-filter-target="item" data-list-filter-text="site e-commerce md-spt">…</a>
 *   </div>
 *   <p data-list-filter-target="empty" class="hidden">Aucun résultat</p>
 * </div>
 */
export default class extends Controller {
    static targets = ['item', 'group', 'empty'];

    filter(event) {
        const query = event.target.value.trim().toLowerCase();
        let visible = 0;

        for (const item of this.itemTargets) {
            const matches = query === '' || (item.dataset.listFilterText ?? item.textContent).toLowerCase().includes(query);
            item.classList.toggle('hidden', !matches);
            visible += matches ? 1 : 0;
        }
        for (const group of this.groupTargets) {
            const hasVisibleItem = this.itemTargets.some((item) => group.contains(item) && !item.classList.contains('hidden'));
            group.classList.toggle('hidden', !hasVisibleItem);
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle('hidden', visible > 0);
        }
    }
}
