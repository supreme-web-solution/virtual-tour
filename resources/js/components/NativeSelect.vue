<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { Search } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

type SelectOption = { value: string; label: string };

/**
 * Reka cannot use an empty string as an item value, so the "no choice"
 * entry travels under this stand-in and maps back to ''.
 */
const EMPTY = '__none__';

const props = defineProps<{
    options: SelectOption[];
    placeholder?: string;
    disabled?: boolean;
    /** Show a search box; by default only when there are many options. */
    searchable?: boolean;
    class?: HTMLAttributes['class'];
}>();

/** Long lists are filtered, and only this many matches are drawn at once. */
const SEARCH_AFTER = 8;
const MAX_SHOWN = 60;

const query = ref('');
const searchInput = ref<HTMLInputElement | null>(null);

const showSearch = computed(
    () => props.searchable ?? props.options.length > SEARCH_AFTER,
);
const matches = computed(() => {
    const term = query.value.trim().toLowerCase();

    return term === ''
        ? props.options
        : props.options.filter((option) =>
              option.label.toLowerCase().includes(term),
          );
});
const shown = computed(() => matches.value.slice(0, MAX_SHOWN));

function onOpen(open: boolean): void {
    query.value = '';

    if (open && showSearch.value) {
        void nextTick(() => setTimeout(() => searchInput.value?.focus(), 0));
    }
}

const model = defineModel<string | null>({ default: '' });

const selected = computed({
    get: () =>
        model.value === '' || model.value === null || model.value === undefined
            ? props.placeholder !== undefined
                ? EMPTY
                : undefined
            : String(model.value),
    set: (value) => {
        model.value = value === EMPTY || value === undefined ? '' : value;
    },
});
</script>

<template>
    <div :class="cn('relative min-w-0', props.class)">
        <Select v-model="selected" :disabled="disabled" @update:open="onOpen">
            <SelectTrigger
                v-bind="$attrs"
                class="h-9 w-full min-w-0 overflow-hidden rounded-lg bg-card shadow-xs transition-colors hover:border-foreground/30 data-[state=open]:border-ring data-[state=open]:ring-[3px] data-[state=open]:ring-ring/30"
            >
                <SelectValue :placeholder="placeholder" />
            </SelectTrigger>
            <SelectContent
                class="max-h-72 max-w-[min(28rem,calc(100vw-2rem))] rounded-xl border bg-popover p-0 shadow-xl"
            >
                <div
                    v-if="showSearch"
                    class="sticky top-0 z-10 border-b bg-popover p-2"
                    @keydown.stop
                >
                    <label class="relative block">
                        <span class="sr-only">Search options</span>
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            ref="searchInput"
                            v-model="query"
                            type="text"
                            placeholder="Search…"
                            autocomplete="off"
                            class="h-8 w-full rounded-lg border bg-card pr-2 pl-8 text-sm outline-none focus:border-ring focus:ring-[3px] focus:ring-ring/30"
                        />
                    </label>
                </div>
                <SelectItem
                    v-if="placeholder !== undefined"
                    :value="EMPTY"
                    class="rounded-lg py-2 text-muted-foreground"
                >
                    {{ placeholder }}
                </SelectItem>
                <SelectItem
                    v-for="option in shown"
                    :key="option.value"
                    :value="String(option.value)"
                    class="rounded-lg py-2 [&>span:last-child]:min-w-0 [&>span:last-child]:flex-1"
                >
                    <span class="block truncate" :title="option.label">{{
                        option.label
                    }}</span>
                </SelectItem>
                <p
                    v-if="showSearch && !matches.length"
                    class="px-3 py-4 text-center text-sm text-muted-foreground"
                >
                    No matches.
                </p>
                <p
                    v-else-if="matches.length > shown.length"
                    class="px-3 py-2 text-center text-xs text-muted-foreground"
                >
                    Showing {{ shown.length }} of {{ matches.length }}. Keep
                    typing to narrow it down.
                </p>
            </SelectContent>
        </Select>
    </div>
</template>
