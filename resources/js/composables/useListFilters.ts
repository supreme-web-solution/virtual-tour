import { router } from '@inertiajs/vue3';
import type { RouteQueryOptions } from '@/wayfinder';
import { reactive, watch } from 'vue';

type Filters = Record<string, string | boolean>;

/**
 * Keep list filters in the URL: changing a filter reloads the page props
 * (text fields after a short pause) without losing scroll or local state.
 */
export function useListFilters<T extends Filters>(
    initial: T,
    url: (options?: RouteQueryOptions) => string,
    debounceMs = 300,
): T {
    const filters = reactive({ ...initial }) as T;
    let timer: ReturnType<typeof setTimeout> | undefined;

    watch(
        () => ({ ...filters }),
        () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                const query = Object.fromEntries(
                    Object.entries(filters)
                        .filter(([, value]) => value !== '' && value !== false)
                        .map(([key, value]) => [
                            key,
                            value === true ? '1' : value,
                        ]),
                );

                router.get(url({ query }), undefined, {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                });
            }, debounceMs);
        },
    );

    return filters;
}
