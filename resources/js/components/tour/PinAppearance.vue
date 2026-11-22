<script setup lang="ts">
import { Check, Circle, RectangleHorizontal, Square } from '@lucide/vue';
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import {
    GLYPH_LABELS,
    GLYPHS_BY_TYPE,
    glyphFor,
    glyphSvg,
} from '@/lib/pinIcons';
import { pinColor } from '@/lib/tourMarkers';
import type { HotspotSettings, HotspotType } from '@/types';

/**
 * How one pin looks: where it sits (round, floor or wall), its size, colour,
 * opacity, rotation and whether its label always shows. Changes show on the
 * panorama straight away.
 */
const props = defineProps<{ type: HotspotType }>();
const settings = defineModel<HotspotSettings>({ required: true });

const placements = [
    {
        value: 'sphere',
        label: 'Round',
        hint: 'A round pin floating in the view.',
        icon: Circle,
    },
    {
        value: 'floor',
        label: 'Floor',
        hint: 'A flat ring that lies on the floor, for doorways and paths.',
        icon: RectangleHorizontal,
    },
    {
        value: 'wall',
        label: 'Wall',
        hint: 'A rounded square, for things on a wall.',
        icon: Square,
    },
] as const;

const swatches = [
    '#3b82f6',
    '#10b981',
    '#f59e0b',
    '#ef4444',
    '#8b5cf6',
    '#ec4899',
    '#ffffff',
    '#111827',
];

// Only the icons that suit this kind of pin are offered.
const glyphChoices = computed(() => GLYPHS_BY_TYPE[props.type]);
const activeGlyph = computed(() => glyphFor(props.type, settings.value.icon));

const activeColor = computed(
    () => settings.value.color ?? pinColor(props.type),
);

const placementHint = computed(
    () =>
        placements.find((option) => option.value === settings.value.placement)
            ?.hint,
);

function update<K extends keyof HotspotSettings>(
    key: K,
    value: HotspotSettings[K],
): void {
    settings.value = { ...settings.value, [key]: value };
}
</script>

<template>
    <section
        class="grid min-w-0 gap-4 rounded-xl border bg-card p-3.5 shadow-xs"
    >
        <div>
            <p class="text-sm font-medium">Pin appearance</p>
            <p class="text-xs text-muted-foreground">
                Changes show on the image as you make them.
            </p>
        </div>

        <div class="grid gap-2">
            <Label>Icon</Label>
            <div
                class="grid grid-cols-5 gap-1.5"
                role="radiogroup"
                aria-label="Pin icon"
            >
                <button
                    v-for="glyph in glyphChoices"
                    :key="glyph"
                    type="button"
                    role="radio"
                    :aria-checked="activeGlyph === glyph"
                    class="grid aspect-square place-items-center rounded-lg border bg-background transition-colors [&>svg]:size-5"
                    :class="
                        activeGlyph === glyph
                            ? 'border-primary bg-primary/5 text-foreground ring-1 ring-primary'
                            : 'text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                    "
                    :title="GLYPH_LABELS[glyph]"
                    :aria-label="GLYPH_LABELS[glyph]"
                    @click="
                        update('icon', glyph === glyphChoices[0] ? null : glyph)
                    "
                    v-html="glyphSvg(glyph)"
                />
            </div>
        </div>

        <div class="grid gap-2">
            <Label>Placement</Label>
            <div
                class="grid grid-cols-3 gap-2"
                role="radiogroup"
                aria-label="Pin placement"
            >
                <button
                    v-for="option in placements"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="settings.placement === option.value"
                    class="flex flex-col items-center gap-1 rounded-lg border bg-background px-2 py-2.5 text-xs transition-colors"
                    :class="
                        settings.placement === option.value
                            ? 'border-primary bg-primary/5 font-medium text-foreground ring-1 ring-primary'
                            : 'text-muted-foreground hover:border-foreground/30 hover:text-foreground'
                    "
                    :title="option.hint"
                    @click="update('placement', option.value)"
                >
                    <component :is="option.icon" class="size-4" />
                    {{ option.label }}
                </button>
            </div>
            <p class="text-xs text-muted-foreground">{{ placementHint }}</p>
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between text-sm">
                <Label>Size</Label>
                <span
                    class="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground tabular-nums"
                    >{{ settings.size }}px</span
                >
            </div>
            <Slider
                :model-value="settings.size"
                :min="20"
                :max="80"
                :step="2"
                @update:model-value="update('size', $event)"
            />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between text-sm">
                <Label>Opacity</Label>
                <span
                    class="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground tabular-nums"
                    >{{ Math.round(settings.opacity * 100) }}%</span
                >
            </div>
            <Slider
                :model-value="Math.round(settings.opacity * 100)"
                :min="30"
                :max="100"
                :step="5"
                @update:model-value="update('opacity', $event / 100)"
            />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between text-sm">
                <Label>Rotation</Label>
                <span
                    class="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground tabular-nums"
                    >{{ settings.rotation }}°</span
                >
            </div>
            <Slider
                :model-value="settings.rotation"
                :min="0"
                :max="355"
                :step="5"
                @update:model-value="update('rotation', $event)"
            />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between gap-2 text-sm">
                <Label>Colour</Label>
                <button
                    v-if="settings.color"
                    type="button"
                    class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    @click="update('color', null)"
                >
                    Reset to default
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="swatch in swatches"
                    :key="swatch"
                    type="button"
                    class="relative flex size-6 items-center justify-center rounded-full border border-black/15 transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:ring-ring"
                    :style="{ backgroundColor: swatch }"
                    :aria-label="`Use ${swatch}`"
                    @click="update('color', swatch)"
                >
                    <Check
                        v-if="activeColor.toLowerCase() === swatch"
                        class="size-3.5"
                        :class="
                            swatch === '#ffffff' ? 'text-black' : 'text-white'
                        "
                    />
                </button>
                <label
                    class="relative size-6 cursor-pointer overflow-hidden rounded-full border border-dashed border-foreground/40"
                    title="Pick any colour"
                    :style="{
                        background:
                            'conic-gradient(#ef4444, #f59e0b, #10b981, #3b82f6, #8b5cf6, #ef4444)',
                    }"
                >
                    <input
                        type="color"
                        :value="activeColor"
                        class="absolute inset-0 size-full cursor-pointer opacity-0"
                        aria-label="Pick any colour"
                        @input="
                            update(
                                'color',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
            </div>
        </div>

        <label
            class="flex items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2 text-sm"
        >
            <span>Show the label only on hover</span>
            <Switch
                :model-value="settings.label_mode === 'hover'"
                @update:model-value="
                    update('label_mode', $event === true ? 'hover' : 'always')
                "
            />
        </label>
    </section>
</template>
