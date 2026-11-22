<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Link2,
    Loader2,
    Sparkles,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import plans from '@/routes/ai-studio/plans';
import type {
    GenerationSummary,
    PanoramaStyle,
    ScenePlan,
    TourTarget,
} from '@/types';

const props = defineProps<{
    plan: GenerationSummary & {
        brief: Record<string, unknown>;
        result: ScenePlan | null;
        property_id: string | null;
    };
    generations: GenerationSummary[];
    hasActive: boolean;
    tours: { id: string; title: string }[];
    pricePerPanorama: number;
    available: number;
    can: { generate: boolean };
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'AI Studio', href: aiStudio.index() },
        { title: 'Scene plan', href: plans.show(props.plan.id) },
    ],
});

useGenerationStatus(
    () => props.hasActive,
    ['plan', 'generations', 'hasActive', 'available'],
);

type EditableScene = {
    key: string;
    include: boolean;
    title: string;
    panorama_prompt: string;
    negative_prompt: string;
};

const form = useForm<{
    style_id: number | null;
    scenes: EditableScene[];
    target: TourTarget;
}>({
    style_id: null,
    scenes: [],
    target: { tour_mode: 'new', tour_id: '', tour_title: '' },
});

const style = ref<PanoramaStyle | null>(null);
const styles = ref<PanoramaStyle[]>([]);

function loadScenes(result: ScenePlan | null): void {
    if (!result || form.scenes.length) {
        return;
    }

    form.scenes = result.scenes.map((scene) => ({
        key: scene.key,
        include: true,
        title: scene.title,
        panorama_prompt: scene.panorama_prompt,
        negative_prompt: scene.negative_prompt,
    }));
    form.target.tour_title = result.tour_title;
}

watch(() => props.plan.result, loadScenes, { immediate: true });
watch(
    () => form.style_id,
    (id) => {
        style.value = styles.value.find((item) => item.id === id) ?? null;
    },
);

const sceneMeta = computed(() =>
    Object.fromEntries(
        (props.plan.result?.scenes ?? []).map((scene) => [scene.key, scene]),
    ),
);
const included = computed(() => form.scenes.filter((scene) => scene.include));
const cost = computed(() => included.value.length * props.pricePerPanorama);
const affordable = computed(() => cost.value <= props.available);

function onStylesLoaded(list: PanoramaStyle[]): void {
    styles.value = list;
    style.value = list.find((item) => item.id === form.style_id) ?? null;
}

function sceneError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[`scenes.${index}.${field}`];
}

