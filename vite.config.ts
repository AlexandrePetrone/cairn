import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import Symfony from '@symfony/reprise/vite';

// The dev server runs in the "vite" container (compose.yaml); the browser
// reaches it through the published port.
const port = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
    input: {
        app: './assets/app.js',
    },
    server: {
        host: true,
        port,
        strictPort: true,
    },
    plugins: [
        tailwindcss(),
        Symfony({
            stimulus: './assets/controllers.json',
            devServerOrigin: `http://localhost:${port}`,
        }),
    ],
});
