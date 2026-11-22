<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import tours from '@/routes/tours';

/**
 * Quick search in the sidebar: finds tours by name. Ctrl/Cmd + K focuses it.
 */
const query = ref('');
const input = ref<HTMLInputElement | null>(null);

function onKey(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        input.value?.focus();
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

function search(): void {
    const term = query.value.trim();

    router.get(tours.index.url({ query: term ? { q: term } : {} }));
}
</script>

<template>
    <form
        class="relative px-3 pb-1 group-data-[collapsible=icon]:hidden"
        role="search"
        @submit.prevent="search"
    >
        <label class="sr-only" for="sidebar-search">Search tours</label>
        <Search
            class="pointer-events-none absolute top-[calc(50%-2px)] left-6 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
            id="sidebar-search"
            ref="input"
            v-model="query"
            type="search"
            placeholder="Search tours…"
            class="h-10 w-full rounded-full border bg-card pr-16 pl-9 text-sm shadow-xs transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
        />
        <kbd
            class="pointer-events-none absolute top-[calc(50%-2px)] right-6 -translate-y-1/2 rounded-md border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
            >Ctrl K</kbd
        >
    </form>
</template>
