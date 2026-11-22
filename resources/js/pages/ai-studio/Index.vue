<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { Sparkles as HeaderIcon } from '@lucide/vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Camera,
    Coins,
    Info,
    ScanSearch,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import GenerationList from '@/components/ai/GenerationList.vue';
import PhotoPicker from '@/components/ai/PhotoPicker.vue';
import StyleSelect from '@/components/ai/StyleSelect.vue';
import TourTargetFields from '@/components/ai/TourTargetFields.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useGenerationStatus } from '@/composables/useGenerationStatus';
import aiStudio from '@/routes/ai-studio';
import analyses from '@/routes/ai-studio/analyses';
import photoPanoramas from '@/routes/ai-studio/photo-panoramas';
import plans from '@/routes/ai-studio/plans';
import type {
    Credits,
    GenerationSummary,
    GenerationType,
    LibraryPhoto,
    Option,
    Paginated,
    TourTarget,
} from '@/types';

const props = defineProps<{
    credits: Credits;
    prices: Record<GenerationType, number>;
    generations: Paginated<GenerationSummary>;
    hasActive: boolean;
    propertyTypes: Option[];
    properties: { id: string; name: string }[];
    tours: { id: string; title: string }[];
    photos: LibraryPhoto[];
    limits: {
        max_scenes: number;
        max_photos_per_analysis: number;
        max_description_length: number;
    };
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'AI Studio', href: aiStudio.index() }],
    },
});

useGenerationStatus(
    () => props.hasActive,
    ['generations', 'hasActive', 'credits'],
);

const page = usePage();
const features = computed(() => page.props.features);
const mode = ref<'describe' | 'photo' | 'analyse'>('describe');

const modes = [
    { key: 'describe', label: 'Describe a property', icon: Sparkles },
    { key: 'photo', label: 'Photo to 360°', icon: Camera },
    { key: 'analyse', label: 'Analyse photos', icon: ScanSearch },
] as const;

const styleOptions = [
    'Modern',
    'Minimalist',
    'Scandinavian',
    'Luxury',
    'Contemporary',
    'Industrial',
    'Traditional',
    'Mediterranean',
    'Tropical',
    'Classic',
].map((style) => ({ value: style, label: style }));

const planForm = useForm({
    description: '',
    property_id: '',
    property_type: 'residential',
    scene_count: 4,
    interior_style: 'Modern',
    architectural_style: '',
    lighting: 'Warm natural daylight',
    time_of_day: 'Day',
    color_palette: '',
    atmosphere: '',
    additional_instructions: '',
    negative_prompt: '',
});

function submitPlan(): void {
    planForm
        .transform((data) => ({
            ...data,
            property_id: data.property_id || null,
        }))
        .submit(plans.store());
}

const photoForm = useForm<{
    photo_ids: string[];
    prompt: string;
    negative_prompt: string;
    style_id: number | null;
    target: TourTarget;
}>({
    photo_ids: [],
    prompt: 'Photorealistic continuation of this room, consistent architecture, materials and lighting',
    negative_prompt: 'people, text, watermark, distortion',
    style_id: null,
    target: { tour_mode: 'none', tour_id: '', tour_title: '' },
});

function submitPhoto(): void {
    photoForm
        .transform((data) => ({
            photo_id: data.photo_ids[0] ?? null,
            prompt: data.prompt,
            negative_prompt: data.negative_prompt || null,
            style_id: data.style_id,
            ...data.target,
        }))
        .submit(photoPanoramas.store(), {
            preserveScroll: true,
            onSuccess: () => photoForm.reset('photo_ids'),
        });
}

const analyseForm = useForm<{ photo_ids: string[] }>({ photo_ids: [] });

function submitAnalysis(): void {
    analyseForm.submit(analyses.store());
}

const creditsError = computed(
    () =>
        (page.props.errors as Record<string, string> | undefined)?.credits ??
        null,
);
</script>

