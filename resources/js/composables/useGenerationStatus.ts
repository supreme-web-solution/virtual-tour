import { usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * Refresh the given page props every few seconds while any generation is
 * still running, and stop as soon as everything has finished.
 */
export function useGenerationStatus(
    hasActive: () => boolean,
    only: string[],
    intervalMs = 5000,
): void {
    const { start, stop } = usePoll(
        intervalMs,
        { only },
        { autoStart: false, keepAlive: false },
    );

    watch(
        hasActive,
        (active) => {
            if (active) {
                start();
            } else {
                stop();
            }
        },
        { immediate: true },
    );
}
