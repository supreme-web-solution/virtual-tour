<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Camera,
    Copy,
    Info,
    Loader2,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationList from '@/components/ai/GenerationList.vue';
import GenerationStatusBadge from '@/components/ai/GenerationStatusBadge.vue';
import StyleSelect from '@/components/ai/StyleSelect.vue';
import TourTargetFields from '@/components/ai/TourTargetFields.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useGenerationStatus } from '@/composables/useGenerationStatus';
import aiStudio from '@/routes/ai-studio';
import analyses from '@/routes/ai-studio/analyses';
import type { GenerationSummary, PhotoAnalysis, TourTarget } from '@/types';

const props = defineProps<{
    analysis: GenerationSummary & {
        result: PhotoAnalysis | null;
        property_id: string | null;
        property_name: string | null;
    };
    photos: Record<
        string,
        { id: string; thumbnail_url: string | null; filename: string | null }
    >;
    generations: GenerationSummary[];
    hasActive: boolean;
    tours: { id: string; title: string }[];
    pricePerPanorama: number;
    available: number;
    can: { convert: boolean };
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'AI Studio', href: aiStudio.index() },
        { title: 'Photo analysis', href: analyses.show(props.analysis.id) },
    ],
});

useGenerationStatus(
    () => props.hasActive,
    ['analysis', 'generations', 'hasActive', 'available'],
);

type Item = {
    asset_id: string;
    include: boolean;
    title: string;
    prompt: string;
};

const form = useForm<{
    style_id: number | null;
    negative_prompt: string;
    items: Item[];
    target: TourTarget;
}>({
    style_id: null,
    negative_prompt: 'people, text, watermark, distortion',
    items: [],
    target: { tour_mode: 'new', tour_id: '', tour_title: '' },
});

watch(
    () => props.analysis.result,
    (result) => {
        if (!result || form.items.length) {
            return;
        }

        form.items = result.images.map((image) => ({
            asset_id: image.asset_id,
            // Skip near-duplicates and poor photos by default; the user decides.
            include: image.duplicate_of === null && image.quality !== 'poor',
            title: image.title,
            prompt: `Photorealistic 360° view of this ${image.room_type || 'space'}: ${image.description}`.slice(
                0,
                2000,
            ),
        }));
        form.target.tour_title = props.analysis.property_name
            ? `${props.analysis.property_name} Tour`
            : 'Photo tour';
    },
    { immediate: true },
);

const imageById = computed(() =>
    Object.fromEntries(
        (props.analysis.result?.images ?? []).map((image) => [
            image.asset_id,
            image,
        ]),
    ),
);
const included = computed(() => form.items.filter((item) => item.include));
const cost = computed(() => included.value.length * props.pricePerPanorama);

const qualityTone: Record<string, string> = {
    good: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    acceptable:
        'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    poor: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
};

function itemError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[`items.${index}.${field}`];
}

