<script setup lang="ts">
import { ArrowLeft, Check, Move, Trash2 } from '@lucide/vue';
import { Switch } from '@/components/ui/switch';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { HotspotInput } from '@/composables/useTourEditor';
import { HttpError } from '@/lib/http';
import PinAppearance from '@/components/tour/PinAppearance.vue';
import type { PinPreview } from '@/lib/tourMarkers';
import { DEFAULT_PIN } from '@/lib/tourMarkers';
import type {
    HotspotSettings,
    HotspotType,
    LibraryAsset,
    TourHotspot,
    TourScene,
} from '@/types';

const props = defineProps<{
    /** Existing hotspot being edited, or null for a new (draft) hotspot. */
    hotspot: TourHotspot | null;
    scenes: TourScene[];
    currentSceneId: string;
    canEdit: boolean;
    mediaLibrary: LibraryAsset[];
    moving: boolean;
    busy: boolean;
}>();

const emit = defineEmits<{
    save: [input: HotspotInput];
    remove: [];
    move: [];
    close: [];
    preview: [pin: PinPreview | null];
}>();

const otherScenes = computed(() =>
    props.scenes.filter((scene) => scene.id !== props.currentSceneId),
);

const typeOptions = computed(() => [
    ...(otherScenes.value.length
        ? [{ value: 'navigation', label: 'Go to another scene' }]
        : []),
    { value: 'info', label: 'Information panel' },
    { value: 'media', label: 'Image or video' },
    { value: 'link', label: 'External link' },
]);

type HotspotForm = {
    type: HotspotType;
    title: string;
    external_url: string;
    target_scene_id: string;
    media_asset_id: string;
    settings: HotspotSettings;
};

const fields = reactive<HotspotForm>({
    type: 'info',
    title: '',
    external_url: '',
    target_scene_id: '',
    media_asset_id: '',
    settings: { ...DEFAULT_PIN },
});
const descriptionText = ref('');
const pinType = computed(() => fields.type);
const pinSettings = computed({
    get: () => fields.settings,
    set: (value: HotspotSettings) => {
        fields.settings = value;
    },
});
const errors = ref<Record<string, string>>({});

const isNavigation = computed(() => fields.type === 'navigation');
const isInfo = computed(() => fields.type === 'info');
const isLink = computed(() => fields.type === 'link');
const isMedia = computed(() => fields.type === 'media');

function reset(): void {
    const hotspot = props.hotspot;
    errors.value = {};
    descriptionText.value = hotspot?.description ?? '';

    Object.assign(fields, {
        type:
            hotspot?.type ??
            ((otherScenes.value.length ? 'navigation' : 'info') as HotspotType),
        title: hotspot?.title ?? '',
        external_url: hotspot?.external_url ?? '',
        target_scene_id:
            hotspot?.target_scene_id ?? otherScenes.value[0]?.id ?? '',
        media_asset_id: hotspot?.media?.id ?? '',
        settings: { ...DEFAULT_PIN, ...hotspot?.settings },
    });
}

watch(() => props.hotspot?.id, reset, { immediate: true });

watch(
    [fields, descriptionText],
    () => {
        emit('preview', {
            id: props.hotspot?.id ?? 'draft',
            type: fields.type,
            label:
                fields.title.trim() ||
                (fields.type === 'navigation'
                    ? (props.scenes.find(
                          (scene) => scene.id === fields.target_scene_id,
                      )?.title ?? null)
                    : null),
            settings: { ...fields.settings },
        });
    },
    { deep: true, immediate: true },
);

onBeforeUnmount(() => emit('preview', null));

function submit(): void {
    errors.value = {};

    const blankToNull = (value: string) =>
        value.trim() !== '' ? value.trim() : null;

    emit('save', {
        type: fields.type,
        title: blankToNull(fields.title),
        description: blankToNull(descriptionText.value),
        external_url:
            fields.type === 'link' ? blankToNull(fields.external_url) : null,
        target_scene_id:
            fields.type === 'navigation'
                ? blankToNull(fields.target_scene_id)
                : null,
        media_asset_id:
            fields.type === 'media' ? blankToNull(fields.media_asset_id) : null,
        settings: { ...fields.settings },
    });
}

/**
 * Called by the parent when the server rejects the hotspot.
 */
function showErrors(error: unknown): void {
    if (error instanceof HttpError) {
        errors.value = Object.fromEntries(
            Object.entries(error.errors).map(([key, messages]) => [
                key,
                messages[0],
            ]),
        );
    }
}

