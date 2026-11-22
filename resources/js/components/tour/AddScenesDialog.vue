<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    Loader2,
    Orbit,
    Search,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import MediaUploadDialog from '@/components/media/MediaUploadDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useMediaSearch } from '@/composables/useMediaSearch';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { AssetTypeOption, LibraryPanorama } from '@/types';

const props = defineProps<{
    panoramas: LibraryPanorama[];
    usedMediaIds: string[];
    assetTypes: AssetTypeOption[];
    properties: { id: string; name: string }[];
    propertyId: string | null;
    canUpload: boolean;
    busy: boolean;
}>();

const emit = defineEmits<{
    add: [mediaIds: string[]];
}>();

const open = defineModel<boolean>('open', { default: false });

const selected = ref<string[]>([]);
const uploadOpen = ref(false);
const search = useMediaSearch(() => 'panorama');
const searching = computed(() => search.query.value.trim() !== '');

// Panoramas from the tour's property first, then the rest of the library.
// Searching looks through the whole library, not just the latest files.
const sorted = computed<LibraryPanorama[]>(() =>
    searching.value
        ? search.results.value.map((found) => ({
              ...found,
              from_tour_property: false,
          }))
        : [...props.panoramas].sort(
              (a, b) =>
                  Number(b.from_tour_property) - Number(a.from_tour_property),
          ),
);

watch(open, (isOpen) => {
    if (isOpen) {
        selected.value = [];
        search.query.value = '';
    }
});

watch(uploadOpen, (isOpen) => {
    if (!isOpen) {
        router.reload({ only: ['panoramas'] });
    }
});

function toggle(id: string): void {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((value) => value !== id)
        : [...selected.value, id];
}

function submit(): void {
    emit('add', selected.value);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[85vh] flex-col sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Add scenes</DialogTitle>
                <DialogDescription>
                    Choose images from your library. Each one becomes a scene,
                    added in the order you select them. Regular photos are
                    converted to 360° automatically when AI 360° is set up.
                </DialogDescription>
            </DialogHeader>

            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search.query.value"
                    type="search"
                    placeholder="Search all panoramas by file name"
                    class="pl-9"
                    aria-label="Search panoramas"
                />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div
                    v-if="sorted.length"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    <button
                        v-for="panorama in sorted"
                        :key="panorama.id"
                        type="button"
                        class="group relative overflow-hidden rounded-md border text-left"
                        :class="
                            selected.includes(panorama.id)
                                ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                                : ''
                        "
                        @click="toggle(panorama.id)"
                    >
                        <div class="aspect-[2/1] bg-muted">
                            <img
                                v-if="panorama.thumbnail_url"
                                :src="panorama.thumbnail_url"
                                :alt="panorama.filename ?? 'Panorama'"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                        </div>
                        <div class="space-y-0.5 p-2">
                            <p class="truncate text-xs font-medium">
                                {{ panorama.filename ?? 'Untitled' }}
                            </p>
                            <p
                                class="truncate text-[11px] text-muted-foreground"
                            >
                                {{ panorama.property ?? 'No property' }}
                                <template
                                    v-if="usedMediaIds.includes(panorama.id)"
                                >
                                    · already in tour</template
                                >
                            </p>
                        </div>
                        <span
                            v-if="selected.includes(panorama.id)"
                            class="absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-full bg-primary text-xs font-medium text-primary-foreground"
                        >
                            {{ selected.indexOf(panorama.id) + 1 }}
                        </span>
                        <span
                            v-else-if="!panorama.is_equirectangular"
                            class="absolute top-1.5 right-1.5 rounded-full bg-amber-500 p-1 text-white"
                            title="Regular photo: converted to 360° when added (if AI 360° is set up)"
                        >
                            <AlertTriangle class="size-3" />
                        </span>
                    </button>
                </div>
                <p
                    v-if="searching && search.loading.value && !sorted.length"
                    class="flex items-center gap-2 py-8 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" /> Searching…
                </p>
                <EmptyState
                    v-else-if="searching && !sorted.length"
                    :icon="Search"
                    title="No panoramas match"
                    description="Try a different part of the file name."
                />
                <EmptyState
                    v-else-if="!sorted.length"
                    :icon="Orbit"
                    title="No 360° panoramas yet"
                    description="Upload equirectangular images from your 360° camera to build scenes."
                />
                <div
                    v-if="searching && search.hasMore.value"
                    class="pt-3 text-center"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="search.loading.value"
                        @click="search.more()"
                        >Show more</Button
                    >
                </div>
            </div>

            <DialogFooter class="items-center gap-2 sm:justify-between">
                <Button
                    v-if="canUpload"
                    variant="outline"
                    @click="uploadOpen = true"
                >
                    <Upload /> Upload panoramas
                </Button>
                <span v-else />
                <Button :disabled="!selected.length || busy" @click="submit">
                    <Check />
                    Add
                    {{
                        selected.length
                            ? `${selected.length} scene${selected.length === 1 ? '' : 's'}`
                            : 'scenes'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <MediaUploadDialog
        v-if="canUpload"
        v-model:open="uploadOpen"
        :asset-types="assetTypes"
        :properties="properties"
        :default-property-id="propertyId"
        default-asset-type="panorama"
    />
</template>