<template>
    <Head title="AI Studio" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="AI Studio"
            :icon="HeaderIcon"
            tone="violet"
            description="Generate 360° scenes from a description, turn photos into panoramas, and let AI organise your property photos."
        >
            <template #actions>
                <div
                    class="flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm"
                >
                    <Coins class="size-4 text-amber-500" />
                    <span class="font-semibold tabular-nums">{{
                        formatCredits(credits.available)
                    }}</span>
                    credits available
                    <span
                        v-if="credits.reserved"
                        class="text-xs text-muted-foreground"
                        >({{ credits.reserved }} held)</span
                    >
                </div>
            </template>
        </PageHeader>

        <div
            v-if="creditsError"
            class="flex gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            {{ creditsError }}
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <section class="min-w-0 rounded-lg border bg-card shadow-sm">
                <div class="flex overflow-x-auto border-b">
                    <button
                        v-for="item in modes"
                        :key="item.key"
                        type="button"
                        class="flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-medium transition-colors"
                        :class="
                            mode === item.key
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        @click="mode = item.key"
                    >
                        <component :is="item.icon" class="size-4" />
                        {{ item.label }}
                    </button>
                </div>

                <!-- Describe a property -->
                <form
                    v-if="mode === 'describe'"
                    class="space-y-5 p-4"
                    @submit.prevent="submitPlan"
                >
                    <p
                        v-if="!features.ai_analysis"
                        class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        AI planning needs an OpenAI API key on the server
                        (OPENAI_API_KEY).
                    </p>
                    <p class="text-sm text-muted-foreground">
                        AI drafts a scene plan first ({{ prices.scene_plan }}
                        credit). You review and edit every scene before any
                        panorama is generated.
                    </p>
                    <div class="grid gap-2">
                        <Label for="description">Describe the property</Label>
                        <Textarea
                            id="description"
                            v-model="planForm.description"
                            rows="5"
                            :maxlength="limits.max_description_length"
                            placeholder="A modern luxury four-bedroom apartment in Lagos, contemporary African interior design, large windows, marble flooring, warm natural lighting and premium furnishings."
                        />
                        <InputError :message="planForm.errors.description" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="property_type">Property type</Label>
                            <NativeSelect
                                id="property_type"
                                v-model="planForm.property_type"
                                :options="propertyTypes"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="scene_count">Number of scenes</Label>
                            <Input
                                id="scene_count"
                                v-model.number="planForm.scene_count"
                                type="number"
                                min="1"
                                :max="limits.max_scenes"
                            />
                            <InputError
                                :message="planForm.errors.scene_count"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="plan_property">Property</Label>
                            <NativeSelect
                                id="plan_property"
                                v-model="planForm.property_id"
                                :options="
                                    properties.map((p) => ({
                                        value: p.id,
                                        label: p.name,
                                    }))
                                "
                                placeholder="Not linked"
                            />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="interior_style">Interior style</Label>
                            <NativeSelect
                                id="interior_style"
                                v-model="planForm.interior_style"
                                :options="styleOptions"
                                placeholder="Any"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="architectural_style"
                                >Architectural style</Label
                            >
                            <Input
                                id="architectural_style"
                                v-model="planForm.architectural_style"
                                maxlength="100"
                                placeholder="e.g. Tropical modernism"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="lighting">Lighting</Label>
                            <Input
                                id="lighting"
                                v-model="planForm.lighting"
                                maxlength="100"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="time_of_day">Time of day</Label>
                            <NativeSelect
                                id="time_of_day"
                                v-model="planForm.time_of_day"
                                :options="
                                    [
                                        'Morning',
                                        'Day',
                                        'Golden hour',
                                        'Dusk',
                                        'Night',
                                    ].map((v) => ({ value: v, label: v }))
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="color_palette">Colour palette</Label>
                            <Input
                                id="color_palette"
                                v-model="planForm.color_palette"
                                maxlength="150"
                                placeholder="e.g. warm neutrals, brass"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="atmosphere">Atmosphere</Label>
                            <Input
                                id="atmosphere"
                                v-model="planForm.atmosphere"
                                maxlength="150"
                                placeholder="e.g. calm, airy"
                            />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="additional"
                                >Additional instructions</Label
                            >
                            <Textarea
                                id="additional"
                                v-model="planForm.additional_instructions"
                                rows="2"
                                maxlength="1000"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="negative">Avoid</Label>
                            <Textarea
                                id="negative"
                                v-model="planForm.negative_prompt"
                                rows="2"
                                maxlength="400"
                                placeholder="e.g. clutter, people, text"
                            />
                        </div>
                    </div>
                    <Button
                        type="submit"
                        :disabled="
                            planForm.processing ||
                            !can.create ||
                            !features.ai_analysis
                        "
                    >
                        <Sparkles /> Draft scene plan
                    </Button>
                </form>

                <!-- Photo to 360 -->
                <form
                    v-else-if="mode === 'photo'"
                    class="space-y-5 p-4"
                    @submit.prevent="submitPhoto"
                >
                    <p
                        class="flex gap-2 rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground"
                    >
                        <Info class="mt-0.5 size-4 shrink-0" />
                        AI extends the photo into a full 360° view by inventing
                        what's outside the frame. The result is a synthetic
                        panorama, not an accurate capture of the real space.
                    </p>
                    <p
                        v-if="!features.panorama_generation"
                        class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        Panorama generation needs a Skybox API key on the server
                        (SKYBOX_API_KEY).
                    </p>
                    <div class="grid gap-2">
                        <Label>Photo</Label>
                        <PhotoPicker
                            v-model="photoForm.photo_ids"
                            :photos="photos"
                            :properties="properties"
                        />
                        <InputError
                            :message="
                                (photoForm.errors as Record<string, string>)
                                    .photo_id
                            "
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="photo-style">Style (Model 4)</Label>
                            <StyleSelect
                                v-if="features.panorama_generation"
                                id="photo-style"
                                v-model="photoForm.style_id"
                                image-to-panorama
                            />
                            <InputError :message="photoForm.errors.style_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="photo-negative">Avoid</Label>
                            <Input
                                id="photo-negative"
                                v-model="photoForm.negative_prompt"
                                maxlength="600"
                            />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="photo-prompt">Describe the space</Label>
                        <Textarea
                            id="photo-prompt"
                            v-model="photoForm.prompt"
                            rows="3"
                            maxlength="2000"
                        />
                        <InputError :message="photoForm.errors.prompt" />
                    </div>
                    <TourTargetFields
                        v-model="photoForm.target"
                        :tours="tours"
                        allow-none
                        :errors="photoForm.errors as Record<string, string>"
                    />
                    <Button
                        type="submit"
                        :disabled="
                            photoForm.processing ||
                            !photoForm.photo_ids.length ||
                            !photoForm.style_id ||
                            !can.create
                        "
                    >
                        <Camera /> Convert to 360° ·
                        {{ prices.photo_to_panorama }} credits
                    </Button>
                </form>

                <!-- Analyse photos -->
                <form
                    v-else
                    class="space-y-5 p-4"
                    @submit.prevent="submitAnalysis"
                >
                    <p
                        v-if="!features.ai_analysis"
                        class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        AI analysis needs an OpenAI API key on the server
                        (OPENAI_API_KEY).
                    </p>
                    <p class="text-sm text-muted-foreground">
                        AI identifies each room, rates image quality, flags
                        duplicates and suggests a tour order. You can correct
                        everything, then convert the photos you choose into 360°
                        scenes.
                    </p>
                    <PhotoPicker
                        v-model="analyseForm.photo_ids"
                        :photos="photos"
                        :properties="properties"
                        multiple
                        :max="limits.max_photos_per_analysis"
                    />
                    <InputError :message="analyseForm.errors.photo_ids" />
                    <Button
                        type="submit"
                        :disabled="
                            analyseForm.processing ||
                            !analyseForm.photo_ids.length ||
                            !can.create ||
                            !features.ai_analysis
                        "
                    >
                        <ScanSearch /> Analyse
                        {{ analyseForm.photo_ids.length || '' }} photos ·
                        {{ prices.photo_analysis }} credit
                    </Button>
                </form>
            </section>

            <section class="min-w-0 space-y-3">
                <h2 class="text-base font-medium">Recent generations</h2>
                <GenerationList
                    :generations="generations.data"
                    empty-text="Nothing generated yet. Your plans, analyses and panoramas will appear here."
                />
                <PaginationLinks :paginator="generations" />
            </section>
        </div>
    </div>
</template>
