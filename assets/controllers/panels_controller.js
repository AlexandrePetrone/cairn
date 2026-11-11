import { Controller } from '@hotwired/stimulus';

/*
 * Several panels in one place, one shown at a time: a menu that leads to
 * another list and comes back. The first panel is the one it starts from;
 * `reset`, on what opens the menu, goes back to it.
 *
 * <div data-controller="panels">
 *   <button data-action="dropdown#toggle panels#reset">…</button>
 *   <div data-panels-target="panel" data-panels-name="places">
 *     <button data-action="panels#show" data-panels-name-param="trackers">…</button>
 *   </div>
 *   <div data-panels-target="panel" data-panels-name="trackers" hidden>…</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['panel'];

    show({ params: { name } }) {
        for (const panel of this.panelTargets) {
            panel.hidden = panel.dataset.panelsName !== name;
        }
    }

    reset() {
        this.panelTargets.forEach((panel, index) => {
            panel.hidden = index > 0;
        });
    }
}
