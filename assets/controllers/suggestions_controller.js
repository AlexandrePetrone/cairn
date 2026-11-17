import { Controller } from '@hotwired/stimulus';

/*
 * The keyboard in a list of suggestions drawn by the server under a
 * field: the arrows move among the options, Enter takes the one marked
 * (a click on it), the first as long as none was moved to. Enter never
 * submits the form the field is in. The options come and go as the
 * server redraws them; one that cannot be taken is not a target. The
 * server says which text its options answer (`for`): while the field
 * holds another one, typed since, they are those of an earlier search
 * and Enter takes none.
 *
 * <div data-controller="suggestions" data-suggestions-for-value="clai">
 *   <input data-action="keydown->suggestions#navigate">
 *   <button data-suggestions-target="option" data-action="live#action" …>Claire Martin</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['option'];
    static values = { for: String };

    optionTargetConnected() {
        this.mark(0);
    }

    optionTargetDisconnected() {
        this.mark(0);
    }

    navigate(event) {
        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
            return;
        }
        event.preventDefault();
        const options = this.optionTargets;
        const marked = options.findIndex((option) => 'true' === option.getAttribute('aria-selected'));
        if ('Enter' === event.key) {
            if (event.target.value.trim() === this.forValue) {
                options[marked]?.click();
            }

            return;
        }
        this.mark(marked + ('ArrowDown' === event.key ? 1 : -1));
        this.optionTargets.find((option) => 'true' === option.getAttribute('aria-selected'))?.scrollIntoView({ block: 'nearest' });
    }

    mark(index) {
        const options = this.optionTargets;
        const marked = Math.max(0, Math.min(index, options.length - 1));
        options.forEach((option, position) => {
            option.setAttribute('aria-selected', position === marked ? 'true' : 'false');
        });
    }
}
