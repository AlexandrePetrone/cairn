/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import Sortable from 'sortablejs';

/*
 * The cards of a kanban column, dragged to another column with SortableJS:
 *
 * <ul data-controller="board" data-board-place-value="in_progress">
 *     <li data-board-key="MD-SPT-12" data-board-places="to_test done">
 *
 * A card goes only to the places its `data-board-places` lists, computed
 * by the server for the viewer; while it is dragged, the columns say
 * which ones (`data-board-state`), and the one under the pointer says it
 * would take it (`data-board-over`). Dropped, it stays where it was put and
 * Issue:BoardMove applies the move; the `board:moved` browser event it
 * sends back redraws the two columns concerned as the server has them.
 * The order within a column is the server's.
 */
export default class extends Controller {
    static values = { place: String };

    connect() {
        this.redraw = this.redraw.bind(this);
        window.addEventListener('board:moved', this.redraw);
        this.sortable = Sortable.create(this.element, {
            group: 'board',
            sort: false,
            draggable: 'li[data-board-key]',
            filter: '.board-locked',
            preventOnFilter: false,
            animation: 150,
            ghostClass: 'opacity-40',
            onStart: (event) => this.mark(this.places(event.item)),
            onEnd: () => {
                this.mark(null);
                this.over(null);
            },
            onMove: (event) => {
                const allowed = event.to === event.from || this.places(event.dragged).includes(event.to.dataset.boardPlaceValue);
                this.over(allowed && event.to !== event.from ? event.to : null);

                return allowed;
            },
            onAdd: (event) => this.move(event.item.dataset.boardKey),
        });
    }

    disconnect() {
        window.removeEventListener('board:moved', this.redraw);
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

    async redraw(event) {
        const { from, to } = event.detail || {};
        if (this.placeValue !== from && this.placeValue !== to) {
            return;
        }
        const column = this.element.closest('[data-live-name-value]');
        if (column) {
            (await getComponent(column)).render();
        }
    }
}
