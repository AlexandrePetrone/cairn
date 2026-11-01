/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import mermaid from 'mermaid';

/** Below this scale, the labels of a fitted drawing are too small to read. */
const MIN_SCALE = 0.75;

let initialized = false;
let count = 0;

/*
 * Draws the Mermaid source of a workflow (the hidden `source` target) as
 * SVG in the `canvas` target. Lazy: Mermaid is only fetched by the pages
 * that show a diagram. When drawing fails, the source is shown instead.
 */
export default class extends Controller {
    static targets = ['canvas', 'source'];

    async connect() {
        if (!initialized) {
            mermaid.initialize({
                startOnLoad: false,
                theme: 'base',
                securityLevel: 'strict',
                fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
                flowchart: { curve: 'basis', htmlLabels: false },
                themeVariables: { lineColor: '#9ca3af', edgeLabelBackground: '#ffffff', fontSize: '13px' },
            });
            initialized = true;
        }

        try {
            const { svg } = await mermaid.render(`workflow-diagram-${++count}`, this.sourceTarget.textContent);
            this.canvasTarget.innerHTML = svg;
            // Mermaid fits the drawing to its container. Shrunk too much (on a
            // phone), it is unreadable: keep its natural size, the container scrolls.
            const drawing = this.canvasTarget.querySelector('svg');
            const [, , width, height] = (drawing?.getAttribute('viewBox') ?? '').split(' ').map(Number);
            if (drawing && width && this.element.clientWidth < width * MIN_SCALE) {
                drawing.removeAttribute('style');
                drawing.setAttribute('width', `${Math.ceil(width)}`);
                drawing.setAttribute('height', `${Math.ceil(height)}`);
            }
        } catch (error) {
            this.sourceTarget.hidden = false;
            console.error(error);
        }
    }
}