function submit(): void {
    form.transform((data) => ({
        style_id: data.style_id,
        scenes: data.scenes
            .filter((scene) => scene.include)
            .map((scene) => ({
                key: scene.key,
                title: scene.title,
                panorama_prompt: scene.panorama_prompt,
                negative_prompt: scene.negative_prompt || null,
            })),
        ...data.target,
    })).submit(plans.generate(props.plan.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Scene plan" />

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
                {{ plan.result?.tour_title ?? 'Scene plan' }}
            </h1>
            <GenerationStatusBadge :status="plan.status" />
        </div>

        <div
            v-if="plan.is_active"
            class="flex flex-col items-center gap-3 rounded-lg border bg-card p-10 text-center shadow-sm"
        >
            <Loader2 class="size-8 animate-spin text-muted-foreground" />
            <p class="text-sm font-medium">Drafting your scene plan…</p>
            <p class="max-w-md text-sm text-muted-foreground">
                AI is working out which scenes to include and writing a panorama
                prompt for each. This usually takes under a minute.
            </p>
        </div>

        <div
            v-else-if="plan.status === 'failed'"
            class="flex gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-destructive" />
            <div>
                <p class="font-medium">The plan couldn't be created.</p>
                <p class="text-muted-foreground">{{ plan.error }}</p>
                <p class="mt-1 text-muted-foreground">
                    The credit for this plan was released.
                </p>
            </div>
        </div>

        <form
            v-else-if="plan.result"
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]"
            @submit.prevent="submit"
        >
            <div class="min-w-0 space-y-4">
                <p
                    v-if="plan.result.tour_description"
                    class="text-sm text-muted-foreground"
                >
                    {{ plan.result.tour_description }}
                </p>

                <div
                    v-for="(scene, index) in form.scenes"
                    :key="scene.key"
                    class="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                    :class="{ 'opacity-60': !scene.include }"
                >
                    <div class="flex items-start gap-3">
                        <Checkbox
                            :model-value="scene.include"
                            :aria-label="`Include ${scene.title}`"
                            class="mt-2"
                            @update:model-value="
                                scene.include = $event === true
                            "
                        />
                        <div class="grid min-w-0 flex-1 gap-1">
                            <Input
                                v-model="scene.title"
                                maxlength="120"
                                :disabled="!scene.include"
                                class="font-medium"
                                :aria-label="`Scene ${index + 1} title`"
                            />
                            <InputError :message="sceneError(index, 'title')" />
                            <p class="text-xs text-muted-foreground">
                                {{ sceneMeta[scene.key]?.room_type }}
                                <template
                                    v-if="
                                        sceneMeta[scene.key]?.connections.length
                                    "
                                >
                                    · <Link2 class="inline size-3" />
                                    {{
                                        sceneMeta[scene.key].connections
                                            .map(
                                                (key) =>
                                                    sceneMeta[key]?.title ??
                                                    key,
                                            )
                                            .join(', ')
                                    }}
                                </template>
                            </p>
                        </div>
                    </div>
                    <div v-if="scene.include" class="grid gap-3 pl-7">
                        <div class="grid gap-1.5">
                            <div class="flex items-center justify-between">
                                <Label :for="`prompt-${scene.key}`"
                                    >Panorama prompt</Label
                                >
                                <span
                                    class="text-xs tabular-nums"
                                    :class="
                                        style &&
                                        scene.panorama_prompt.length >
                                            style.max_prompt_length
                                            ? 'text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                    >{{ scene.panorama_prompt.length
                                    }}<template v-if="style">
                                        /
                                        {{ style.max_prompt_length }}</template
                                    ></span
                                >
                            </div>
                            <Textarea
                                :id="`prompt-${scene.key}`"
                                v-model="scene.panorama_prompt"
                                rows="4"
                                maxlength="2000"
                            />
                            <InputError
                                :message="sceneError(index, 'panorama_prompt')"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label :for="`negative-${scene.key}`">Avoid</Label>
                            <Input
                                :id="`negative-${scene.key}`"
                                v-model="scene.negative_prompt"
                                maxlength="600"
                            />
                            <InputError
                                :message="sceneError(index, 'negative_prompt')"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <aside class="space-y-4">
                <div
                    class="sticky top-4 space-y-4 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="text-sm font-medium">Generate panoramas</h2>
                    <div class="grid gap-2">
                        <Label for="plan-style">Style</Label>
                        <StyleSelect
                            id="plan-style"
                            v-model="form.style_id"
                            @loaded="onStylesLoaded"
                        />
                        <InputError :message="form.errors.style_id" />
                    </div>
                    <TourTargetFields
                        v-model="form.target"
                        :tours="tours"
                        :errors="form.errors as Record<string, string>"
                    />
                    <div class="rounded-md bg-muted/50 p-3 text-sm">
                        <div class="flex justify-between">
                            <span
                                >{{ included.length }} scene{{
                                    included.length === 1 ? '' : 's'
                                }}
                                × {{ pricePerPanorama }}</span
                            >
                            <span class="font-semibold tabular-nums"
                                >{{ cost }} credits</span
                            >
                        </div>
                        <div
                            class="mt-1 flex justify-between text-xs text-muted-foreground"
                        >
                            <span>Available</span>
                            <span class="tabular-nums">{{
                                formatCredits(available)
                            }}</span>
                        </div>
                        <p
                            v-if="!affordable"
                            class="mt-2 text-xs text-destructive"
                        >
                            Not enough credits for the selected scenes.
                        </p>
                    </div>
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).credits ??
                            form.errors.scenes
                        "
                    />
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="
                            form.processing ||
                            !can.generate ||
                            !included.length ||
                            !form.style_id ||
                            !affordable
                        "
                    >
                        <Sparkles /> Generate {{ included.length }} panorama{{
                            included.length === 1 ? '' : 's'
                        }}
                    </Button>
                    <p class="text-xs text-muted-foreground">
                        Credits are held now and only charged for panoramas that
                        are generated and saved successfully. Each takes about
                        1–3 minutes.
                    </p>
                </div>
            </aside>
        </form>

        <section v-if="generations.length" class="space-y-3">
            <h2 class="text-base font-medium">Panoramas from this plan</h2>
            <GenerationList :generations="generations" />
        </section>
    </div>
</template>
