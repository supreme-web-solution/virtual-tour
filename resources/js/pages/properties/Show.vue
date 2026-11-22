<script setup lang="ts">
import CommentsPanel from '@/components/CommentsPanel.vue';
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    Bath,
    BedDouble,
    Copy,
    Images,
    MapPin,
    Orbit,
    Pencil,
    Plus,
    Ruler,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaDetailSheet from '@/components/media/MediaDetailSheet.vue';
import MediaSelectionBar from '@/components/media/MediaSelectionBar.vue';
import {
    useEnhancementWatcher,
    useMediaSelection,
} from '@/composables/useMediaSelection';
import MediaUploadDialog from '@/components/media/MediaUploadDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import PropertyFormDialog from '@/components/property/PropertyFormDialog.vue';
import PropertyStatusBadge from '@/components/property/PropertyStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCreateTour } from '@/composables/useCreateTour';
import { formatPrice } from '@/lib/format';
import mediaRoutes from '@/routes/media';
import properties from '@/routes/properties';
import tourRoutes from '@/routes/tours';
import type {
    AssetType,
    AssetTypeOption,
    MediaAsset,
    Property,
    PropertyOptions,
    TeamComments,
} from '@/types';

const props = defineProps<{
    property: Property;
    media: MediaAsset[];
    mediaCounts: Record<AssetType, number>;
    assetTypes: AssetTypeOption[];
    properties: { id: string; name: string }[];
    tours: {
        id: string;
        title: string;
        status: 'draft' | 'published';
        scenes_count: number;
    }[];
    can: { duplicate: boolean; upload: boolean; createTour: boolean };
    comments: TeamComments;
    options: PropertyOptions;
    coverChoices: {
        id: string;
        thumbnail_url: string | null;
        name: string | null;
        asset_type: string;
    }[];
}>();

const editOpen = ref(false);
const { openCreateTour } = useCreateTour();

// Old "edit property" links arrive with ?edit=1.
onMounted(() => {
    if (
        props.property.can.update &&
        new URLSearchParams(window.location.search).has('edit')
    ) {
        editOpen.value = true;
    }
});

setLayoutProps({
    breadcrumbs: [
        { title: 'Properties', href: properties.index() },
        {
            title: props.property.name,
            href: properties.show(props.property.id),
        },
    ],
});

const uploadOpen = ref(false);
const detailOpen = ref(false);
const deleteOpen = ref(false);
const selectedId = ref<string | null>(null);
const typeFilter = ref<AssetType | ''>('');

const duplicateForm = useForm({});
const deleteForm = useForm({});

const selected = computed(
    () => props.media.find((asset) => asset.id === selectedId.value) ?? null,
);
const visibleMedia = computed(() =>
    typeFilter.value
        ? props.media.filter((asset) => asset.asset_type === typeFilter.value)
        : props.media,
);
const { selectedIds, toggle, selectAll, clear } =
    useMediaSelection(visibleMedia);
useEnhancementWatcher(
    computed(() => props.media),
    ['media', 'mediaCounts'],
);
const totalMedia = computed(() =>
    Object.values(props.mediaCounts).reduce((sum, count) => sum + count, 0),
);
const typeChips = computed(() =>
    props.assetTypes.filter(
        (type) => props.mediaCounts[type.value as AssetType] > 0,
    ),
);
const price = computed(() =>
    formatPrice(props.property.price, props.property.currency),
);

function openAsset(asset: MediaAsset): void {
    selectedId.value = asset.id;
    detailOpen.value = true;
}
</script>

