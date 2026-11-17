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
 *
 * A list too long to be in the page is asked for instead: with a `url`
 * value, what is typed goes to that address as `q` and the HTML it
 * answers (items, and whatever it says beside them) replaces the content
 * of the `results` target. The items outside it are left alone.
 *
 * <div data-controller="list-filter" data-list-filter-url-value="/MD/SPT/budgets/issues">
 *   <input data-action="input->list-filter#filter">
 *   <div data-list-filter-target="results"></div>
 *   <p data-list-filter-target="empty" hidden>…</p>
 * </div>
 */
export default class extends Controller {
    static targets = ['item', 'group', 'empty', 'results'];
    static values = { url: String };

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    filter(event) {
        if (this.hasUrlValue) {
            // an emptied field (the list just opened) is answered at once, typing once it pauses
            const text = event.target.value.trim();
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.ask(text), '' === text ? 0 : 200);

            return;
        }
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

    async ask(text) {
        this.request?.abort();
        this.request = new AbortController();
        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('q', text);
        let html;
        try {
            const response = await fetch(url, { signal: this.request.signal, headers: { Accept: 'text/html' } });
            // a session that ended answers with the sign-in page: not a list
            if (!response.ok || response.redirected) {
                return;
            }
            html = await response.text();
        } catch (error) {
            // a newer question took its place, or the network failed: the list stays as it is
            return;
        }
        this.resultsTarget.innerHTML = html;
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = this.itemTargets.some((item) => this.resultsTarget.contains(item));
        }
    }
}