function submit(): void {
    form.transform((data) => ({
        style_id: data.style_id,
        negative_prompt: data.negative_prompt || null,
        items: data.items
            .filter((item) => item.include)
            .map(({ asset_id, title, prompt }) => ({
                asset_id,
                title,
                prompt,
            })),
        ...data.target,
    })).submit(analyses.convert(props.analysis.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Photo analysis" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center gap-3">
            <Button variant="ghost" size="icon" class="size-8" as-child>
                <Link :href="aiStudio.index()" aria-label="Back to AI Studio">
                    <ArrowLeft />
                </Link>
            </Button>
            <h1
                class="min-w-0 flex-1 truncate text-xl font-semibold tracking-tight"
            >
                Photo analysis<template v-if="analysis.property_name">
                    · {{ analysis.property_name }}</template
                >
            </h1>
            <GenerationStatusBadge :status="analysis.status" />
        </div>

        <div
            v-if="analysis.is_active"
            class="flex flex-col items-center gap-3 rounded-lg border bg-card p-10 text-center shadow-sm"
        >
            <Loader2 class="size-8 animate-spin text-muted-foreground" />
            <p class="text-sm font-medium">Analysing {{ analysis.title }}…</p>
        </div>

        <div
            v-else-if="analysis.status === 'failed'"
            class="flex gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-destructive" />
            <div>
                <p class="font-medium">The analysis couldn't be completed.</p>
                <p class="text-muted-foreground">{{ analysis.error }}</p>
            </div>
        </div>

        <form
            v-else-if="analysis.result"
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]"
            @submit.prevent="submit"
        >
            <div class="min-w-0 space-y-4">
                <p class="text-sm text-muted-foreground">
                    {{ analysis.result.summary }}
                </p>
                <p class="flex gap-2 text-xs text-muted-foreground">
                    <Info class="mt-0.5 size-3.5 shrink-0" />
                    “Seen” lists what is visible in the photo. “Suggested” is
                    the AI's inference — check it before relying on it.
                </p>

                <div
                    v-for="(item, index) in form.items"
                    :key="item.asset_id"
                    class="flex flex-col gap-4 rounded-lg border bg-card p-4 shadow-sm sm:flex-row"
                    :class="{ 'opacity-60': !item.include }"
                >
                    <div class="w-full shrink-0 sm:w-44">
                        <div
                            class="aspect-[4/3] overflow-hidden rounded-md bg-muted"
                        >
                            <img
                                v-if="photos[item.asset_id]?.thumbnail_url"
                                :src="
                                    photos[item.asset_id].thumbnail_url ??
                                    undefined
                                "
                                :alt="item.title"
                                class="size-full object-cover"
                            />
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5 text-xs">
                            <span
                                class="rounded-full px-2 py-0.5 font-medium capitalize"
                                :class="
                                    qualityTone[
                                        imageById[item.asset_id]?.quality ??
                                            'acceptable'
                                    ]
                                "
                                >{{ imageById[item.asset_id]?.quality }}</span
                            >
                            <span class="rounded-full bg-muted px-2 py-0.5">{{
                                imageById[item.asset_id]?.room_type
                            }}</span>
                            <span
                                v-if="imageById[item.asset_id]?.duplicate_of"
                                class="flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                                ><Copy class="size-3" /> Duplicate</span
                            >
                        </div>
                    </div>
                    <div class="min-w-0 flex-1 space-y-3">
                        <div class="flex items-center gap-3">
                            <Checkbox
                                :model-value="item.include"
                                :aria-label="`Convert ${item.title}`"
                                @update:model-value="
                                    item.include = $event === true
                                "
                            />
                            <Input
                                v-model="item.title"
                                maxlength="120"
                                class="font-medium"
                                :aria-label="`Scene title for photo ${index + 1}`"
                            />
                        </div>
                        <InputError :message="itemError(index, 'title')" />
                        <div class="grid gap-3 text-xs sm:grid-cols-2">
                            <div
                                v-if="
                                    imageById[item.asset_id]?.observed_facts
                                        .length
                                "
                            >
                                <p class="mb-1 font-medium">Seen</p>
                                <ul
                                    class="list-disc space-y-0.5 pl-4 text-muted-foreground"
                                >
                                    <li
                                        v-for="fact in imageById[item.asset_id]
                                            .observed_facts"
                                        :key="fact"
                                    >
                                        {{ fact }}
                                    </li>
                                </ul>
                            </div>
                            <div
                                v-if="
                                    imageById[item.asset_id]
                                        ?.inferred_suggestions.length
                                "
                            >
                                <p class="mb-1 font-medium">Suggested</p>
                                <ul
                                    class="list-disc space-y-0.5 pl-4 text-muted-foreground"
                                >
                                    <li
                                        v-for="hint in imageById[item.asset_id]
                                            .inferred_suggestions"
                                        :key="hint"
                                    >
                                        {{ hint }}
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <p
                            v-if="imageById[item.asset_id]?.quality_notes"
                            class="text-xs text-muted-foreground"
                        >
                            Quality:
                            {{ imageById[item.asset_id].quality_notes }}
                        </p>
                        <div v-if="item.include" class="grid gap-1.5">
                            <Label
                                :for="`prompt-${item.asset_id}`"
                                class="text-xs"
                                >360° prompt</Label
                            >
                            <Textarea
                                :id="`prompt-${item.asset_id}`"
                                v-model="item.prompt"
                                rows="2"
                                maxlength="2000"
                            />
                            <InputError :message="itemError(index, 'prompt')" />
                        </div>
                    </div>
                </div>
            </div>

            <aside>
                <div
                    class="sticky top-4 space-y-4 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="text-sm font-medium">Convert to 360° scenes</h2>
                    <p class="flex gap-2 text-xs text-muted-foreground">
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        Panoramas are AI-extended from each photo: areas outside
                        the frame are invented, not captured.
                    </p>
                    <div class="grid gap-2">
                        <Label for="analysis-style">Style (Model 4)</Label>
                        <StyleSelect
                            id="analysis-style"
                            v-model="form.style_id"
                            image-to-panorama
                        />
                        <InputError :message="form.errors.style_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="analysis-negative">Avoid</Label>
                        <Input
                            id="analysis-negative"
                            v-model="form.negative_prompt"
                            maxlength="600"
                        />
                    </div>
                    <TourTargetFields
                        v-model="form.target"
                        :tours="tours"
                        allow-none
                        :errors="form.errors as Record<string, string>"
                    />
                    <div class="rounded-md bg-muted/50 p-3 text-sm">
                        <div class="flex justify-between">
                            <span
                                >{{ included.length }} ×
                                {{ pricePerPanorama }}</span
                            >
                            <span class="font-semibold tabular-nums"
                                >{{ cost }} credits</span
                            >
                        </div>
                        <div
                            class="mt-1 flex justify-between text-xs text-muted-foreground"
                        >
                            <span>Available</span
                            ><span class="tabular-nums">{{
                                formatCredits(available)
                            }}</span>
                        </div>
                    </div>
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).credits ??
                            form.errors.items
                        "
                    />
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="
                            form.processing ||
                            !can.convert ||
                            !included.length ||
                            !form.style_id ||
                            cost > available
                        "
                    >
                        <Camera /> Convert {{ included.length }} photo{{
                            included.length === 1 ? '' : 's'
                        }}
                    </Button>
                </div>
            </aside>
        </form>

        <section v-if="generations.length" class="space-y-3">
            <h2 class="text-base font-medium">Panoramas from these photos</h2>
            <GenerationList :generations="generations" />
        </section>
    </div>
</template>
