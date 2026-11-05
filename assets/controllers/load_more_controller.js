import { Controller } from '@hotwired/stimulus';

/*
 * Clicks the "show more" button it sits on once it scrolls into view, so
 * that a list loads its next batch as the user reaches its end; the
 * button stays for keyboards and screen readers.
 *
 * <button data-controller="load-more" data-action="live#action" data-live-action-param="more">
 */
export default class extends Controller {
    connect() {
        this.observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.observer.disconnect();
                this.element.click();
            }
        });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
    }
}
