/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import Sortable from 'sortablejs';

/*
 * The cards of a kanban column, dragged to another column with SortableJS:
 *
 * <section data-board-column> <span data-board-count>3</span>
 *   <ul data-controller="board" data-board-place-value="in_progress">
 *     <li data-board-key="MD-SPT-12" data-board-places="to_test done">
 *
 * A card goes only to the places its `data-board-places` lists, computed
 * by the server for the viewer; while it is dragged, the columns say
 * which ones (`data-board-state`), and the one under the pointer says it
 * would take it (`data-board-over`). Dropped, it stays where it was put
 * and Board:Move applies the move, at once or after a dialog. The
 * `board:moved` browser event it sends back settles it here, without
 * reading any column again: applied, the card takes the places it may now
 * go to and the two columns count again; refused or cancelled, it goes
 * back where it came from. Until then the card cannot be dragged again
 * (`board-pending`): one move is settled at a time. A column that loaded
 * again in the meantime already shows the card as the server has it, so
 * the origin is looked up by its place, never trusted as an element. The
 * order within a column is the server's the next time the column loads.
 */

/** Where each card being moved came from: its list, its place and the card that followed it. */
const origins = new WeakMap();

export default class extends Controller {
    static values = { place: String };

    connect() {
        this.settle = this.settle.bind(this);
        window.addEventListener('board:moved', this.settle);
        this.sortable = Sortable.create(this.element, {
            group: 'board',
            sort: false,
            draggable: 'li[data-board-key]',
            filter: '.board-locked, .board-pending',
            preventOnFilter: false,
            animation: 150,
            ghostClass: 'opacity-40',
            onStart: (event) => {
                origins.set(event.item, { list: this.element, place: this.placeValue, next: event.item.nextElementSibling });
                this.mark(this.places(event.item));
            },
            onEnd: () => {
                this.mark(null);
                this.over(null);
            },
            onMove: (event) => {
                const allowed = event.to === event.from || this.places(event.dragged).includes(event.to.dataset.boardPlaceValue);
                this.over(allowed && event.to !== event.from ? event.to : null);

                return allowed;
            },
            onAdd: (event) => {
                event.item.classList.add('board-pending');
                this.move(event.item.dataset.boardKey);
            },
        });
    }

    disconnect() {
        window.removeEventListener('board:moved', this.settle);
        this.sortable?.destroy();
    }

    places(card) {
        return (card.dataset.boardPlaces || '').split(' ').filter(Boolean);
    }

    /** Tells each column whether the card being dragged may go there; null clears it. */
    mark(places) {
        document.querySelectorAll('[data-controller~="board"]').forEach((list) => {
            if (null === places || list === this.element) {
                delete list.dataset.boardState;
            } else {
                list.dataset.boardState = places.includes(list.dataset.boardPlaceValue) ? 'allowed' : 'denied';
            }
        });
    }

    /** Marks the list the card would be dropped on; null clears it. */
    over(list) {
        document.querySelectorAll('[data-board-over]').forEach((other) => {
            if (other !== list) {
                delete other.dataset.boardOver;
            }
        });
        if (list) {
            list.dataset.boardOver = '';
        }
    }

    async move(key) {
        const mover = document.querySelector('[data-board-move]');
        if (!mover) {
            return;
        }
        const component = await getComponent(mover);
        component.action('move', { key, place: this.placeValue });
    }

    /** The move of a card ended; the column that holds the card acts. */
    settle(event) {
        const { key, applied, places } = event.detail || {};
        const card = [...this.element.children].find((item) => item.dataset.boardKey === key);
        const origin = card ? origins.get(card) : null;
        if (!card || !origin) {
            return;
        }
        origins.delete(card);
        card.classList.remove('board-pending');
        // The origin column may have loaded again since the drop: its list is then another element, drawn with the card still in it.
        const reloaded = !origin.list.isConnected;
        const list = reloaded ? document.querySelector(`[data-controller~="board"][data-board-place-value="${CSS.escape(origin.place)}"]`) : origin.list;
        const stale = reloaded && list ? [...list.children].find((item) => item.dataset.boardKey === key) : null;
        if (!applied) {
            if (reloaded) {
                card.remove();
            } else {
                list.insertBefore(card, origin.next?.parentElement === list ? origin.next : null);
            }

            return;
        }
        stale?.remove();
        card.dataset.boardPlaces = (places || []).join(' ');
        card.classList.toggle('board-locked', 0 === (places || []).length);
        card.classList.toggle('cursor-grab', (places || []).length > 0);
        if (list) {
            this.count(list, -1);
        }
        this.count(this.element, 1);
    }

    count(list, change) {
        const count = list.closest('[data-board-column]')?.querySelector('[data-board-count]');
        if (count) {
            count.textContent = Math.max(0, (parseInt(count.textContent, 10) || 0) + change);
        }
    }
}
