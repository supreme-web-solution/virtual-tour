<script setup lang="ts">
import {
    Check,
    Circle,
    LayoutPanelTop,
    Loader2,
    MapPin,
    Plus,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PanelSection from '@/components/tour/PanelSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TourEditor } from '@/composables/useTourEditor';
import { useMediaUpload } from '@/composables/useMediaUpload';
import type { AssetTypeOption, LibraryAsset } from '@/types';

const props = defineProps<{
    editor: TourEditor;
    library: LibraryAsset[];
    assetTypes: AssetTypeOption[];
    canEdit: boolean;
}>();

const tour = computed(() => props.editor.tour.value);
const currentScene = computed(() => props.editor.currentScene.value);
const selectedId = ref<string | null>(tour.value.floorplans[0]?.id ?? null);
const adding = ref(false);
const newAssetId = ref('');
const newTitle = ref('Ground floor');
const rename = ref('');

// Upload the plan image right here instead of detouring through the library.
const upload = useMediaUpload(props.assetTypes);
const fileInput = ref<HTMLInputElement | null>(null);
const uploaded = ref<LibraryAsset[]>([]);
const planOptions = computed(() =>
    [...uploaded.value, ...props.library].map((asset) => ({
        value: asset.id,
        label: asset.name ?? 'Floor plan',
    })),
);
const uploadError = computed(
    () => upload.items.value.find((item) => item.status === 'error')?.error,
);

function pickFile(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files?.length) {
        upload.clear();
        upload.add([input.files[0]]);
        void upload.start('floor_plan', null);
    }

    input.value = '';
}

watch(
    () => upload.items.value.map((item) => item.asset?.id ?? null),
    () => {
        for (const item of upload.items.value) {
            if (item.asset) {
                uploaded.value = [
                    {
                        id: item.asset.id,
                        name: item.asset.original_filename,
                        type: 'floor_plan',
                        thumbnail_url: item.asset.thumbnail_url,
                        url: item.asset.secure_url,
                    },
                    ...uploaded.value,
                ];
                newAssetId.value = item.asset.id;
                upload.remove(item.key);
            }
        }
    },
);

const floorplan = computed(
    () =>
        tour.value.floorplans.find((plan) => plan.id === selectedId.value) ??
        null,
);
const sceneTitles = computed(() =>
    Object.fromEntries(
        tour.value.scenes.map((scene) => [scene.id, scene.title]),
    ),
);
const currentMarker = computed(
    () =>
        floorplan.value?.markers.find(
            (marker) => marker.scene_id === currentScene.value?.id,
        ) ?? null,
);
const placedIds = computed(
    () => new Set(floorplan.value?.markers.map((marker) => marker.scene_id)),
);

watch(
    () => tour.value.floorplans.map((plan) => plan.id).join(),
    () => {
        if (!floorplan.value) {
            selectedId.value = tour.value.floorplans.at(-1)?.id ?? null;
        }
    },
);
watch(
    floorplan,
    (plan) => {
        rename.value = plan?.title ?? '';
    },
    { immediate: true },
);

async function add(): Promise<void> {
    if (!newAssetId.value || !newTitle.value.trim()) {
        return;
    }

    await props.editor.addFloorplan(newAssetId.value, newTitle.value.trim());
    selectedId.value = tour.value.floorplans.at(-1)?.id ?? null;
    adding.value = false;
    newAssetId.value = '';
}

function placeMarker(event: MouseEvent): void {
    if (!props.canEdit || !floorplan.value || !currentScene.value) {
        return;
    }

    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const x = Math.min(
        1,
        Math.max(0, (event.clientX - rect.left) / rect.width),
    );
    const y = Math.min(
        1,
        Math.max(0, (event.clientY - rect.top) / rect.height),
    );

    void props.editor.placeFloorplanMarker(
        floorplan.value.id,
        currentScene.value.id,
        Number(x.toFixed(5)),
        Number(y.toFixed(5)),
    );
}

function saveTitle(): void {
    if (
        floorplan.value &&
        rename.value.trim() &&
        rename.value.trim() !== floorplan.value.title
    ) {
        void props.editor.renameFloorplan(
            floorplan.value.id,
            rename.value.trim(),
        );
    }
}
</script>

