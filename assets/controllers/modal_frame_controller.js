import { Controller } from '@hotwired/stimulus';

/*
 * The `modal` Turbo frame of a page, where its dialogs load. A form sent
 * from a dialog stays in the frame, so a refusal draws the dialog again
 * and nothing else. Accepted, the server redirects: the frame is answered
 * its own empty self, which closes the dialog, and this controller visits
 * the page the redirect led to, so that it shows what changed and its
 * flash, which the answer made for the frame left unread. When that page
 * is the one the dialog was opened over, it is read again from where the
 * reader was: a visit would otherwise go back to its top.
 */
export default class extends Controller {
    submitted(event) {
        const response = event.detail.fetchResponse;
        if (!event.detail.success || !response?.redirected) {
            return;
        }

        const url = new URL(response.response.url);
        if (url.pathname === window.location.pathname) {
            const top = window.scrollY;
            document.addEventListener('turbo:load', () => {
                // only for the visit made below: had it failed, another page would load first
                if (window.location.pathname === url.pathname) {
                    window.scrollTo(0, top);
                }
            }, { once: true });
        }
        window.Turbo.visit(url);
    }
}
