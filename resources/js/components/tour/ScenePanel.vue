<script setup lang="ts">
import {
    AlertTriangle,
    Crosshair,
    ExternalLink,
    Eye,
    Info,
    Navigation,
    Play,
    Plus,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import NativeSelect from '@/components/NativeSelect.vue';
import type { HotspotType, LibraryAsset, TourScene } from '@/types';

const props = defineProps<{
    scene: TourScene;
    sceneTitles: Record<string, string>;
    audioLibrary: LibraryAsset[];
    canEdit: boolean;
    placing: boolean;
}>();

const emit = defineEmits<{
    update: [data: Record<string, unknown>];
    captureView: [];
    previewView: [];
    addHotspot: [];
    selectHotspot: [hotspotId: string];
}>();

const title = ref(props.scene.title);
const description = ref(props.scene.description ?? '');

// Re-sync only when the stored values change, so unrelated saves don't
// overwrite text the user is still typing.
watch(
    () => [props.scene.id, props.scene.title] as const,
    () => {
        title.value = props.scene.title;
    },
);
watch(
    () => [props.scene.id, props.scene.description] as const,
    () => {
        description.value = props.scene.description ?? '';
    },
);

function saveTitle(): void {
    const value = title.value.trim();

    if (value === '') {
        title.value = props.scene.title;

        return;
    }

    if (value !== props.scene.title) {
        emit('update', { title: value });
    }
}

function saveDescription(): void {
    const value = description.value.trim();

    if (value !== (props.scene.description ?? '')) {
        emit('update', { description: value === '' ? null : value });
    }
}

const icons: Record<HotspotType, typeof Info> = {
    navigation: Navigation,
    info: Info,
    link: ExternalLink,
    media: Play,
};

function hotspotLabel(hotspot: TourScene['hotspots'][number]): string {
    if (hotspot.title) {
        return hotspot.title;
    }

    if (hotspot.type === 'navigation' && hotspot.target_scene_id) {
        return `→ ${props.sceneTitles[hotspot.target_scene_id] ?? 'Scene'}`;
    }

    return 'Untitled';
}
</script>

<template>
    <div class="space-y-6 p-3">
        <fieldset :disabled="!canEdit" class="space-y-4">
            <div class="grid gap-2">
                <Label for="scene-title">Scene name</Label>
                <Input
                    id="scene-title"
                    v-model="title"
                    maxlength="120"
                    @blur="saveTitle"
                    @keydown.enter.prevent="
                        ($event.target as HTMLInputElement).blur()
                    "
                />
            </div>
            <div class="grid gap-2">
                <Label for="scene-description">Description</Label>
                <Textarea
                    id="scene-description"
                    v-model="description"
                    rows="3"
                    maxlength="5000"
                    placeholder="Shown to visitors in the scene list."
                    @blur="saveDescription"
                />
            </div>
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Visible to visitors</span
                ><Switch
                    :model-value="scene.is_visible"
                    @update:model-value="
                        emit('update', { is_visible: $event === true })
                    "
            /></label>
        </fieldset>

        <div
            v-if="!scene.media.is_equirectangular"
            class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
            This is a regular photo, not a 360° panorama. Visitors see it as a
            flat view and can't look past its edges. Use a 2:1 panorama, or
            convert the photo to 360° with AI, for a full sphere.
        </div>

        <section class="space-y-2">
            <h3 class="text-sm font-medium">Starting view</h3>
            <p class="text-xs text-muted-foreground">
                Visitors arriving in this scene first look this way. Currently
                {{ scene.initial_view.yaw.toFixed(0) }}° across,
                {{ scene.initial_view.pitch.toFixed(0) }}° up, zoom
                {{ scene.initial_view.zoom }}.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="canEdit"
                    size="sm"
                    variant="outline"
                    @click="emit('captureView')"
                >
                    <Crosshair /> Use current view
                </Button>
                <Button size="sm" variant="ghost" @click="emit('previewView')">
                    <Eye /> Show
                </Button>
            </div>
        </section>

        <fieldset :disabled="!canEdit" class="space-y-2">
            <h3 class="text-sm font-medium">Scene audio</h3>
            <NativeSelect
                :model-value="scene.audio.asset_id ?? ''"
                :options="
                    audioLibrary.map((track) => ({
                        value: track.id,
                        label: track.name ?? 'Audio',
                    }))
                "
                placeholder="None (use tour music)"
                aria-label="Scene audio"
                @update:model-value="
                    emit('update', {
                        audio_settings: { asset_id: $event || null },
                    })
                "
            />
            <template v-if="scene.audio.asset_id">
                <div class="grid gap-2 text-sm">
                    <span>Volume {{ scene.audio.volume }}%</span
                    ><Slider
                        :model-value="scene.audio.volume"
                        :min="0"
                        :max="100"
                        :step="5"
                        @commit="
                            emit('update', {
                                audio_settings: { volume: $event },
                            })
                        "
                    />
                </div>
                <label
                    class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                    ><span>Pause tour music in this scene</span
                    ><Switch
                        :model-value="scene.audio.pause_background"
                        @update:model-value="
                            emit('update', {
                                audio_settings: {
                                    pause_background: $event === true,
                                },
                            })
                        "
                /></label>
                <label
                    class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                    ><span>Loop</span
                    ><Switch
                        :model-value="scene.audio.loop"
                        @update:model-value="
                            emit('update', {
                                audio_settings: { loop: $event === true },
                            })
                        "
                /></label>
            </template>
        </fieldset>

        <section class="space-y-2">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium">
                    Hotspots
                    <span class="font-normal text-muted-foreground">{{
                        scene.hotspots.length
                    }}</span>
                </h3>
                <Button
                    v-if="canEdit"
                    size="sm"
                    variant="outline"
                    class="h-7"
                    :class="{ 'border-primary': placing }"
                    @click="emit('addHotspot')"
                >
                    <Plus /> {{ placing ? 'Placing…' : 'Add' }}
                </Button>
            </div>
            <ul v-if="scene.hotspots.length" class="divide-y rounded-md border">
                <li v-for="hotspot in scene.hotspots" :key="hotspot.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-2.5 py-2 text-left text-sm hover:bg-accent"
                        @click="emit('selectHotspot', hotspot.id)"
                    >
                        <component
                            :is="icons[hotspot.type]"
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="truncate">{{
                            hotspotLabel(hotspot)
                        }}</span>
                    </button>
                </li>
            </ul>
            <p v-else class="text-xs text-muted-foreground">
                Add hotspots to link scenes together or point out features.
            </p>
        </section>
    </div>
</template>
