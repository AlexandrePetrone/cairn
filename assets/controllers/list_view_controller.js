import { Controller } from '@hotwired/stimulus';

/*
 * Tells a list to remember how it was just arranged: when its frame has
 * loaded another arrangement, the query string of that address is sent to
 * the list, at its own address, with a PUT.
 *
 * <turbo-frame id="issues" data-controller="list-view" data-action="turbo:frame-load->list-view#remember">
 *
 * Only a change made on the page loads the frame: opening an address,
 * one's own or one someone shared, remembers nothing. The server keeps
 * what it wants of the query string (not the page).
 */
export default class extends Controller {
    remember() {
        const address = new URL(this.element.src || window.location.href, window.location.href);
        const send = () => fetch(address.pathname, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: address.searchParams.toString(),
            keepalive: true,
        }).catch(() => {});
        // One at a time, in the order of the changes: the last arrangement is the one kept.
        this.last = (this.last || Promise.resolve()).then(send);
    }
}
