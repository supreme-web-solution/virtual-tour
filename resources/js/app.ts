import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import EditorLayout from '@/layouts/EditorLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Public pages may send `siteName` (a white-labelled workspace's brand) to
 * use after page titles instead of the platform name.
 */
function pageSiteName(props: Record<string, unknown> | undefined): string {
    return typeof props?.siteName === 'string' && props.siteName !== ''
        ? props.siteName
        : appName;
}

let siteName = (() => {
    try {
        const script = document.querySelector('script[data-page]');

        return pageSiteName(JSON.parse(script?.textContent ?? '{}').props);
    } catch {
        return appName;
    }
})();

router.on('navigate', (event) => {
    siteName = pageSiteName(event.detail.page.props);
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${siteName}` : siteName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name.startsWith('public/'):
                return null;
            case name === 'tours/Editor':
                return EditorLayout;
            case name.startsWith('auth/'):
            case name.startsWith('invitations/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
