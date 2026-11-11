import { Controller } from '@hotwired/stimulus';

const COOKIE = 'cairn_theme';
const YEAR = 60 * 60 * 24 * 365;

/*
 * The theme chosen in the user menu: light, dark, or the system's.
 *
 * <div data-controller="theme" data-action="change->theme#choose">
 *   <input type="radio" name="theme" value="light|dark|system">
 *
 * The choice goes on <html> (`data-theme`, which app.css reads) and into a
 * cookie, from which the server renders the next pages the same way
 * (ThemeExtension); the system's theme removes both. Turbo keeps the
 * attributes of <html> from page to page, so nothing else is needed.
 * `theme:changed` tells what draws its own colours (the workflow diagram).
 */
export default class extends Controller {
    connect() {
        // A page restored from Turbo's cache may carry the choice made before.
        const choice = document.documentElement.dataset.theme ?? 'system';
        this.element.querySelectorAll('input[name="theme"]').forEach((radio) => {
            radio.checked = radio.value === choice;
        });
    }

    choose(event) {
        const choice = event.target.value;
        const root = document.documentElement;

        if ('system' === choice) {
            delete root.dataset.theme;
            document.cookie = `${COOKIE}=; path=/; max-age=0; SameSite=Lax`;
        } else {
            root.dataset.theme = choice;
            const secure = 'https:' === window.location.protocol ? '; Secure' : '';
            document.cookie = `${COOKIE}=${choice}; path=/; max-age=${YEAR}; SameSite=Lax${secure}`;
        }
        document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', 'system' === choice ? 'light dark' : choice);
        window.dispatchEvent(new CustomEvent('theme:changed'));
    }
}
