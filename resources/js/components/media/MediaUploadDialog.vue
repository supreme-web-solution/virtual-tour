<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CloudUpload,
    Loader2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import FileThumb from '@/components/media/FileThumb.vue';
import { useMediaUpload } from '@/composables/useMediaUpload';
import { formatBytes } from '@/lib/format';
import type { AssetType, AssetTypeOption } from '@/types';

const props = defineProps<{
    assetTypes: AssetTypeOption[];
    properties: { id: string; name: string }[];
    defaultPropertyId?: string | null;
    defaultAssetType?: AssetType;
}>();

const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const configured = computed(() => page.props.features.media_uploads);

const assetType = ref<AssetType>(props.defaultAssetType ?? 'photo');
const propertyId = ref<string>(props.defaultPropertyId ?? '');
const dragging = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

const uploader = useMediaUpload(props.assetTypes);
const { items, running, finishedCount, failedCount } = uploader;

const selectedType = computed(() =>
    props.assetTypes.find((type) => type.value === assetType.value),
);
const accept = computed(
    () =>
        selectedType.value?.formats.map((format) => `.${format}`).join(',') ??
        '',
);
const propertyOptions = computed(() =>
    props.properties.map((property) => ({
        value: property.id,
        label: property.name,
    })),
);
const pendingCount = computed(
    () =>
        items.value.filter(
            (item) => item.status === 'queued' || item.status === 'error',
        ).length,
);

function onDrop(event: DragEvent): void {
    dragging.value = false;

    if (event.dataTransfer?.files.length) {
        uploader.add(event.dataTransfer.files);
    }
}

function onPick(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files?.length) {
        uploader.add(input.files);
    }

    input.value = '';
}

async function upload(): Promise<void> {
    await uploader.start(assetType.value, propertyId.value || null);

    if (finishedCount.value > 0) {
        router.reload();
    }
}

watch(open, (isOpen) => {
    if (running.value) {
        return;
    }

    if (isOpen) {
        propertyId.value = props.defaultPropertyId ?? '';
        assetType.value = props.defaultAssetType ?? assetType.value;
    } else {
        uploader.clear();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Upload media</DialogTitle>
                <DialogDescription>
                    Files go straight to secure cloud storage. Originals are
                    never modified.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="!configured"
                class="flex gap-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>
                    Media storage isn't configured yet. An administrator needs
                    to add the Cloudinary credentials to the server's
                    environment before files can be uploaded.
                </p>
            </div>

            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="upload-type">Media type</Label>
                        <NativeSelect
                            id="upload-type"
                            v-model="assetType"
                            :options="assetTypes"
                            :disabled="running"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="upload-property">Property</Label>
                        <NativeSelect
                            id="upload-property"
                            v-model="propertyId"
                            :options="propertyOptions"
                            placeholder="Not assigned"
                            :disabled="running"
                        />
                    </div>
                </div>

                <p
                    v-if="assetType === 'panorama'"
                    class="text-xs text-muted-foreground"
                >
                    Upload equirectangular 360° images (2:1 ratio) from cameras
                    such as Insta360 or Ricoh Theta. Images with another ratio
                    are accepted but flagged.
                </p>

                <button
                    type="button"
                    class="flex w-full flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed bg-card px-4 py-8 text-center transition-colors"
                    :class="
                        dragging
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-foreground/30'
                    "
                    :disabled="running"
                    @click="fileInput?.click()"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <CloudUpload class="size-7 text-muted-foreground" />
                    <span class="text-sm font-medium"
                        >Drop files here or click to browse</span
                    >
                    <span class="text-xs text-muted-foreground">
                        {{ selectedType?.formats.join(', ').toUpperCase() }} ·
                        up to
                        {{ formatBytes(selectedType?.max_bytes ?? 0) }} each
                    </span>
                </button>
                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    multiple
                    :accept="accept"
                    @change="onPick"
                />

                <ul
                    v-if="items.length"
                    class="max-h-64 divide-y overflow-y-auto rounded-xl border bg-card"
                >
                    <li
                        v-for="item in items"
                        :key="item.key"
                        class="flex min-w-0 items-center gap-3 px-3 py-2 text-sm"
                    >
                        <FileThumb :item="item" />
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="truncate">{{
                                    item.file.name
                                }}</span>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >{{ formatBytes(item.file.size) }}</span
                                >
                            </div>
                            <div
                                v-if="
                                    item.status === 'uploading' ||
                                    item.status === 'verifying'
                                "
                                class="mt-1.5 h-1 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full bg-primary transition-[width]"
                                    :style="{ width: `${item.progress}%` }"
                                />
                            </div>
                            <p
                                v-if="item.error"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ item.error }}
                            </p>
                            <p
                                v-else-if="
                                    item.asset?.panorama?.warnings.length
                                "
                                class="mt-1 text-xs text-amber-700 dark:text-amber-400"
                            >
                                {{ item.asset.panorama.warnings[0] }}
                            </p>
                        </div>
                        <Loader2
                            v-if="
                                item.status === 'uploading' ||
                                item.status === 'verifying'
                            "
                            class="size-4 shrink-0 animate-spin text-muted-foreground"
                        />
                        <CheckCircle2
                            v-else-if="item.status === 'done'"
                            class="size-4 shrink-0 text-emerald-600"
                        />
                        <button
                            v-else-if="!running"
                            type="button"
                            class="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                            :aria-label="`Remove ${item.file.name}`"
                            @click="uploader.remove(item.key)"
                        >
                            <X class="size-4" />
                        </button>
                    </li>
                </ul>
            </template>

            <DialogFooter class="items-center gap-2 sm:justify-between">
                <p class="text-xs text-muted-foreground">
                    <template v-if="finishedCount || failedCount">
                        {{ finishedCount }} uploaded<template v-if="failedCount"
                            >, {{ failedCount }} failed</template
                        >
                    </template>
                </p>
                <div class="flex gap-2">
                    <Button variant="ghost" @click="open = false">
                        {{ finishedCount && !pendingCount ? 'Done' : 'Cancel' }}
                    </Button>
                    <Button
                        v-if="configured"
                        :disabled="running || pendingCount === 0"
                        @click="upload"
                    >
                        <Loader2 v-if="running" class="animate-spin" />
                        {{
                            running
                                ? 'Uploading…'
                                : failedCount && pendingCount === failedCount
                                  ? 'Retry failed'
                                  : `Upload ${pendingCount || ''}`.trim()
                        }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
