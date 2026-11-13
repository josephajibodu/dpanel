import '../css/app.css';

import {
    createInertiaApp,
    router,
    type ResolvedComponent,
} from '@inertiajs/react';
import { echo } from '@inertiajs/react/echo';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import './echo';
import { initializeTheme } from './hooks/use-appearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./pages/**/*.tsx', {
                import: 'default',
            }),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props} />
                <Toaster richColors position="bottom-center" />
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
    live: echo(),
});

// Toasts flashed by the server, such as the error sent back when a form
// submit fails in production.
router.on('flash', (event) => {
    const flashedToast = event.detail.flash.toast;

    if (flashedToast) {
        toast[flashedToast.type](flashedToast.message);
    }
});

// This will set light / dark mode on load...
initializeTheme();
