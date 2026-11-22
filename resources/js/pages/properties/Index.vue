<script setup lang="ts">
import { Building2 as HeaderIcon } from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Images, Link2, MapPin, Plus, Search } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { onMounted, reactive, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import PropertyStatusBadge from '@/components/property/PropertyStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { timeAgo } from '@/lib/format';
import PropertyFormDialog from '@/components/property/PropertyFormDialog.vue';
import { useCreateTour } from '@/composables/useCreateTour';
import properties from '@/routes/properties';
import type { Paginated, Property, PropertyOptions } from '@/types';

const props = defineProps<{
    properties: Paginated<Property>;
    filters: { search: string; type: string; status: string; sort: string };
    options: PropertyOptions;
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Properties', href: properties.index() }],
    },
});

const filters = reactive({ ...props.filters });
const createOpen = ref(false);
const { openCreateTour } = useCreateTour();

// Old "new property" links arrive with ?create=1.
onMounted(() => {
    if (
        props.can.create &&
        new URLSearchParams(window.location.search).has('create')
    ) {
        createOpen.value = true;
    }
});

const sortOptions = [
    { value: 'latest', label: 'Newest first' },
    { value: 'updated', label: 'Recently updated' },
    { value: 'name', label: 'Name A–Z' },
    { value: 'oldest', label: 'Oldest first' },
];

watchDebounced(
    filters,
    () => {
        const query = Object.fromEntries(
            Object.entries(filters).filter(
                ([key, value]) =>
                    value !== '' && !(key === 'sort' && value === 'latest'),
            ),
        );

        router.get(properties.index.url({ query }), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    },
    { debounce: 300, deep: true },
);

const hasFilters = () =>
    filters.search !== '' || filters.type !== '' || filters.status !== '';

function clearFilters(): void {
    Object.assign(filters, { search: '', type: '', status: '' });
}
</script>

<template>
    <Head title="Properties" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Properties"
            :icon="HeaderIcon"
            tone="indigo"
            description="Every tour, video and media file belongs to a property."
        >
            <template v-if="can.create" #actions>
                <Button variant="outline" @click="openCreateTour('link')">
                    <Link2 /> Import from a listing link
                </Button>
                <Button @click="createOpen = true">
                    <Plus /> New property
                </Button>
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
                    placeholder="Search name or location"
                    class="pl-9"
                    aria-label="Search properties"
                />
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:flex">
                <NativeSelect
                    v-model="filters.type"
                    :options="options.types"
                    placeholder="All types"
                    aria-label="Filter by type"
                    class="md:w-44"
                />
                <NativeSelect
                    v-model="filters.status"
                    :options="options.statuses"
                    placeholder="All statuses"
                    aria-label="Filter by status"
                    class="md:w-36"
                />
                <NativeSelect
                    v-model="filters.sort"
                    :options="sortOptions"
                    aria-label="Sort"
                    class="col-span-2 sm:col-span-1 md:w-44"
                />
            </div>
        </div>

        <div
            v-if="props.properties.data.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="property in props.properties.data"
                :key="property.id"
                :href="properties.show(property.id)"
                class="group overflow-hidden rounded-lg border bg-card transition-colors hover:border-foreground/20"
            >
                <div class="aspect-[16/9] overflow-hidden bg-muted">
                    <img
                        v-if="property.cover?.thumbnail_url"
                        :src="property.cover.thumbnail_url"
                        :alt="property.name"
                        class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                        loading="lazy"
                    />
                    <div
                        v-else
                        class="flex size-full items-center justify-center"
                    >
                        <Building2 class="size-8 text-muted-foreground/50" />
                    </div>
                </div>
                <div class="space-y-2 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="truncate font-medium">
                            {{ property.name }}
                        </h3>
                        <PropertyStatusBadge
                            :status="property.status"
                            :label="property.status_label"
                        />
                    </div>
                    <p
                        v-if="property.location"
                        class="flex items-center gap-1.5 truncate text-sm text-muted-foreground"
                    >
                        <MapPin class="size-3.5 shrink-0" />
                        {{ property.location }}
                    </p>
                    <div
                        class="flex items-center justify-between pt-1 text-xs text-muted-foreground"
                    >
                        <span>{{ property.property_type_label }}</span>
                        <span class="flex items-center gap-3">
                            <span class="flex items-center gap-1">
                                <Images class="size-3.5" />
                                {{ property.media_count ?? 0 }}
                            </span>
                            <span>{{ timeAgo(property.updated_at) }}</span>
                        </span>
                    </div>
                </div>
            </Link>
        </div>

        <EmptyState
            v-else-if="hasFilters()"
            :icon="Search"
            title="No matching properties"
            description="Try a different search or clear the filters."
        >
            <Button variant="outline" @click="clearFilters"
                >Clear filters</Button
            >
        </EmptyState>

        <EmptyState
            v-else
            :icon="Building2"
            title="No properties yet"
            description="Create a property to start uploading photos and 360° panoramas."
        >
            <Button v-if="can.create" @click="createOpen = true">
                <Plus /> Create your first property
            </Button>
        </EmptyState>

        <PaginationLinks :paginator="props.properties" />
    </div>

    <PropertyFormDialog
        v-if="can.create"
        v-model:open="createOpen"
        :options="options"
    />
</template>
