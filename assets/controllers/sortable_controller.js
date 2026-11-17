import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/*
 * Reorders a list by drag and drop, then calls an action of the live
 * component around it with the id of the item moved and its new position.
 * The id is sent as `tracker` unless `data-sortable-param-value` names the
 * argument, as a number when it is one:
 *
 * <ul data-controller="sortable" data-sortable-action-value="move">
 *     <li data-sortable-target="item" data-sortable-id="12" draggable="true"
 *         data-action="dragstart->sortable#start dragover->sortable#over drop->sortable#drop dragend->sortable#end">
 *
 * Firefox starts a drag only when dragstart sets data, and follows the
 * link inside a dropped row unless the drop is accepted and handled.
 */
export default class extends Controller {
    static targets = ['item'];
    static values = { action: String, param: { type: String, default: 'tracker' } };

    start(event) {
        this.dragged = event.currentTarget;
        this.from = this.itemTargets.indexOf(this.dragged);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragged.dataset.sortableId);
        this.dragged.classList.add('opacity-40');
    }

    over(event) {
        if (!this.dragged) {
            return;
        }
        event.preventDefault();
        const item = event.currentTarget;
        if (item === this.dragged) {
            return;
        }
        const box = item.getBoundingClientRect();
        item.parentNode.insertBefore(this.dragged, event.clientY < box.top + box.height / 2 ? item : item.nextSibling);
    }

    drop(event) {
        event.preventDefault();
    }

    async end() {
        const item = this.dragged;
        this.dragged = null;
        if (!item) {
            return;
        }
        item.classList.remove('opacity-40');
        const position = this.itemTargets.indexOf(item);
        if (position === this.from) {
            return;
        }
        const component = await getComponent(this.element.closest('[data-controller~="live"]'));
        const id = item.dataset.sortableId;
        component.action(this.actionValue, { [this.paramValue]: /^\d+$/.test(id) ? Number(id) : id, position });
    }
}
