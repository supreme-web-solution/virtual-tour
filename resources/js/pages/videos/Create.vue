<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { Clapperboard as HeaderIcon } from '@lucide/vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    AudioLines,
    Building2,
    Check,
    ClipboardCheck,
    Clock3,
    Columns2,
    Footprints,
    Gem,
    Images,
    Info,
    Music,
    Orbit,
    Palette,
    Plane,
    Play,
    RectangleHorizontal,
    Smartphone,
    Sparkles,
    Store,
    VolumeX,
    WandSparkles,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { accents } from '@/lib/accent';
import type { Accent } from '@/lib/accent';
import videos from '@/routes/videos';
import type { SoundtrackModeValue, VideoLimits, VideoSource } from '@/types';

const props = defineProps<{
    sources: VideoSource[];
    audio: { id: string; name: string | null; url: string }[];
    properties: { id: string; name: string }[];
    tours: { id: string; title: string }[];
    styles: { value: string; label: string; description: string }[];
    aspectRatios: string[];
    durations: number[];
    moods: string[];
    ambiences: string[];
    maxSources: number;
    limits: VideoLimits;
    prices: { storyboard: number; clip_per_second: number; music: number };
    available: number;
    defaultPropertyId: string | null;
    fromTour: { id: string; title: string; source_ids: string[] } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Video Studio', href: videos.index() },
            { title: 'New video', href: videos.create() },
        ],
    },
});

const steps = [
    'Sources',
    'Style',
    'Format',
    'Length',
    'Sound',
    'Review',
] as const;
// Coming from a tour, its scenes are already chosen: start at the style step.
const step = ref(props.fromTour ? 1 : 0);
const propertyFilter = ref(props.defaultPropertyId ?? '');

const form = useForm({
    title: props.fromTour ? `${props.fromTour.title} video` : '',
    property_id: props.defaultPropertyId ?? '',
    virtual_tour_id: props.fromTour?.id ?? '',
    source_ids: [...(props.fromTour?.source_ids ?? [])] as string[],
    style: 'property_walkthrough',
    aspect_ratio: '16:9',
    duration: 15,
    soundtrack_mode: 'ai_music' as SoundtrackModeValue,
    soundtrack_asset_id: '',
    voiceover_asset_id: '',
    settings: {
        music_mood: 'cinematic',
        ambience: 'soft_room',
        branded_outro: true,
        brief: '',
    },
});

const visibleSources = computed(() =>
    propertyFilter.value
        ? props.sources.filter(
              (source) => source.property_id === propertyFilter.value,
          )
        : props.sources,
);
const sourceById = computed(() =>
    Object.fromEntries(props.sources.map((source) => [source.id, source])),
);
const style = computed(() =>
    props.styles.find((item) => item.value === form.style),
);
const estimatedShots = computed(() =>
    Math.max(
        1,
        Math.min(
            form.source_ids.length,
            Math.floor(form.duration / props.limits.min_seconds),
        ),
    ),
);
const clipCredits = computed(() =>
    Math.ceil(form.duration * props.prices.clip_per_second),
);
const musicCredits = computed(() =>
    form.soundtrack_mode === 'ai_music' ? props.prices.music : 0,
);

const soundModes: {
    value: SoundtrackModeValue;
    label: string;
    description: string;
    icon: Component;
    tone: Accent;
}[] = [
    {
        value: 'ai_music',
        label: 'AI music',
        description: 'An original instrumental track composed for this video.',
        icon: Music,
        tone: 'violet',
    },
    {
        value: 'ambient',
        label: 'Ambient sound',
        description:
            'Natural room or outdoor ambience generated with each clip.',
        icon: AudioLines,
        tone: 'sky',
    },
    {
        value: 'own_audio',
        label: 'My own audio',
        description: 'Music or narration you uploaded (MP3, WAV or M4A).',
        icon: Play,
        tone: 'emerald',
    },
    {
        value: 'none',
        label: 'No sound',
        description: 'A silent video.',
        icon: VolumeX,
        tone: 'amber',
    },
];