defineExpose({ showErrors });
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex items-center gap-2 border-b px-3 py-2.5">
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                aria-label="Back to scene settings"
                @click="emit('close')"
            >
                <ArrowLeft />
            </Button>
            <h2 class="text-sm font-medium">
                {{ hotspot ? 'Edit hotspot' : 'New hotspot' }}
            </h2>
        </div>

        <form
            class="min-h-0 min-w-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto p-3"
            @submit.prevent="submit"
        >
            <fieldset :disabled="!canEdit" class="min-w-0 space-y-4">
                <div class="grid gap-2">
                    <Label for="hotspot-type">Type</Label>
                    <NativeSelect
                        id="hotspot-type"
                        v-model="fields.type"
                        :options="typeOptions"
                    />
                    <InputError :message="errors.type" />
                </div>

                <div v-if="isNavigation" class="grid gap-2">
                    <Label>Leads to</Label>
                    <div
                        class="grid max-h-64 grid-cols-2 gap-2 overflow-y-auto pr-1"
                        role="radiogroup"
                        aria-label="Destination scene"
                    >
                        <button
                            v-for="scene in otherScenes"
                            :key="scene.id"
                            type="button"
                            role="radio"
                            :aria-checked="fields.target_scene_id === scene.id"
                            class="group relative min-w-0 overflow-hidden rounded-lg border bg-muted text-left transition"
                            :class="
                                fields.target_scene_id === scene.id
                                    ? 'ring-2 ring-primary ring-offset-1 ring-offset-background'
                                    : 'hover:border-foreground/40'
                            "
                            @click="fields.target_scene_id = scene.id"
                        >
                            <img
                                v-if="scene.media.thumbnail_url"
                                :src="scene.media.thumbnail_url"
                                :alt="scene.title"
                                class="aspect-[4/3] w-full object-cover"
                                loading="lazy"
                            />
                            <div v-else class="aspect-[4/3] w-full" />
                            <span
                                class="block truncate bg-background/90 px-2 py-1 text-xs"
                                >{{ scene.title }}</span
                            >
                            <span
                                v-if="fields.target_scene_id === scene.id"
                                class="absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground"
                            >
                                <Check class="size-3.5" />
                            </span>
                        </button>
                    </div>
                    <InputError :message="errors.target_scene_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="hotspot-title">
                        {{ isNavigation ? 'Label (optional)' : 'Title' }}
                    </Label>
                    <Input
                        id="hotspot-title"
                        v-model="fields.title"
                        maxlength="120"
                        :placeholder="
                            isNavigation
                                ? 'Defaults to the scene name'
                                : isInfo
                                  ? 'e.g. Marble flooring'
                                  : isMedia
                                    ? 'e.g. Kitchen walkthrough'
                                    : 'e.g. Book a viewing'
                        "
                    />
                    <InputError :message="errors.title" />
                </div>

                <div v-if="isMedia" class="grid gap-2">
                    <Label>Image or video</Label>
                    <div
                        v-if="mediaLibrary.length"
                        class="grid max-h-56 grid-cols-3 gap-2 overflow-y-auto pr-1"
                        role="radiogroup"
                        aria-label="Image or video"
                    >
                        <button
                            v-for="asset in mediaLibrary"
                            :key="asset.id"
                            type="button"
                            role="radio"
                            :aria-checked="fields.media_asset_id === asset.id"
                            class="relative aspect-[4/3] overflow-hidden rounded-md border bg-muted"
                            :class="
                                fields.media_asset_id === asset.id
                                    ? 'ring-2 ring-primary ring-offset-1 ring-offset-background'
                                    : 'hover:opacity-90'
                            "
                            :title="asset.name ?? undefined"
                            @click="fields.media_asset_id = asset.id"
                        >
                            <img
                                v-if="asset.thumbnail_url"
                                :src="asset.thumbnail_url"
                                :alt="asset.name ?? ''"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                            <span
                                v-if="asset.type === 'video'"
                                class="absolute right-1 bottom-1 rounded bg-black/70 px-1 text-[10px] text-white"
                                >Video</span
                            >
                        </button>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        Upload a photo or video in the media library first.
                    </p>
                    <InputError :message="errors.media_asset_id" />
                </div>

                <div v-if="isLink" class="grid gap-2">
                    <Label for="hotspot-url">Link address</Label>
                    <Input
                        id="hotspot-url"
                        v-model="fields.external_url"
                        type="url"
                        :placeholder="'https://'"
                        maxlength="2048"
                    />
                    <InputError :message="errors.external_url" />
                    <label
                        class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                        ><span>Open in a new tab</span
                        ><Switch
                            :model-value="fields.settings.open_in_new_tab"
                            @update:model-value="
                                fields.settings.open_in_new_tab =
                                    $event === true
                            "
                    /></label>
                </div>

                <div v-if="!isNavigation" class="grid gap-2">
                    <Label for="hotspot-description">
                        {{
                            isInfo
                                ? 'Description'
                                : isMedia
                                  ? 'Caption (optional)'
                                  : 'Description (optional)'
                        }}
                    </Label>
                    <Textarea
                        id="hotspot-description"
                        v-model="descriptionText"
                        rows="5"
                        maxlength="5000"
                        placeholder="Premium marble flooring with floor-to-ceiling windows."
                    />
                    <InputError :message="errors.description" />
                </div>

                <PinAppearance v-model="pinSettings" :type="pinType" />

                <InputError :message="errors.yaw ?? errors.pitch" />

                <div class="flex flex-wrap gap-2 pt-2">
                    <Button type="submit" :disabled="busy">
                        {{ hotspot ? 'Save hotspot' : 'Add hotspot' }}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="emit('close')"
                    >
                        Cancel
                    </Button>
                </div>
            </fieldset>

            <div v-if="hotspot && canEdit" class="space-y-2 border-t pt-4">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="w-full"
                    :class="{ 'border-primary': moving }"
                    @click="emit('move')"
                >
                    <Move />
                    {{ moving ? 'Click the new position…' : 'Reposition' }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="w-full text-destructive hover:text-destructive"
                    @click="emit('remove')"
                >
                    <Trash2 /> Delete hotspot
                </Button>
            </div>
        </form>
    </div>
</template>
