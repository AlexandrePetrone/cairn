import { Controller } from '@hotwired/stimulus';

/*
 * The `modal` Turbo frame of a page, where its dialogs load. A form sent
 * from a dialog stays in the frame, so a refusal draws the dialog again
 * and nothing else. Accepted, the server redirects: the frame is answered
 * its own empty self, which closes the dialog, and this controller visits
 * the page the redirect led to, so that it shows what changed and its
 * flash, which the answer made for the frame left unread.
 */
export default class extends Controller {
    submitted(event) {
        const response = event.detail.fetchResponse;
        if (event.detail.success && response?.redirected) {
            window.Turbo.visit(response.response.url);
        }
    }
}
