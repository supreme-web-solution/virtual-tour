import { watchDebounced } from '@vueuse/core';
import { ref } from 'vue';
import type { Ref } from 'vue';
import { getJson } from '@/lib/http';
import { pick } from '@/routes/media';

export type FoundAsset = {
    id: string;
    name: string | null;
    filename: string | null;
    thumbnail_url: string | null;
    width: number | null;
    height: number | null;
    is_equirectangular: boolean;
    property: string | null;
};

/**
 * Search the whole media library by file name, 48 files at a time. Pickers
 * preload only the latest files; this reaches the rest.
 */
export function useMediaSearch(type: Ref<string> | (() => string)) {
    const query = ref('');
    const results = ref<FoundAsset[]>([]);
    const hasMore = ref(false);
    const loading = ref(false);
    const searched = ref(false);
    let page = 1;
    let token = 0;

    const typeValue = (): string =>
        typeof type === 'function' ? type() : type.value;

    async function load(reset: boolean): Promise<void> {
        const mine = ++token;
        page = reset ? 1 : page + 1;
        loading.value = true;

        try {
            const response = await getJson<{
                data: FoundAsset[];
                has_more: boolean;
            }>(
                pick.url({
                    query: {
                        type: typeValue(),
                        q: query.value.trim(),
                        page,
                    },
                }),
            );

            if (mine !== token) {
                return;
            }

            results.value = reset
                ? response.data
                : [...results.value, ...response.data];
            hasMore.value = response.has_more;
            searched.value = true;
        } catch {
            // Keep what is shown; the next search tries again.
        } finally {
            if (mine === token) {
                loading.value = false;
            }
        }
    }

    watchDebounced(
        query,
        () => {
            if (query.value.trim() === '') {
                token++;
                results.value = [];
                hasMore.value = false;
                searched.value = false;
                loading.value = false;

                return;
            }

            void load(true);
        },
        { debounce: 300 },
    );

    return {
        query,
        results,
        hasMore,
        loading,
        searched,
        more: () => load(false),
    };
}
