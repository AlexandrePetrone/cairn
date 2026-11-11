import { Controller } from '@hotwired/stimulus';

/*
 * The rows checked in a list, kept in the browser: nothing is asked of the
 * server until the selection is sent, as a form, to the page that acts on
 * it.
 *
 * <div data-controller="selection" data-selection-one-value="1 issue selected"
 *      data-selection-other-value="%count% issues selected">
 *   <form data-selection-target="bar" hidden> <span data-selection-target="count"></span> … </form>
 *   <input type="checkbox" data-selection-target="all" data-action="selection#togglePage">
 *   <input type="checkbox" name="ids[]" data-selection-target="box" data-action="selection#refresh">
 *
 * The bar shows while a row is checked and counts them, in the sentence of
 * the language of the page for one or for several; the header box checks
 * or unchecks the rows on screen (not those of a folded group) and says
 * when they all are.
 */
export default class extends Controller {
    static targets = ['box', 'all', 'bar', 'count'];
    static values = { one: String, other: String };

    connect() {
        this.refresh();
    }

    togglePage() {
        const checked = this.allTarget.checked;
        this.shown.forEach((box) => {
            box.checked = checked;
        });
        this.refresh();
    }

    clear() {
        this.boxTargets.forEach((box) => {
            box.checked = false;
        });
        this.refresh();
    }

    refresh() {
        const count = this.boxTargets.filter((box) => box.checked).length;
        if (this.hasAllTarget) {
            const shown = this.shown;
            const checked = shown.filter((box) => box.checked).length;
            this.allTarget.checked = checked > 0 && checked === shown.length;
            this.allTarget.indeterminate = count > 0 && !this.allTarget.checked;
        }
        if (this.hasBarTarget) {
            this.barTarget.hidden = 0 === count;
        }
        if (this.hasCountTarget) {
            const one = 'one' === new Intl.PluralRules(document.documentElement.lang || 'en').select(count);
            this.countTarget.textContent = (one ? this.oneValue : this.otherValue).replace('%count%', count);
        }
    }

    /** The boxes of the rows on screen: the header box leaves the rows of a folded group alone. */
    get shown() {
        return this.boxTargets.filter((box) => null === box.closest('[hidden]'));
    }
}