const stepMeta: {
    title: string;
    hint: string;
    icon: Component;
    tone: Accent;
}[] = [
    {
        title: 'Choose photos or 360° scenes',
        hint: 'Pick the images, in the order you want them to appear.',
        icon: Images,
        tone: 'sky',
    },
    {
        title: 'Choose a style',
        hint: 'This shapes the camera movement and pacing.',
        icon: Palette,
        tone: 'violet',
    },
    {
        title: 'Choose a format',
        hint: 'Where will the video be watched?',
        icon: RectangleHorizontal,
        tone: 'emerald',
    },
    {
        title: 'Choose a length',
        hint: 'Longer videos use more shots and more credits.',
        icon: Clock3,
        tone: 'amber',
    },
    {
        title: 'Add sound',
        hint: 'Music, ambience, your own audio, or nothing.',
        icon: Music,
        tone: 'rose',
    },
    {
        title: 'Review and plan',
        hint: 'The AI plans the shots first. Nothing is generated until you approve the storyboard.',
        icon: ClipboardCheck,
        tone: 'indigo',
    },
];

const styleLooks: Record<string, { icon: Component; tone: Accent }> = {
    property_walkthrough: { icon: Footprints, tone: 'sky' },
    drone_reveal: { icon: Plane, tone: 'indigo' },
    social_teaser: { icon: Smartphone, tone: 'rose' },
    before_after_staging: { icon: Columns2, tone: 'emerald' },
    luxury_showcase: { icon: Gem, tone: 'amber' },
    youtube_advert: { icon: Play, tone: 'rose' },
    development_launch: { icon: Building2, tone: 'violet' },
    commercial_promo: { icon: Store, tone: 'sky' },
    custom: { icon: WandSparkles, tone: 'violet' },
};

function styleLook(value: string): { icon: Component; tone: Accent } {
    return styleLooks[value] ?? { icon: Palette, tone: 'sky' };
}

const summaryRows = computed(() => [
    {
        label: 'Images',
        value: `${form.source_ids.length} chosen`,
        icon: Images,
        tone: 'sky' as Accent,
    },
    {
        label: 'Style',
        value: style.value?.label ?? '',
        icon: styleLook(form.style).icon,
        tone: styleLook(form.style).tone,
    },
    {
        label: 'Format',
        value: `${form.aspect_ratio} · ${props.limits.resolution}`,
        icon: RectangleHorizontal,
        tone: 'emerald' as Accent,
    },
    {
        label: 'Length',
        value: `${form.duration} seconds`,
        icon: Clock3,
        tone: 'amber' as Accent,
    },
    {
        label: 'Sound',
        value:
            soundModes.find((mode) => mode.value === form.soundtrack_mode)
                ?.label ?? '',
        icon: Music,
        tone: 'rose' as Accent,
    },
]);

const canContinue = computed(() => {
    switch (step.value) {
        case 0:
            return form.source_ids.length > 0;
        case 1:
            return (
                form.style !== 'custom' || form.settings.brief.trim().length > 0
            );
        case 4:
            return (
                form.soundtrack_mode !== 'own_audio' ||
                form.soundtrack_asset_id !== ''
            );
        default:
            return true;
    }
});

function toggleSource(id: string): void {
    if (form.source_ids.includes(id)) {
        form.source_ids = form.source_ids.filter((value) => value !== id);
    } else if (form.source_ids.length < props.maxSources) {
        form.source_ids = [...form.source_ids, id];
    }
}

function moveSource(index: number, offset: number): void {
    const ids = [...form.source_ids];
    const target = index + offset;

    if (target < 0 || target >= ids.length) {
        return;
    }

    [ids[index], ids[target]] = [ids[target], ids[index]];
    form.source_ids = ids;
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        title: data.title.trim() || `${style.value?.label ?? 'Property'} video`,
        property_id: data.property_id || null,
        virtual_tour_id: data.virtual_tour_id || null,
        soundtrack_asset_id:
            data.soundtrack_mode === 'own_audio'
                ? data.soundtrack_asset_id
                : null,
        voiceover_asset_id: data.voiceover_asset_id || null,
        settings: {
            ...data.settings,
            brief: data.settings.brief.trim() || null,
        },
    })).submit(videos.store(), {
        onError: (errors) => {
            if (
                errors.source_ids ||
                Object.keys(errors).some((key) => key.startsWith('source_ids'))
            ) {
                step.value = 0;
            }
        },
    });
}

