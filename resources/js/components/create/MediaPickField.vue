<script setup lang="ts">
import {
    Check,
    ImagePlus,
    Loader2,
    Search,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FileThumb from '@/components/media/FileThumb.vue';
import { Input } from '@/components/ui/input';
import { useMediaSearch } from '@/composables/useMediaSearch';
import { useMediaUpload } from '@/composables/useMediaUpload';
import type { AssetTypeOption, PickableAsset } from '@/types';

/**
 * Pick media for a new tour: drop files to upload them, or choose from what
 * is already in the library. Uploaded files are selected automatically.
 */
const props = defineProps<{
    assetType: 'photo' | 'panorama';
    assetTypes: AssetTypeOption[];
    recent: PickableAsset[];
    max: number;
    propertyId: string | null;
}>();

const selected = defineModel<string[]>({ required: true });
const emit = defineEmits<{ busy: [value: boolean] }>();

const upload = useMediaUpload(props.assetTypes);
const fileInput = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const showLibrary = ref(false);
const uploaded = ref<PickableAsset[]>([]);
const search = useMediaSearch(() => props.assetType);

const known = computed<Record<string, PickableAsset>>(() =>
    Object.fromEntries(
        [...uploaded.value, ...props.recent, ...search.results.value].map(
            (asset) => [asset.id, asset],
        ),
    ),
);
const chosen = computed(() =>
    selected.value
        .map((id) => known.value[id])
        .filter((asset): asset is PickableAsset => asset !== undefined),
);
const pending = computed(() =>
    upload.items.value.filter((item) => item.status !== 'done'),
);
const searching = computed(() => search.query.value.trim() !== '');
const libraryItems = computed(() =>
    (searching.value ? search.results.value : props.recent).filter(
        (asset) => !selected.value.includes(asset.id),
    ),
);
const label = computed(() =>
    props.assetType === 'panorama' ? '360° photos' : 'photos',
);
const accept = computed(() =>
    props.assetType === 'panorama'
        ? '.jpg,.jpeg,.png,.webp'
        : '.jpg,.jpeg,.png,.webp,.heic,.heif',
);

watch(
    () => upload.running.value,
    (running) => emit('busy', running),
);

// Select each file as soon as it finishes uploading.
watch(
    () => upload.items.value.map((item) => item.asset?.id ?? null),
    () => {
        for (const item of upload.items.value) {
            if (
                item.asset &&
                !uploaded.value.some((asset) => asset.id === item.asset?.id)
            ) {
                uploaded.value = [
                    ...uploaded.value,
                    {
                        id: item.asset.id,
                        name: item.asset.original_filename,
                        thumbnail_url: item.asset.thumbnail_url,
                        width: item.asset.width,
                        height: item.asset.height,
                    },
                ];

                if (selected.value.length < props.max) {
                    selected.value = [...selected.value, item.asset.id];
                }
            }
        }

        upload.items.value
            .filter((item) => item.status === 'done')
            .forEach((item) => upload.remove(item.key));
    },
);

function addFiles(files: FileList | null): void {
    if (!files?.length) {
        return;
    }

    upload.add(files);
    void upload.start(props.assetType, props.propertyId);
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    addFiles(event.dataTransfer?.files ?? null);
}

function onPick(event: Event): void {
    addFiles((event.target as HTMLInputElement).files);
    (event.target as HTMLInputElement).value = '';
}

function toggle(id: string): void {
    if (selected.value.includes(id)) {
        selected.value = selected.value.filter((value) => value !== id);
    } else if (selected.value.length < props.max) {
        selected.value = [...selected.value, id];
    }
}

function sizeLabel(asset: PickableAsset): string {
    return asset.width && asset.height
        ? `${asset.width} × ${asset.height}`
        : '';
}
</script>

<template>
    <div class="min-w-0 space-y-3">
        <button
            type="button"
            class="flex w-full flex-col items-center gap-1.5 rounded-xl border border-dashed bg-card px-4 py-6 text-center transition-colors"
            :class="
                dragging
                    ? 'border-primary bg-primary/5'
                    : 'hover:border-foreground/30'
            "
            @click="fileInput?.click()"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <ImagePlus class="size-6 text-muted-foreground" />
            <span class="text-sm font-medium"
                >Drop {{ label }} here or click to upload</span
            >
            <span class="text-xs text-muted-foreground">
                <template v-if="assetType === 'panorama'"
                    >Equirectangular 2:1 images from any 360° camera</template
                >
                <template v-else
                    >Phone or camera photos, one per room or area</template
                >
            </span>
        </button>
        <input
            ref="fileInput"
            type="file"
            multiple
            :accept="accept"
            class="hidden"
            :aria-label="`Upload ${label}`"
            @change="onPick"
        />

        <ul v-if="chosen.length || pending.length" class="space-y-1.5">
            <li
                v-for="asset in chosen"
                :key="asset.id"
                class="flex items-center gap-3 rounded-lg border bg-card p-2"
            >
                <img
                    v-if="asset.thumbnail_url"
                    :src="asset.thumbnail_url"
                    alt=""
                    class="h-10 w-14 shrink-0 rounded-md object-cover"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">
                        {{ asset.name ?? 'Image' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ assetType === 'panorama' ? '360°' : 'Photo'
                        }}<template v-if="sizeLabel(asset)">
                            · {{ sizeLabel(asset) }}</template
                        >
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded p-1 text-muted-foreground hover:text-foreground"
                    :aria-label="`Remove ${asset.name ?? 'image'}`"
                    @click="toggle(asset.id)"
                >
                    <X class="size-4" />
                </button>
            </li>
            <li
                v-for="item in pending"
                :key="item.key"
                class="flex min-w-0 items-center gap-3 rounded-lg border bg-card p-2 text-sm"
            >
                <FileThumb :item="item" />
                <div class="min-w-0 flex-1">
                    <p class="truncate">{{ item.file.name }}</p>
                    <p
                        class="text-xs"
                        :class="
                            item.status === 'error'
                                ? 'text-red-600'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            item.status === 'error'
                                ? item.error
                                : `Uploading · ${item.progress}%`
                        }}
                    </p>
                </div>
                <button
                    v-if="item.status === 'error'"
                    type="button"
                    class="rounded p-1 text-muted-foreground hover:text-foreground"
                    aria-label="Dismiss"
                    @click="upload.remove(item.key)"
                >
                    <X class="size-4" />
                </button>
            </li>
        </ul>

        <div v-if="recent.length">
            <button
                type="button"
                class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                @click="showLibrary = !showLibrary"
            >
                {{
                    showLibrary
                        ? 'Hide library'
                        : 'Or choose from your media library'
                }}
            </button>
            <div v-if="showLibrary" class="mt-2 space-y-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search.query.value"
                        type="search"
                        placeholder="Search your whole library by file name"
                        class="h-9 pl-9"
                        aria-label="Search media library"
                    />
                </div>
                <div
                    class="grid max-h-48 grid-cols-4 gap-2 overflow-y-auto pr-1 sm:grid-cols-6"
                >
                    <button
                        v-for="asset in libraryItems"
                        :key="asset.id"
                        type="button"
                        class="relative aspect-[4/3] overflow-hidden rounded-md border bg-muted hover:opacity-90"
                        :title="asset.name ?? undefined"
                        @click="toggle(asset.id)"
                    >
                        <img
                            v-if="asset.thumbnail_url"
                            :src="asset.thumbnail_url"
                            :alt="asset.name ?? ''"
                            class="size-full object-cover"
                            loading="lazy"
                        />
                        <span
                            class="absolute right-1 bottom-1 rounded-full bg-black/60 p-0.5 text-white"
                            ><Check class="size-3"
                        /></span>
                    </button>
                    <p
                        v-if="search.loading.value && !libraryItems.length"
                        class="col-span-full flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Loader2 class="size-3.5 animate-spin" /> Searching…
                    </p>
                    <p
                        v-else-if="!libraryItems.length"
                        class="col-span-full text-xs text-muted-foreground"
                    >
                        {{
                            searching
                                ? 'No files match that name.'
                                : 'Everything in your library is already selected.'
                        }}
                    </p>
                </div>
                <button
                    v-if="searching && search.hasMore.value"
                    type="button"
                    class="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    :disabled="search.loading.value"
                    @click="search.more()"
                >
                    Show more
                </button>
            </div>
        </div>
    </div>
</template>
