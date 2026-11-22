<script setup lang="ts">
import {
    AlertTriangle,
    AudioLines,
    Check,
    Loader2,
    Orbit,
    Play,
} from '@lucide/vue';
import { formatBytes } from '@/lib/format';
import type { MediaAsset } from '@/types';

withDefaults(
    defineProps<{
        asset: MediaAsset;
        /** Show a tick box so several files can be picked at once. */
        selectable?: boolean;
        selected?: boolean;
        /** Some card is ticked: tapping a card ticks it instead of opening it. */
        selecting?: boolean;
    }>(),
    { selectable: false, selected: false, selecting: false },
);

const emit = defineEmits<{
    select: [asset: MediaAsset];
    toggle: [asset: MediaAsset];
}>();
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-lg border bg-card text-left transition-colors hover:border-foreground/20"
        :class="
            selected
                ? 'border-primary ring-2 ring-primary ring-offset-1 ring-offset-background'
                : ''
        "
    >
        <button
            type="button"
            class="block w-full text-left focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            @click="
                selectable && selecting
                    ? emit('toggle', asset)
                    : emit('select', asset)
            "
        >
            <div class="relative aspect-[4/3] overflow-hidden bg-muted">
                <img
                    v-if="asset.thumbnail_url"
                    :src="asset.thumbnail_url"
                    :alt="asset.original_filename ?? asset.asset_type_label"
                    class="size-full object-cover"
                    loading="lazy"
                />
                <div v-else class="flex size-full items-center justify-center">
                    <AudioLines class="size-8 text-muted-foreground/60" />
                </div>

                <span
                    v-if="asset.asset_type === 'panorama'"
                    class="absolute top-2 inline-flex items-center gap-1 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-medium text-white"
                    :class="selectable ? 'left-10' : 'left-2'"
                >
                    <Orbit class="size-3" /> 360°
                </span>
                <span
                    v-if="asset.asset_type === 'video'"
                    class="absolute top-2 inline-flex items-center gap-1 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-medium text-white"
                    :class="selectable ? 'left-10' : 'left-2'"
                >
                    <Play class="size-3" /> Video
                </span>
                <span
                    v-if="asset.panorama && !asset.panorama.is_equirectangular"
                    class="absolute top-2 right-2 inline-flex items-center rounded-full bg-amber-500 p-1 text-white"
                    title="Not a 2:1 equirectangular image"
                >
                    <AlertTriangle class="size-3" />
                </span>

                <div
                    v-if="asset.enhancement_id"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-black/55 text-xs font-medium text-white backdrop-blur-[1px]"
                >
                    <Loader2 class="size-5 animate-spin" />
                    Enhancing…
                </div>
            </div>
            <div class="space-y-0.5 p-2.5">
                <p class="truncate text-sm font-medium">
                    {{ asset.original_filename ?? 'Untitled' }}
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{ asset.asset_type_label }} ·
                    <template v-if="asset.width && asset.height"
                        >{{ asset.width }}×{{ asset.height }} ·
                    </template>
                    {{ formatBytes(asset.file_size) }}
                </p>
            </div>
        </button>

        <button
            v-if="selectable && asset.can.delete"
            type="button"
            role="checkbox"
            :aria-checked="selected"
            :aria-label="`Select ${asset.original_filename ?? 'file'}`"
            class="absolute top-2 left-2 grid size-6 place-items-center rounded-md border-2 shadow-sm transition-opacity focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            :class="[
                selected
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-white bg-black/30 text-transparent hover:bg-black/50',
                selected || selecting
                    ? 'opacity-100'
                    : 'opacity-0 group-hover:opacity-100',
            ]"
            @click.stop="emit('toggle', asset)"
        >
            <Check class="size-4" />
        </button>
    </div>
</template>