const label = (value: string): string => value.replace('_', ' ');
</script>

<template>
    <Head title="New video" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="New video"
            :icon="HeaderIcon"
            tone="rose"
            description="AI plans the shots; you review the storyboard before any clip is generated."
        />

        <!-- Progress -->
        <ol class="flex items-center gap-1 overflow-x-auto pb-1 text-sm">
            <template v-for="(name, index) in steps" :key="name">
                <li class="shrink-0">
                    <button
                        type="button"
                        class="group flex items-center gap-2 rounded-full py-1 pr-3 pl-1 transition-colors"
                        :class="
                            index === step
                                ? 'bg-primary/10 text-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :disabled="index > step"
                        :aria-current="index === step ? 'step' : undefined"
                        @click="step = index"
                    >
                        <span
                            class="grid size-7 place-items-center rounded-full text-xs font-semibold transition-colors"
                            :class="
                                index < step
                                    ? 'bg-emerald-500 text-white'
                                    : index === step
                                      ? 'bg-primary text-primary-foreground'
                                      : 'bg-muted text-muted-foreground'
                            "
                        >
                            <Check v-if="index < step" class="size-3.5" />
                            <span v-else>{{ index + 1 }}</span>
                        </span>
                        <span
                            class="font-medium"
                            :class="{
                                'hidden sm:inline': index !== step,
                            }"
                            >{{ name }}</span
                        >
                    </button>
                </li>
                <li
                    v-if="index < steps.length - 1"
                    class="h-px min-w-3 flex-1 bg-border"
                    :class="{ 'bg-emerald-500/50': index < step }"
                    aria-hidden="true"
                />
            </template>
        </ol>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
            <section
                class="min-w-0 overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <header class="flex items-center gap-3 border-b px-5 py-4">
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-xl"
                        :class="accents[stepMeta[step].tone]"
                    >
                        <component :is="stepMeta[step].icon" class="size-4.5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-semibold">
                            {{ stepMeta[step].title }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ stepMeta[step].hint }}
                        </p>
                    </div>
                </header>

                <div class="space-y-5 p-5">
                    <!-- 1. Sources -->
                    <template v-if="step === 0">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <p class="text-sm text-muted-foreground">
                                <span class="font-semibold text-foreground">{{
                                    form.source_ids.length
                                }}</span>
                                of {{ maxSources }} chosen. 360° scenes become a
                                flat forward view.
                            </p>
                            <NativeSelect
                                v-model="propertyFilter"
                                searchable
                                :options="
                                    properties.map((p) => ({
                                        value: p.id,
                                        label: p.name,
                                    }))
                                "
                                placeholder="All properties"
                                aria-label="Filter by property"
                                class="w-56"
                            />
                        </div>

                        <div
                            v-if="form.source_ids.length"
                            class="rounded-xl border bg-muted/30 p-3"
                        >
                            <p
                                class="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                Order of shots
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <div
                                    v-for="(id, index) in form.source_ids"
                                    :key="id"
                                    class="flex items-center gap-1 rounded-lg border bg-background p-1 text-xs"
                                >
                                    <span
                                        class="grid size-5 place-items-center rounded bg-primary text-primary-foreground"
                                        >{{ index + 1 }}</span
                                    >
                                    <img
                                        v-if="sourceById[id]?.thumbnail_url"
                                        :src="
                                            sourceById[id].thumbnail_url ??
                                            undefined
                                        "
                                        alt=""
                                        class="h-8 w-12 rounded object-cover"
                                    />
                                    <button
                                        type="button"
                                        class="px-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                        :aria-label="`Move ${index + 1} earlier`"
                                        :disabled="index === 0"
                                        @click="moveSource(index, -1)"
                                    >
                                        ‹
                                    </button>
                                    <button
                                        type="button"
                                        class="px-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                        :aria-label="`Move ${index + 1} later`"
                                        :disabled="
                                            index === form.source_ids.length - 1
                                        "
                                        @click="moveSource(index, 1)"
                                    >
                                        ›
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="visibleSources.length"
                            class="grid max-h-[22rem] grid-cols-3 gap-2 overflow-y-auto pr-1 sm:grid-cols-4 lg:grid-cols-5"
                        >
                            <button
                                v-for="source in visibleSources"
                                :key="source.id"
                                type="button"
                                class="relative aspect-[4/3] overflow-hidden rounded-lg border bg-muted transition-transform hover:scale-[1.02]"
                                :class="
                                    form.source_ids.includes(source.id)
                                        ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                                        : ''
                                "
                                :aria-pressed="
                                    form.source_ids.includes(source.id)
                                "
                                :title="source.filename ?? undefined"
                                @click="toggleSource(source.id)"
                            >
                                <img
                                    v-if="source.thumbnail_url"
                                    :src="source.thumbnail_url"
                                    :alt="source.filename ?? 'Image'"
                                    class="size-full object-cover"
                                    loading="lazy"
                                />
                                <span
                                    v-if="source.is_panorama"
                                    class="absolute bottom-1 left-1 flex items-center gap-0.5 rounded bg-black/60 px-1 text-[10px] text-white"
                                    ><Orbit class="size-2.5" /> 360°</span
                                >
                                <span
                                    v-if="form.source_ids.includes(source.id)"
                                    class="absolute top-1 right-1 grid size-5 place-items-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground"
                                    >{{
                                        form.source_ids.indexOf(source.id) + 1
                                    }}</span
                                >
                            </button>
                        </div>
                        <p
                            v-else
                            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
                        >
                            No photos or panoramas yet. Upload some in the media
                            library first.
                        </p>
                        <InputError :message="form.errors.source_ids" />

                        <div class="grid gap-4 border-t pt-5 sm:grid-cols-3">
                            <div class="grid gap-2">
                                <Label for="video-title"
                                    >Title (optional)</Label
                                >
                                <Input
                                    id="video-title"
                                    v-model="form.title"
                                    maxlength="160"
                                    placeholder="e.g. Lekki Villa teaser"
                                />
                                <InputError :message="form.errors.title" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="video-property">Property</Label>
                                <NativeSelect
                                    id="video-property"
                                    v-model="form.property_id"
                                    searchable
                                    :options="
                                        properties.map((p) => ({
                                            value: p.id,
                                            label: p.name,
                                        }))
                                    "
                                    placeholder="Not linked"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="video-tour"
                                    >Tour (for logo outro)</Label
                                >
                                <NativeSelect
                                    id="video-tour"
                                    v-model="form.virtual_tour_id"
                                    searchable
                                    :options="
                                        tours.map((t) => ({
                                            value: t.id,
                                            label: t.title,
                                        }))
                                    "
                                    placeholder="Not linked"
                                />
                            </div>
                        </div>
                    </template>

                    <!-- 2. Style -->
                    <template v-else-if="step === 1">
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <button
                                v-for="item in styles"
                                :key="item.value"
                                type="button"
                                class="relative flex flex-col gap-2 rounded-xl border p-4 text-left transition-all hover:-translate-y-0.5 hover:shadow-sm"
                                :class="
                                    form.style === item.value
                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                        : 'hover:border-foreground/30'
                                "
                                :aria-pressed="form.style === item.value"
                                @click="form.style = item.value"
                            >
                                <span
                                    class="grid size-9 place-items-center rounded-lg"
                                    :class="accents[styleLook(item.value).tone]"
                                >
                                    <component
                                        :is="styleLook(item.value).icon"
                                        class="size-4.5"
                                    />
                                </span>
                                <span class="text-sm font-semibold">{{
                                    item.label
                                }}</span>
                                <span
                                    class="line-clamp-3 text-xs text-muted-foreground"
                                    >{{ item.description }}</span
                                >
                                <span
                                    v-if="form.style === item.value"
                                    class="absolute top-3 right-3 grid size-5 place-items-center rounded-full bg-primary text-primary-foreground"
                                    ><Check class="size-3" />
                                </span>
                            </button>
                        </div>
                        <div v-if="form.style === 'custom'" class="grid gap-2">
                            <Label for="video-brief"
                                >Describe the video you want</Label
                            >
                            <Textarea
                                id="video-brief"
                                v-model="form.settings.brief"
                                rows="3"
                                maxlength="1000"
                                placeholder="e.g. A calm sunset mood, start on the pool, end on the master bedroom view."
                            />
                            <InputError
                                :message="
                                    (form.errors as Record<string, string>)[
                                        'settings.brief'
                                    ]
                                "
                            />
                        </div>
                        <p
                            v-if="form.style === 'drone_reveal'"
                            class="flex gap-2 text-xs text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            Drone-style shots are simulated camera movement over
                            your photos, not real aerial footage.
                        </p>
                        <p
                            v-if="form.style === 'before_after_staging'"
                            class="flex gap-2 text-xs text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" /> Select
                            both the before and after photos in step 1. The
                            original condition is never invented.
                        </p>
                    </template>

                    <!-- 3. Format -->
                    <template v-else-if="step === 2">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <button
                                v-for="ratio in aspectRatios"
                                :key="ratio"
                                type="button"
                                class="relative flex flex-col items-center gap-3 rounded-xl border p-5 transition-all hover:-translate-y-0.5 hover:shadow-sm"
                                :class="
                                    form.aspect_ratio === ratio
                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                        : 'hover:border-foreground/30'
                                "
                                :aria-pressed="form.aspect_ratio === ratio"
                                @click="form.aspect_ratio = ratio"
                            >
                                <span
                                    class="grid h-20 place-items-center"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="rounded-md border-2 border-current bg-muted/60"
                                        :class="
                                            ratio === '16:9'
                                                ? 'h-11 w-20'
                                                : ratio === '9:16'
                                                  ? 'h-20 w-11'
                                                  : 'size-16'
                                        "
                                    />
                                </span>
                                <span class="text-sm font-semibold">{{
                                    ratio
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    ratio === '16:9'
                                        ? 'YouTube, websites'
                                        : ratio === '9:16'
                                          ? 'Reels, TikTok, Shorts'
                                          : 'Feeds'
                                }}</span>
                                <span
                                    v-if="form.aspect_ratio === ratio"
                                    class="absolute top-3 right-3 grid size-5 place-items-center rounded-full bg-primary text-primary-foreground"
                                    ><Check class="size-3" />
                                </span>
                            </button>
                        </div>
                        <p
                            v-if="
                                !limits.aspect_ratios.includes(
                                    form.aspect_ratio,
                                )
                            "
                            class="flex gap-2 text-xs text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" /> Clips are
                            generated in 16:9 and centre-cropped to
                            {{ form.aspect_ratio }} during assembly.
                        </p>
                    </template>

                    <!-- 4. Length -->
                    <template v-else-if="step === 3">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <button
                                v-for="seconds in durations"
                                :key="seconds"
                                type="button"
                                class="flex flex-col items-center rounded-xl border px-3 py-4 transition-all hover:-translate-y-0.5 hover:shadow-sm"
                                :class="
                                    form.duration === seconds
                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                        : 'hover:border-foreground/30'
                                "
                                :aria-pressed="form.duration === seconds"
                                @click="form.duration = seconds"
                            >
                                <span
                                    class="text-2xl font-semibold tabular-nums"
                                    >{{ seconds }}</span
                                >
                                <span class="text-xs text-muted-foreground"
                                    >seconds</span
                                >
                            </button>
                        </div>
                        <p
                            class="flex gap-2 rounded-lg bg-muted/40 p-3 text-sm text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-4 shrink-0" />
                            <span
                                >About {{ estimatedShots }} shot{{
                                    estimatedShots === 1 ? '' : 's'
                                }}
                                of {{ limits.min_seconds }}–{{
                                    limits.max_seconds
                                }}
                                seconds. Final length can differ slightly after
                                transitions.</span
                            >
                        </p>
                    </template>

                    <!-- 5. Sound -->
                    <template v-else-if="step === 4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button
                                v-for="mode in soundModes"
                                :key="mode.value"
                                type="button"
                                class="relative flex items-start gap-3 rounded-xl border p-4 text-left transition-all hover:-translate-y-0.5 hover:shadow-sm"
                                :class="
                                    form.soundtrack_mode === mode.value
                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                        : 'hover:border-foreground/30'
                                "
                                :aria-pressed="
                                    form.soundtrack_mode === mode.value
                                "
                                @click="form.soundtrack_mode = mode.value"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-lg"
                                    :class="accents[mode.tone]"
                                >
                                    <component
                                        :is="mode.icon"
                                        class="size-4.5"
                                    />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{
                                        mode.label
                                    }}</span>
                                    <span
                                        class="mt-0.5 block text-xs text-muted-foreground"
                                        >{{ mode.description }}</span
                                    >
                                </span>
                                <span
                                    v-if="form.soundtrack_mode === mode.value"
                                    class="absolute top-3 right-3 grid size-5 place-items-center rounded-full bg-primary text-primary-foreground"
                                    ><Check class="size-3" />
                                </span>
                            </button>
                        </div>

                        <div
                            v-if="form.soundtrack_mode === 'ai_music'"
                            class="grid gap-2 sm:w-64"
                        >
                            <Label for="video-mood">Mood</Label>
                            <NativeSelect
                                id="video-mood"
                                v-model="form.settings.music_mood"
                                :options="
                                    moods.map((mood) => ({
                                        value: mood,
                                        label: label(mood),
                                    }))
                                "
                            />
                        </div>
                        <div
                            v-if="form.soundtrack_mode === 'ambient'"
                            class="grid gap-2 sm:w-64"
                        >
                            <Label for="video-ambience">Ambience</Label>
                            <NativeSelect
                                id="video-ambience"
                                v-model="form.settings.ambience"
                                :options="
                                    ambiences.map((item) => ({
                                        value: item,
                                        label: label(item),
                                    }))
                                "
                            />
                        </div>
                        <div
                            v-if="form.soundtrack_mode === 'own_audio'"
                            class="grid gap-2"
                        >
                            <Label for="video-audio">Audio file</Label>
                            <NativeSelect
                                id="video-audio"
                                v-model="form.soundtrack_asset_id"
                                searchable
                                :options="
                                    audio.map((track) => ({
                                        value: track.id,
                                        label: track.name ?? 'Audio',
                                    }))
                                "
                                placeholder="Choose from media library"
                            />
                            <audio
                                v-if="form.soundtrack_asset_id"
                                :src="
                                    audio.find(
                                        (track) =>
                                            track.id ===
                                            form.soundtrack_asset_id,
                                    )?.url
                                "
                                controls
                                class="w-full max-w-md"
                            />
                            <p
                                v-if="!audio.length"
                                class="text-xs text-muted-foreground"
                            >
                                Upload MP3, WAV or M4A files in the media
                                library first. Only use music you have the
                                rights to.
                            </p>
                            <InputError
                                :message="form.errors.soundtrack_asset_id"
                            />
                        </div>

                        <div
                            v-if="form.soundtrack_mode !== 'none'"
                            class="grid gap-2 border-t pt-5"
                        >
                            <Label for="video-voice"
                                >Narration (optional)</Label
                            >
                            <NativeSelect
                                id="video-voice"
                                v-model="form.voiceover_asset_id"
                                searchable
                                :options="
                                    audio.map((track) => ({
                                        value: track.id,
                                        label: track.name ?? 'Audio',
                                    }))
                                "
                                placeholder="No narration"
                                class="sm:w-80"
                            />
                        </div>
                        <label
                            class="flex items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2.5 text-sm"
                        >
                            <Checkbox
                                :model-value="form.settings.branded_outro"
                                @update:model-value="
                                    form.settings.branded_outro =
                                        $event === true
                                "
                            />
                            End with the logo from the linked tour
                        </label>
                    </template>

                    <!-- 6. Review -->
                    <template v-else>
                        <dl class="grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="row in summaryRows"
                                :key="row.label"
                                class="flex items-center gap-3 rounded-xl border p-3"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-lg"
                                    :class="accents[row.tone]"
                                >
                                    <component :is="row.icon" class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <dt class="text-xs text-muted-foreground">
                                        {{ row.label }}
                                    </dt>
                                    <dd class="truncate text-sm font-medium">
                                        {{ row.value }}
                                    </dd>
                                </div>
                            </div>
                        </dl>
                        <div
                            class="space-y-1 rounded-xl bg-muted/50 p-4 text-sm"
                        >
                            <div class="flex justify-between">
                                <span>Storyboard now</span
                                ><span class="tabular-nums"
                                    >{{ prices.storyboard }} credit</span
                                >
                            </div>
                            <div
                                class="flex justify-between text-muted-foreground"
                            >
                                <span
                                    >Clips later (about
                                    {{ form.duration }} s)</span
                                ><span class="tabular-nums"
                                    >≈ {{ clipCredits }} credits</span
                                >
                            </div>
                            <div
                                v-if="musicCredits"
                                class="flex justify-between text-muted-foreground"
                            >
                                <span>AI music later</span
                                ><span class="tabular-nums"
                                    >{{ musicCredits }} credits</span
                                >
                            </div>
                            <div
                                class="flex justify-between border-t pt-1 text-xs text-muted-foreground"
                            >
                                <span>Available</span
                                ><span class="tabular-nums">{{
                                    formatCredits(available)
                                }}</span>
                            </div>
                        </div>
                        <InputError
                            :message="
                                (form.errors as Record<string, string>).credits
                            "
                        />
                    </template>
                </div>

                <footer
                    class="flex items-center justify-between border-t bg-muted/30 px-5 py-3"
                >
                    <Button
                        variant="ghost"
                        :disabled="step === 0"
                        @click="step--"
                        ><ArrowLeft /> Back</Button
                    >
                    <Button
                        v-if="step < steps.length - 1"
                        :disabled="!canContinue"
                        @click="step++"
                        >Continue <ArrowRight
                    /></Button>
                    <Button
                        v-else
                        :disabled="
                            form.processing || available < prices.storyboard
                        "
                        @click="submit"
                    >
                        <Sparkles /> Plan storyboard
                    </Button>
                </footer>
            </section>

            <!-- Live summary -->
            <aside
                class="hidden space-y-4 rounded-xl border bg-card p-4 shadow-sm lg:sticky lg:top-4 lg:block"
            >
                <div>
                    <p class="text-sm font-semibold">Your video</p>
                    <p class="text-xs text-muted-foreground">
                        Updates as you choose.
                    </p>
                </div>
                <div
                    v-if="form.source_ids.length"
                    class="grid grid-cols-4 gap-1.5"
                >
                    <img
                        v-for="id in form.source_ids.slice(0, 7)"
                        :key="id"
                        :src="sourceById[id]?.thumbnail_url ?? undefined"
                        alt=""
                        class="aspect-square rounded-md border object-cover"
                    />
                    <span
                        v-if="form.source_ids.length > 7"
                        class="grid aspect-square place-items-center rounded-md bg-muted text-xs font-medium"
                        >+{{ form.source_ids.length - 7 }}</span
                    >
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    No photos chosen yet.
                </p>
                <ul class="space-y-2.5">
                    <li
                        v-for="row in summaryRows"
                        :key="row.label"
                        class="flex items-center gap-2.5 text-sm"
                    >
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-lg"
                            :class="accents[row.tone]"
                        >
                            <component :is="row.icon" class="size-3.5" />
                        </span>
                        <span class="min-w-0">
                            <span
                                class="block text-[11px] text-muted-foreground"
                                >{{ row.label }}</span
                            >
                            <span class="block truncate text-sm font-medium">{{
                                row.value
                            }}</span>
                        </span>
                    </li>
                </ul>
                <div
                    class="flex items-center justify-between rounded-lg bg-muted/50 px-3 py-2 text-xs"
                >
                    <span class="text-muted-foreground">Storyboard cost</span>
                    <span class="font-semibold tabular-nums"
                        >{{ prices.storyboard }} credit</span
                    >
                </div>
            </aside>
        </div>
    </div>
</template>