<template>
    <div class="min-w-0 space-y-3 overflow-x-hidden p-3">
        <PanelSection
            title="Floor plan"
            :icon="LayoutPanelTop"
            tone="sky"
            description="The map visitors use to jump between rooms"
        >
            <div class="flex items-center gap-2">
                <NativeSelect
                    v-if="tour.floorplans.length"
                    class="flex-1"
                    :model-value="selectedId ?? ''"
                    :options="
                        tour.floorplans.map((plan) => ({
                            value: plan.id,
                            label: plan.title,
                        }))
                    "
                    aria-label="Floor plan"
                    @update:model-value="selectedId = $event || null"
                />
                <Button
                    v-if="canEdit"
                    variant="outline"
                    size="sm"
                    :class="{ 'flex-1': !tour.floorplans.length }"
                    @click="adding = !adding"
                >
                    <Plus />
                    {{ tour.floorplans.length ? 'Add' : 'Add a floor plan' }}
                </Button>
            </div>

            <div v-if="adding" class="space-y-3 rounded-md border p-3">
                <div class="grid gap-2">
                    <Label for="floorplan-image">Floor plan image</Label>
                    <NativeSelect
                        id="floorplan-image"
                        v-model="newAssetId"
                        :options="planOptions"
                        placeholder="Choose an image"
                    />
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp"
                        class="sr-only"
                        tabindex="-1"
                        @change="pickFile"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="upload.running.value"
                        @click="fileInput?.click()"
                    >
                        <Loader2
                            v-if="upload.running.value"
                            class="animate-spin"
                        />
                        <Upload v-else />
                        {{
                            upload.running.value
                                ? 'Uploading…'
                                : 'Upload a floor plan image'
                        }}
                    </Button>
                    <p v-if="uploadError" class="text-xs text-destructive">
                        {{ uploadError }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Use a picture or drawing of the layout from above (PNG,
                        JPG or WebP).
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="floorplan-title">Name</Label>
                    <Input
                        id="floorplan-title"
                        v-model="newTitle"
                        maxlength="120"
                    />
                </div>
                <Button
                    size="sm"
                    :disabled="!newAssetId || !newTitle.trim()"
                    @click="add"
                    >Add floor plan</Button
                >
            </div>

            <div v-if="floorplan" class="flex items-center gap-2">
                <Input
                    v-model="rename"
                    :disabled="!canEdit"
                    maxlength="120"
                    aria-label="Floor plan name"
                    class="min-w-0"
                    @blur="saveTitle"
                />
                <Button
                    v-if="canEdit"
                    variant="ghost"
                    size="icon"
                    class="shrink-0 text-destructive hover:text-destructive"
                    aria-label="Delete floor plan"
                    @click="editor.deleteFloorplan(floorplan.id)"
                >
                    <Trash2 />
                </Button>
            </div>
            <p v-else-if="!adding" class="text-sm text-muted-foreground">
                Add a floor plan so visitors can see where each scene is and
                jump between rooms.
            </p>
        </PanelSection>

        <PanelSection
            v-if="floorplan"
            title="Place your scenes"
            :icon="MapPin"
            tone="emerald"
            description="Click the plan to drop the current scene"
        >
            <div
                v-if="canEdit && currentScene"
                class="flex items-center gap-3 rounded-xl border bg-muted/30 p-3"
            >
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-full"
                    :class="
                        currentMarker
                            ? 'bg-emerald-500/15 text-emerald-600'
                            : 'bg-amber-500/15 text-amber-600'
                    "
                >
                    <Check v-if="currentMarker" class="size-4" />
                    <MapPin v-else class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p
                        class="truncate text-sm font-medium"
                        :title="currentScene.title"
                    >
                        {{ currentScene.title }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            currentMarker
                                ? 'Placed. Click the plan to move it.'
                                : 'Click the plan where this room is.'
                        }}
                    </p>
                </div>
                <Button
                    v-if="currentMarker"
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    @click="
                        editor.removeFloorplanMarker(
                            floorplan.id,
                            currentScene.id,
                        )
                    "
                >
                    Remove
                </Button>
            </div>

            <div
                class="relative cursor-crosshair overflow-hidden rounded-xl border bg-white"
                role="button"
                tabindex="0"
                aria-label="Floor plan: click to place the current scene"
                @click="placeMarker"
            >
                <img
                    :src="floorplan.image_url"
                    :alt="floorplan.title"
                    class="block w-full select-none"
                    draggable="false"
                />
                <span
                    v-for="marker in floorplan.markers"
                    :key="marker.scene_id"
                    class="absolute flex size-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white shadow"
                    :class="
                        marker.scene_id === currentScene?.id
                            ? 'bg-amber-500'
                            : 'bg-blue-600'
                    "
                    :style="{
                        left: `${marker.x * 100}%`,
                        top: `${marker.y * 100}%`,
                    }"
                    :title="sceneTitles[marker.scene_id]"
                >
                    <MapPin class="size-3 text-white" />
                </span>
            </div>

            <section class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-2">
                <div class="flex items-center justify-between text-xs">
                    <h3 class="font-medium">Scenes</h3>
                    <span class="text-muted-foreground tabular-nums"
                        >{{ placedIds.size }} of
                        {{ tour.scenes.length }} placed</span
                    >
                </div>
                <ul class="grid grid-cols-[minmax(0,1fr)] gap-1">
                    <li
                        v-for="scene in tour.scenes"
                        :key="scene.id"
                        class="min-w-0"
                    >
                        <button
                            type="button"
                            class="flex w-full min-w-0 items-center gap-2 rounded-lg border px-2.5 py-1.5 text-left text-sm transition-colors"
                            :class="
                                scene.id === currentScene?.id
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:border-foreground/30'
                            "
                            :title="scene.title"
                            @click="editor.selectScene(scene.id)"
                        >
                            <span
                                class="grid size-5 shrink-0 place-items-center rounded-full"
                                :class="
                                    placedIds.has(scene.id)
                                        ? 'bg-emerald-500/15 text-emerald-600'
                                        : 'bg-muted text-muted-foreground'
                                "
                            >
                                <Check
                                    v-if="placedIds.has(scene.id)"
                                    class="size-3"
                                />
                                <Circle v-else class="size-2" />
                            </span>
                            <span class="min-w-0 flex-1 truncate">{{
                                scene.title
                            }}</span>
                        </button>
                    </li>
                </ul>
            </section>
        </PanelSection>
    </div>
</template>
