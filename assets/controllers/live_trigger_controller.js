import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/*
 * A link outside a live component that calls one of its actions: the
 * button of a page header that opens the dialog of the component below.
 * `component` is the name of the live component, `action` the live
 * action. The link stays a link: its address opens the page in the state
 * the action leads to, for a new tab (a modified click is left to the
 * browser) or when the component cannot be reached. Once the page is
 * shown, the parameters of the link leave the address: the state is the
 * component's from then on, and a reload must not bring it back.
 */
export default class extends Controller {
    static values = { component: String, action: String };

    connect() {
        const address = new URL(window.location.href);
        const before = address.search;
        for (const name of new URL(this.element.href).searchParams.keys()) {
            address.searchParams.delete(name);
        }
        if (address.search !== before) {
            // through Turbo, which keeps its own idea of the address
            window.Turbo.navigator.history.replace(address);
        }
    }

    async call(event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const element = document.querySelector(`[data-live-name-value="${this.componentValue}"]`);
        if (!element) {
            return;
        }
        event.preventDefault();
        try {
            const component = await getComponent(element);
            component.action(this.actionValue);
        } catch {
            window.location.assign(this.element.href);
        }
    }
}
