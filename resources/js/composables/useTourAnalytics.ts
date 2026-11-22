import { onBeforeUnmount, onMounted } from 'vue';
import * as publicRoutes from '@/routes/tours/public';

type TrackedEvent = {
    type: 'scene_view' | 'hotspot_click' | 'link_click';
    scene_id?: string | null;
    hotspot_id?: string | null;
};

const VISITOR_KEY = 'vt_visitor';
const FLUSH_INTERVAL_MS = 15_000;

/**
 * Respect browser privacy signals: Do Not Track and Global Privacy Control.
 */
function trackingRefused(): boolean {
    const nav = navigator as Navigator & { globalPrivacyControl?: boolean };

    return nav.doNotTrack === '1' || nav.globalPrivacyControl === true;
}

/**
 * A random, anonymous ID kept in this browser so repeat visits can be
 * estimated. Falls back to a per-page ID when storage is unavailable.
 */
function visitorId(): string {
    try {
        let id = localStorage.getItem(VISITOR_KEY);

        if (!id) {
            id = crypto.randomUUID();
            localStorage.setItem(VISITOR_KEY, id);
        }

        return id;
    } catch {
        return crypto.randomUUID();
    }
}

/**
 * Anonymous visit analytics for the public viewer. Events are batched and
 * sent with sendBeacon so they survive the visitor closing the tab.
 */
export function useTourAnalytics(options: {
    publicId: string;
    enabled: boolean;
    embed: boolean;
}) {
    let visitId: string | null = null;
    let queue: TrackedEvent[] = [];
    let timer: ReturnType<typeof setInterval> | null = null;
    const active = options.enabled && !trackingRefused();

    function flush(): void {
        if (!visitId) {
            return;
        }

        const body = JSON.stringify({
            visit_id: visitId,
            events: queue.splice(0, 50),
        });
        const url = publicRoutes.events.url(options.publicId);

        if (
            !navigator.sendBeacon?.(
                url,
                new Blob([body], { type: 'text/plain' }),
            )
        ) {
            void fetch(url, {
                method: 'POST',
                body,
                keepalive: true,
                headers: { 'Content-Type': 'text/plain' },
            }).catch(() => {});
        }
    }

    function track(event: TrackedEvent): void {
        if (!active) {
            return;
        }

        queue.push(event);

        if (queue.length >= 20) {
            flush();
        }
    }

    function onVisibility(): void {
        if (document.visibilityState === 'hidden') {
            flush();
        }
    }

    onMounted(async () => {
        if (!active) {
            return;
        }

        try {
            const response = await fetch(
                publicRoutes.visits.url(options.publicId),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        visitor_id: visitorId(),
                        referrer: document.referrer || null,
                        embed: options.embed,
                    }),
                },
            );

            if (response.status !== 201) {
                return;
            }

            visitId = ((await response.json()) as { visit_id: string })
                .visit_id;
            flush();
            timer = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    flush();
                }
            }, FLUSH_INTERVAL_MS);
            document.addEventListener('visibilitychange', onVisibility);
            window.addEventListener('pagehide', flush);
        } catch {
            // Analytics must never break the tour.
        }
    });

    onBeforeUnmount(() => {
        flush();

        if (timer) {
            clearInterval(timer);
        }

        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('pagehide', flush);
    });

    return {
        visitId: () => visitId,
        trackScene: (sceneId: string) =>
            track({ type: 'scene_view', scene_id: sceneId }),
        trackHotspot: (
            sceneId: string | null,
            hotspotId: string,
            isLink: boolean,
        ) =>
            track({
                type: isLink ? 'link_click' : 'hotspot_click',
                scene_id: sceneId,
                hotspot_id: hotspotId,
            }),
    };
}
