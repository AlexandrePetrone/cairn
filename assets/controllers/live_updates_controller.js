import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import { EventSourcePolyfill } from 'event-source-polyfill';

/*
 * Keeps an issue list or a kanban in step with what others do. The server
 * publishes on a Mercure hub that an issue changed: its id, its tracker,
 * the place it stands at, the one it left, and nothing else. What the page shows
 * is then read again the way it was drawn, so the server still decides
 * what this viewer sees. The page was given the token that lets it listen
 * to its topics, and sends it to the hub in a header.
 *
 * <turbo-frame id="issues" data-controller="live-updates" data-live-updates-url-value="…/.well-known/mercure?…"
 *              data-live-updates-token-value="eyJ…" data-live-updates-tracker-value="3"
 *              data-action="turbo:frame-load->live-updates#loaded …">
 *   <p hidden data-live-updates-target="stale"> … <button data-action="live-updates#reload">
 *
 * The list of a tracker leaves the changes of the other trackers alone.
 * A kanban reads again the column that holds the card and the columns of
 * the place it left and of the place it now stands at, and never while a card is dragged or its
 * move is being settled. A table reloads its frame, once it is not
 * loading what the viewer just asked for, unless rows are checked: it then says that the list changed and waits to be asked.
 * The viewer's own changes are heard like the others, since they may have
 * another tab open: the page that made one reads again what it already
 * shows. Changes are gathered for a moment, and wait for a hidden tab to
 * show again.
 */
export default class extends Controller {
    static targets = ['stale'];
    static values = { url: String, token: String, tracker: Number };

    connect() {
        this.changes = [];
        // The browser's own EventSource sends no header: the polyfill does, and is otherwise the same.
        this.source = new EventSourcePolyfill(this.urlValue, { headers: { Authorization: `Bearer ${this.tokenValue}` }, heartbeatTimeout: 120000 });
        this.source.onmessage = (event) => this.heard(JSON.parse(event.data));
        this.shown = () => this.schedule();
        document.addEventListener('visibilitychange', this.shown);
    }

    disconnect() {
        this.source?.close();
        clearTimeout(this.timer);
        document.removeEventListener('visibilitychange', this.shown);
    }

    heard(change) {
        if (this.concerns(change)) {
            this.changes.push(change);
            this.schedule();
        }
    }

    /** Whether the change is about what the list shows: every issue of the project, or those of its tracker and the ones it still shows. */
    concerns({ issue, tracker }) {
        return !this.hasTrackerValue || tracker === this.trackerValue
            || null !== this.element.querySelector(`#board-card-${issue}, input[name="ids[]"][value="${issue}"]`);
    }

    schedule(delay = 300) {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), delay);
    }

    refresh() {
        if (0 === this.changes.length || document.hidden) {
            return;
        }
        // Not under what the viewer is doing: a card dragged or being settled, a frame already loading their filter or sort.
        if (this.element.hasAttribute('busy') || this.element.querySelector('.board-pending, .sortable-chosen')) {
            this.schedule(500);

            return;
        }
        const changes = this.changes;
        this.changes = [];
        if (this.element.querySelector('[data-board-move]')) {
            this.columns(changes).forEach((column) => getComponent(column).then((component) => component.render()));
        } else if (this.element.querySelector('input[name="ids[]"]:checked')) {
            this.staleTargets.forEach((notice) => {
                notice.hidden = false;
            });
        } else {
            this.reload();
        }
    }

    /** The columns a change touches: the one showing the card, those of the place the issue left and of the one it is at. */
    columns(changes) {
        const columns = new Set();
        changes.forEach(({ issue, place, from }) => {
            columns.add(document.getElementById(`board-card-${issue}`)?.closest('[data-board-column]'));
            [place, from].forEach((id) => columns.add(id ? this.element.querySelector(`[data-board-column="${CSS.escape(id)}"]`) : null));
        });
        columns.delete(null);
        columns.delete(undefined);

        return [...columns];
    }

    /**
     * Loads the frame again at the address it shows, which replaces its entry
     * of the history instead of adding one.
     */
    reload() {
        this.reloading = true;
        this.element.removeAttribute('src');
        window.Turbo.visit(window.location.href, { frame: this.element.id, action: 'replace' });
    }

    /** The frame loaded: when it only read itself again, the list is not told to remember an arrangement. */
    loaded(event) {
        if (this.reloading) {
            this.reloading = false;
            event.stopImmediatePropagation();
        }
    }
}
