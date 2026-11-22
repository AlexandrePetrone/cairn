/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import mermaid from 'mermaid';

/** Below this scale, the labels of a fitted drawing are too small to read. */
const MIN_SCALE = 0.75;

let count = 0;

/*
 * Draws the Mermaid source of a workflow (the hidden `source` target) as
 * SVG in the `canvas` target. Lazy: Mermaid is only fetched by the pages
 * that show a diagram. When drawing fails, the source is shown instead.
 *
 * The source names the colour roles of app.css (`var(--color-blue-soft)`,
 * MermaidDiagram), as do the lines and labels here; Mermaid wants real
 * colours, so each role is read from the page when drawing, and the
 * diagram is drawn again when the theme changes (`theme:changed`, or the
 * system's while it is followed), and when its source does.
 */
export default class extends Controller {
    static targets = ['canvas', 'source'];

    connect() {
        this.redraw = () => this.draw();
        this.scheme = window.matchMedia('(prefers-color-scheme: dark)');
        this.scheme.addEventListener('change', this.redraw);
        window.addEventListener('theme:changed', this.redraw);
        // A live component that holds the diagram writes another source when the workflow changes.
        this.source = new MutationObserver(this.redraw);
        this.source.observe(this.sourceTarget, { childList: true, characterData: true, subtree: true });
        this.draw();
    }

    disconnect() {
        this.scheme.removeEventListener('change', this.redraw);
        window.removeEventListener('theme:changed', this.redraw);
        this.source.disconnect();
    }

    async draw() {
        mermaid.initialize({
            startOnLoad: false,
            theme: 'base',
            securityLevel: 'strict',
            fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
            flowchart: { htmlLabels: false },
            // Mermaid lays flowcharts out with ELK, which draws the arrows at right
            // angles and ignores `flowchart.curve`. Linear segments keep the drawing
            // narrow and the labels of the actions apart.
            elk: { nodePlacementStrategy: 'LINEAR_SEGMENTS', straightenEdges: true },
            // Mermaid lets half of a line show through the label it crosses: the name of an
            // action and its roles are read on a plain ground.
            themeCSS: '.edgeLabel rect { opacity: 1; }',
            themeVariables: {
                lineColor: this.color('faint'),
                edgeLabelBackground: this.color('surface'),
                textColor: this.color('body'),
                primaryTextColor: this.color('body'),
                fontSize: '13px',
            },
        });

        try {
            const source = this.sourceTarget.textContent.replace(/var\(--color-([a-z-]+)\)/g, (_, role) => this.color(role));
            const { svg } = await mermaid.render(`workflow-diagram-${++count}`, source);
            this.canvasTarget.innerHTML = svg;
            this.sourceTarget.hidden = true;
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

    /**
     * The colour a role of app.css has here and now, resolved by the browser,
     * in hexadecimal: Mermaid separates the styles of a class with commas.
     */
    color(role) {
        const probe = document.createElement('span');
        probe.style.color = `var(--color-${role})`;
        this.element.append(probe);
        const channels = getComputedStyle(probe).color.match(/[\d.]+/g) ?? ['0', '0', '0'];
        probe.remove();

        return `#${channels.slice(0, 3).map((channel) => Math.round(Number(channel)).toString(16).padStart(2, '0')).join('')}`;
    }
}
