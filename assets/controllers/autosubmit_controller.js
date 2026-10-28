import { Controller } from '@hotwired/stimulus';

/*
 * Submits the form it sits on as soon as a control changes, so that filters
 * apply without a button:
 *
 * <form data-controller="autosubmit" data-action="change->autosubmit#submit">
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