<template>
    <Head :title="property.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="property.name">
            <template #meta>
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                >
                    <PropertyStatusBadge
                        :status="property.status"
                        :label="property.status_label"
                    />
                    <span>{{ property.property_type_label }}</span>
                    <span
                        v-if="property.location"
                        class="flex items-center gap-1"
                    >
                        <MapPin class="size-3.5" /> {{ property.location }}
                    </span>
                </div>
            </template>
            <template #actions>
                <Button
                    v-if="can.upload"
                    variant="outline"
                    @click="uploadOpen = true"
                >
                    <Upload /> Upload
                </Button>
                <Button
                    v-if="can.duplicate"
                    variant="outline"
                    :disabled="duplicateForm.processing"
                    @click="
                        duplicateForm.submit(properties.duplicate(property.id))
                    "
                >
                    <Copy /> Duplicate
                </Button>
                <Button v-if="property.can.update" @click="editOpen = true">
                    <Pencil /> Edit
                </Button>
                <Button
                    v-if="property.can.delete"
                    variant="ghost"
                    size="icon"
                    class="text-destructive hover:text-destructive"
                    aria-label="Delete property"
                    @click="deleteOpen = true"
                >
                    <Trash2 />
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <section class="min-w-0 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-medium">
                        Media
                        <span class="font-normal text-muted-foreground">{{
                            totalMedia
                        }}</span>
                    </h2>
                    <div
                        v-if="typeChips.length > 1"
                        class="flex flex-wrap gap-1.5"
                    >
                        <button
                            type="button"
                            class="rounded-full border px-2.5 py-0.5 text-xs transition-colors"
                            :class="
                                typeFilter === ''
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-accent'
                            "
                            @click="typeFilter = ''"
                        >
                            All
                        </button>
                        <button
                            v-for="type in typeChips"
                            :key="type.value"
                            type="button"
                            class="rounded-full border px-2.5 py-0.5 text-xs transition-colors"
                            :class="
                                typeFilter === type.value
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-accent'
                            "
                            @click="typeFilter = type.value as AssetType"
                        >
                            {{ type.label }}
                            {{ mediaCounts[type.value as AssetType] }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="visibleMedia.length"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4"
                >
                    <MediaCard
                        v-for="asset in visibleMedia"
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
                    v-else
                    :icon="Images"
                    title="No media for this property"
                    description="Upload photos or 360° panoramas taken at this property."
                >
                    <Button v-if="can.upload" @click="uploadOpen = true">
                        <Upload /> Upload media
                    </Button>
                </EmptyState>
                <MediaSelectionBar
                    :ids="selectedIds"
                    :total="
                        visibleMedia.filter((asset) => asset.can.delete).length
                    "
                    @clear="clear"
                    @select-all="selectAll"
                />

                <p
                    v-if="totalMedia > props.media.length"
                    class="text-sm text-muted-foreground"
                >
                    Showing the latest {{ props.media.length }} files.
                    <Link
                        :href="
                            mediaRoutes.index.url({
                                query: { property: property.id },
                            })
                        "
                        class="underline underline-offset-4"
                        >View all in the media library</Link
                    >
                </p>
            </section>

            <aside class="space-y-4">
                <CommentsPanel :comments="comments" />
                <div class="rounded-lg border bg-card shadow-sm">
                    <div
                        class="flex items-center justify-between border-b px-4 py-2.5"
                    >
                        <h2 class="text-sm font-medium">Virtual tours</h2>
                        <Button
                            v-if="can.createTour"
                            variant="ghost"
                            size="sm"
                            class="h-7"
                            @click="openCreateTour('photos', property.id)"
                        >
                            <Plus /> New
                        </Button>
                    </div>
                    <ul v-if="tours.length" class="divide-y">
                        <li v-for="tour in tours" :key="tour.id">
                            <Link
                                :href="tourRoutes.edit(tour.id)"
                                class="flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-accent/50"
                            >
                                <Orbit
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    tour.title
                                }}</span>
                                <span class="text-xs text-muted-foreground"
                                    >{{ tour.scenes_count }} ·
                                    {{
                                        tour.status === 'published'
                                            ? 'Live'
                                            : 'Draft'
                                    }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-3 text-sm text-muted-foreground">
                        No tours for this property yet.
                    </p>
                </div>

                <div
                    class="overflow-hidden rounded-lg border bg-card shadow-sm"
                >
                    <div
                        v-if="property.cover?.thumbnail_url"
                        class="aspect-[16/10] bg-muted"
                    >
                        <img
                            :src="property.cover.thumbnail_url"
                            :alt="property.name"
                            class="size-full object-cover"
                        />
                    </div>
                    <div class="space-y-4 p-4">
                        <div
                            v-if="
                                property.bedrooms !== null ||
                                property.bathrooms !== null ||
                                property.area !== null
                            "
                            class="flex flex-wrap gap-4 text-sm"
                        >
                            <span
                                v-if="property.bedrooms !== null"
                                class="flex items-center gap-1.5"
                            >
                                <BedDouble
                                    class="size-4 text-muted-foreground"
                                />
                                {{ property.bedrooms }} bed
                            </span>
                            <span
                                v-if="property.bathrooms !== null"
                                class="flex items-center gap-1.5"
                            >
                                <Bath class="size-4 text-muted-foreground" />
                                {{ Number(property.bathrooms) }} bath
                            </span>
                            <span
                                v-if="property.area !== null"
                                class="flex items-center gap-1.5"
                            >
                                <Ruler class="size-4 text-muted-foreground" />
                                {{ Number(property.area).toLocaleString() }}
                                {{
                                    property.area_unit === 'sqm' ? 'm²' : 'ft²'
                                }}
                            </span>
                        </div>
                        <p v-if="price" class="text-lg font-semibold">
                            {{ price }}
                        </p>
                        <p
                            v-if="property.description"
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ property.description }}
                        </p>
                        <dl
                            v-if="property.address || property.latitude"
                            class="space-y-2 border-t pt-4 text-sm"
                        >
                            <div v-if="property.address">
                                <dt class="text-muted-foreground">Address</dt>
                                <dd>{{ property.address }}</dd>
                            </div>
                            <div v-if="property.latitude">
                                <dt class="text-muted-foreground">
                                    Coordinates
                                </dt>
                                <dd>
                                    {{ Number(property.latitude) }},
                                    {{ Number(property.longitude) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <MediaUploadDialog
        v-model:open="uploadOpen"
        :asset-types="assetTypes"
        :properties="props.properties"
        :default-property-id="property.id"
    />

    <MediaDetailSheet
        v-model:open="detailOpen"
        :asset="selected"
        :asset-types="assetTypes"
        :properties="props.properties"
    />

    <Dialog v-model:open="deleteOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ property.name }}?</DialogTitle>
                <DialogDescription>
                    The property will be removed. Its
                    {{ totalMedia }} media file{{ totalMedia === 1 ? '' : 's' }}
                    stay in the media library, marked as not assigned.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="ghost" @click="deleteOpen = false"
                    >Cancel</Button
                >
                <Button
                    variant="destructive"
                    :disabled="deleteForm.processing"
                    @click="deleteForm.submit(properties.destroy(property.id))"
                    >Delete property</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
    <PropertyFormDialog
        v-if="property.can.update"
        v-model:open="editOpen"
        :options="options"
        :property="property"
        :cover-choices="coverChoices"
    />
</template>
