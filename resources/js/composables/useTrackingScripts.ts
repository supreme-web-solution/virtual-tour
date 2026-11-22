import { onMounted } from 'vue';

export type TrackingIds = { ga4: string | null; gtm: string | null };

declare global {
    interface Window {
        dataLayer?: unknown[];
    }
}

function addScript(id: string, src: string): void {
    if (document.getElementById(id)) {
        return;
    }

    const script = document.createElement('script');
    script.id = id;
    script.async = true;
    script.src = src;
    document.head.appendChild(script);
}

/**
 * Load the workspace's own Google Analytics (GA4) and Tag Manager on a public
 * tour. Only the IDs are accepted (checked on the server), never free-form
 * scripts, so a workspace can't run code on the platform's pages.
 */
export function useTrackingScripts(tracking: TrackingIds | null): void {
    onMounted(() => {
        if (!tracking) {
            return;
        }

        window.dataLayer = window.dataLayer ?? [];

        if (tracking.gtm) {
            window.dataLayer.push({
                'gtm.start': Date.now(),
                event: 'gtm.js',
            });
            addScript(
                'tour-gtm',
                `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(tracking.gtm)}`,
            );
        }

        if (tracking.ga4) {
            // gtag reads the dataLayer with the `arguments` object, not an array.
            const layer = window.dataLayer;
            const gtag = function (..._args: unknown[]): void {
                // eslint-disable-next-line prefer-rest-params
                layer.push(arguments);
            };
            gtag('js', new Date());
            gtag('config', tracking.ga4);
            addScript(
                'tour-ga4',
                `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(tracking.ga4)}`,
            );
        }
    });
}
