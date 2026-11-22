import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { MediaAsset } from '@/types';

/**
 * Tick-to-select state for a grid of media cards. Selection is dropped for
 * files that leave the list (deleted, filtered out).
 */
export function useMediaSelection(assets: Ref<MediaAsset[]>) {
    const selectedIds = ref<string[]>([]);

    watch(assets, (list) => {
        const present = new Set(list.map((asset) => asset.id));
        selectedIds.value = selectedIds.value.filter((id) => present.has(id));
    });

    function toggle(asset: MediaAsset): void {
        selectedIds.value = selectedIds.value.includes(asset.id)
            ? selectedIds.value.filter((id) => id !== asset.id)
            : [...selectedIds.value, asset.id];
    }

    function selectAll(): void {
        selectedIds.value = assets.value
            .filter((asset) => asset.can.delete)
            .map((asset) => asset.id);
    }

    function clear(): void {
        selectedIds.value = [];
    }

    return { selectedIds, toggle, selectAll, clear };
}

/**
 * While any photo on screen is being enhanced, refresh the list now and then
 * so its card and the detail panel update when the edit finishes.
 */
export function useEnhancementWatcher(
    assets: Ref<MediaAsset[]>,
    only: string[],
): void {
    let timer: ReturnType<typeof setInterval> | undefined;

    function stop(): void {
        clearInterval(timer);
        timer = undefined;
    }

    watch(
        () => assets.value.some((asset) => asset.enhancement_id),
        (running) => {
            stop();

            if (running) {
                timer = setInterval(() => router.reload({ only }), 5000);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(stop);
}
