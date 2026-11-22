<script setup lang="ts">
import { Images as HeaderIcon } from '@lucide/vue';
import { Head, router } from '@inertiajs/vue3';
import { Images, Search, Upload } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaDetailSheet from '@/components/media/MediaDetailSheet.vue';
import MediaSelectionBar from '@/components/media/MediaSelectionBar.vue';
import MediaUploadDialog from '@/components/media/MediaUploadDialog.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    useEnhancementWatcher,
    useMediaSelection,
} from '@/composables/useMediaSelection';
import media from '@/routes/media';
import type { AssetTypeOption, MediaAsset, Paginated } from '@/types';

const props = defineProps<{
    assets: Paginated<MediaAsset>;
    filters: { search: string; type: string; property: string };
    properties: { id: string; name: string }[];
    assetTypes: AssetTypeOption[];
    can: { upload: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Media Library', href: media.index() }],
    },
});

const filters = reactive({ ...props.filters });
const uploadOpen = ref(false);
const detailOpen = ref(false);
const selectedId = ref<string | null>(null);

// Re-resolve from props so the sheet reflects reloads after edits.
const selected = computed(
    () =>
        props.assets.data.find((asset) => asset.id === selectedId.value) ??
        null,
);

const pageAssets = computed(() => props.assets.data);
const { selectedIds, toggle, selectAll, clear } = useMediaSelection(pageAssets);
useEnhancementWatcher(pageAssets, ['assets']);

const propertyFilterOptions = computed(() => [
    { value: 'none', label: 'Not assigned' },
    ...props.properties.map((property) => ({
        value: property.id,
        label: property.name,
    })),
]);

const hasFilters = computed(
    () =>
        filters.search !== '' || filters.type !== '' || filters.property !== '',
);

watchDebounced(
    filters,
    () => {
        const query = Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== ''),
        );

        router.get(media.index.url({ query }), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    },
    { debounce: 300, deep: true },
);

function openAsset(asset: MediaAsset): void {
    selectedId.value = asset.id;
    detailOpen.value = true;
}
</script>

<template>
    <Head title="Media Library" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Media Library"
            :icon="HeaderIcon"
            tone="amber"
            description="Photos, 360° panoramas, video, audio, logos and floor plans across your workspace."
        >
            <template v-if="can.upload" #actions>
                <Button @click="uploadOpen = true"><Upload /> Upload</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-3 md:flex-row md:items-center">
            <div class="relative md:max-w-xs md:flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    type="search"
                    placeholder="Search by file name"
                    class="pl-9"
                    aria-label="Search media"
                />
            </div>
            <div class="grid grid-cols-2 gap-3 md:flex">
                <NativeSelect
                    v-model="filters.type"
                    :options="assetTypes"
                    placeholder="All types"
                    aria-label="Filter by type"
                    class="md:w-44"
                />
                <NativeSelect
                    v-model="filters.property"
                    :options="propertyFilterOptions"
                    placeholder="All properties"
                    aria-label="Filter by property"
                    class="md:w-52"
                />
            </div>
        </div>

        <div
            v-if="assets.data.length"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6"
        >
            <MediaCard
                v-for="asset in assets.data"
                :key="asset.id"
                :asset="asset"
                selectable
                :selected="selectedIds.includes(asset.id)"
                :selecting="selectedIds.length > 0"
                @select="openAsset"
                @toggle="toggle"
            />
        </div>

        <EmptyState
            v-else-if="hasFilters"
            :icon="Search"
            title="No matching media"
            description="Try a different search or filter."
        />

        <EmptyState
            v-else
            :icon="Images"
            title="Your library is empty"
            description="Upload property photos or existing 360° panoramas to get started."
        >
            <Button v-if="can.upload" @click="uploadOpen = true">
                <Upload /> Upload media
            </Button>
        </EmptyState>

        <MediaSelectionBar
            :ids="selectedIds"
            :total="assets.data.filter((asset) => asset.can.delete).length"
            @clear="clear"
            @select-all="selectAll"
        />

        <PaginationLinks :paginator="assets" />
    </div>

    <MediaUploadDialog
        v-model:open="uploadOpen"
        :asset-types="assetTypes"
        :properties="properties"
        :default-property-id="
            filters.property && filters.property !== 'none'
                ? filters.property
                : null
        "
    />

    <MediaDetailSheet
        v-model:open="detailOpen"
        :asset="selected"
        :asset-types="assetTypes"
        :properties="properties"
    />
</template>
