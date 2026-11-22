<script setup lang="ts">
import { isUnlimitedCredits } from '@/lib/credits';
import { Link, router } from '@inertiajs/vue3';
import {
    Check,
    Globe,
    ImageIcon,
    Link2,
    Loader2,
    Orbit,
    PenLine,
    RotateCcw,
    Sparkles,
    SquareArrowOutUpRight,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MediaPickField from '@/components/create/MediaPickField.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { getJson, HttpError, postJson } from '@/lib/http';
import {
    complete as completeImport,
    show as showImport,
    store as storeImport,
} from '@/routes/imports';
import { progress as tourProgress, store as storeTour } from '@/routes/tours';
import {
    describe as describeRoute,
    link as linkRoute,
    options as optionsRoute,
    panoramas as panoramasRoute,
    photos as photosRoute,
} from '@/routes/tours/create';
import type {
    CreateMode,
    CreateOptions,
    ListingImport,
    TourBuild,
} from '@/types';

/**
 * Four ways in, one tour: describe a space, upload photos, upload 360°
 * photos or paste a listing link. Pressing "Generate tour" does everything
 * (planning, 360° scenes, linking) and shows progress right here.
 */
const props = withDefaults(
    defineProps<{
        initialMode?: CreateMode;
        propertyId?: string | null;
    }>(),
    { initialMode: 'describe', propertyId: null },
);

const emit = defineEmits<{ started: [build: TourBuild] }>();

const tabs: { value: CreateMode; label: string; icon: typeof PenLine }[] = [
    { value: 'describe', label: 'Describe', icon: PenLine },
    { value: 'photos', label: 'Photo', icon: ImageIcon },
    { value: 'panoramas', label: '360° photos', icon: Orbit },
    { value: 'link', label: 'Link', icon: Link2 },
];

const options = ref<CreateOptions | null>(null);
const loadError = ref<string | null>(null);
const mode = ref<CreateMode>(props.initialMode);
const description = ref('');
const sceneCount = ref('6');
const propertyType = ref('residential');
const photoIds = ref<string[]>([]);
const convertPhotos = ref(true);
const panoramaIds = ref<string[]>([]);
const uploading = ref(false);
const listingUrl = ref('');
const listing = ref<ListingImport | null>(null);
const readingListing = ref(false);
const imageUrls = ref<string[]>([]);
const rightsConfirmed = ref(false);
const starting = ref(false);
const error = ref<string | null>(null);
const build = ref<TourBuild | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

onMounted(loadOptions);
onBeforeUnmount(() => clearTimeout(timer));
watch(mode, () => (error.value = null));

async function loadOptions(): Promise<void> {
    try {
        options.value = await getJson<CreateOptions>(optionsRoute.url());
    } catch {
        loadError.value = 'Could not load. Reload the page and try again.';
    }
}

const needsAi = computed(
    () => mode.value !== 'panoramas' && mode.value !== 'photos',
);
// Photos are converted to 360° when AI can do it and the user has not opted out.
const convertingPhotos = computed(
    () => convertPhotos.value && options.value?.features.panoramas === true,
);
const unavailable = computed(() => {
    if (!options.value) {
        return null;
    }

    if (mode.value === 'describe' && !options.value.features.ai) {
        return 'AI planning needs the OpenAI key. You can still build a tour from your own 360° photos.';
    }

    if (needsAi.value && !options.value.features.panoramas) {
        return 'AI 360° generation is not set up yet (the Skybox key is missing). You can still build a tour from your own 360° photos.';
    }

    return null;
});

const credits = computed(() => {
    const prices = options.value?.prices;

    if (!prices) {
        return 0;
    }

    switch (mode.value) {
        case 'describe':
            return prices.plan + Number(sceneCount.value) * prices.text_scene;
        case 'photos':
            return convertingPhotos.value
                ? (options.value?.features.ai ? prices.analysis : 0) +
                      photoIds.value.length * prices.photo_scene
                : 0;
        case 'link':
            return (
                (options.value?.features.ai ? prices.analysis : 0) +
                imageUrls.value.length * prices.photo_scene
            );
        default:
            return 0;
    }
});

const canGenerate = computed(() => {
    if (
        !options.value ||
        starting.value ||
        uploading.value ||
        unavailable.value
    ) {
        return false;
    }

    switch (mode.value) {
        case 'describe':
            return description.value.trim().length >= 15;
        case 'photos':
            return photoIds.value.length > 0;
        case 'panoramas':
            return panoramaIds.value.length > 0;
        case 'link':
            return (
                listing.value?.status === 'ready' &&
                imageUrls.value.length > 0 &&
                rightsConfirmed.value
            );
    }

    return false;
});

const sceneOptions = computed(() =>
    Array.from(
        { length: Math.max(1, (options.value?.max_scenes ?? 8) - 1) },
        (_, index) => {
            const count = String(index + 2);

            return { value: count, label: `${count} scenes` };
        },
    ),
);

async function generate(): Promise<void> {
    if (!canGenerate.value) {
        return;
    }

    starting.value = true;
    error.value = null;

    try {
        const body = { property_id: props.propertyId };
        const response =
            mode.value === 'describe'
                ? await postJson<{ tour: TourBuild }>(describeRoute.url(), {
                      ...body,
                      description: description.value.trim(),
                      scene_count: Number(sceneCount.value),
                      property_type: propertyType.value,
                  })
                : mode.value === 'photos'
                  ? await postJson<{ tour: TourBuild }>(photosRoute.url(), {
                        ...body,
                        photo_ids: photoIds.value,
                        convert: convertPhotos.value,
                    })
                  : mode.value === 'panoramas'
                    ? await postJson<{ tour: TourBuild }>(
                          panoramasRoute.url(),
                          { ...body, panorama_ids: panoramaIds.value },
                      )
                    : await postJson<{ tour: TourBuild }>(
                          linkRoute.url(listing.value?.id ?? ''),
                          {
                              target: 'new',
                              details: listing.value?.details ?? {},
                              image_urls: imageUrls.value,
                              rights_confirmed: rightsConfirmed.value,
                          },
                      );

        build.value = response.tour;
        emit('started', response.tour);
        poll();
    } catch (exception) {
        error.value =
            exception instanceof HttpError
                ? exception.message
                : 'Something went wrong. Please try again.';
    } finally {
        starting.value = false;
    }
}

function poll(): void {
    clearTimeout(timer);

    if (!build.value || build.value.is_finished) {
        return;
    }

    timer = setTimeout(async () => {
        if (!build.value) {
            return;
        }

        try {
            build.value = (
                await getJson<{ tour: TourBuild }>(
                    tourProgress.url(build.value.id),
                )
            ).tour;
        } catch {
            // Keep trying: a slow request should not stop the progress view.
        }

        poll();
    }, 3000);
}

async function readListing(): Promise<void> {
    if (!listingUrl.value.trim()) {
        return;
    }

    readingListing.value = true;
    error.value = null;
    listing.value = null;
    imageUrls.value = [];

    try {
        let url = listingUrl.value.trim();

        if (!/^https?:\/\//i.test(url)) {
            url = `https://${url}`;
        }

        listing.value = (
            await postJson<{ import: ListingImport }>(storeImport.url(), {
                url,
            })
        ).import;

        while (listing.value && listing.value.is_active) {
            await new Promise((resolve) => setTimeout(resolve, 2000));
            listing.value = (
                await getJson<{ import: ListingImport }>(
                    showImport.url(listing.value.id),
                )
            ).import;
        }

        imageUrls.value = (listing.value?.images ?? [])
            .slice(0, 8)
            .map((image) => image.url);
    } catch (exception) {
        error.value =
            exception instanceof HttpError
                ? exception.message
                : 'Could not read that page.';
    } finally {
        readingListing.value = false;
    }
}

function toggleImage(url: string): void {
    imageUrls.value = imageUrls.value.includes(url)
        ? imageUrls.value.filter((value) => value !== url)
        : [...imageUrls.value, url];
}

function hideBrokenImage(url: string): void {
    if (listing.value) {
        listing.value = {
            ...listing.value,
            images: listing.value.images.filter((image) => image.url !== url),
        };
    }

    imageUrls.value = imageUrls.value.filter((value) => value !== url);
}

/**
 * Without AI 360° generation, a listing can still be saved as a property
 * with its photos.
 */
async function importPhotosOnly(): Promise<void> {
    if (!listing.value) {
        return;
    }

    starting.value = true;

    try {
        const response = await postJson<{ property_url: string }>(
            completeImport.url(listing.value.id),
            {
                target: 'new',
                details: listing.value.details ?? {},
                image_urls: imageUrls.value,
                rights_confirmed: rightsConfirmed.value,
            },
        );
        router.visit(response.property_url);
    } catch (exception) {
        error.value =
            exception instanceof HttpError
                ? exception.message
                : 'Could not save the property.';
    } finally {
        starting.value = false;
    }
}

function startBlank(): void {
    router.post(storeTour.url(), {
        title: 'New tour',
        property_id: props.propertyId,
    });
}

function startAgain(): void {
    clearTimeout(timer);
    build.value = null;
    description.value = '';
    photoIds.value = [];
    panoramaIds.value = [];
    listing.value = null;
    listingUrl.value = '';
    imageUrls.value = [];
    rightsConfirmed.value = false;
    void loadOptions();
}

const stageLabel = computed(() => {
    switch (build.value?.stage) {
        case 'planning':
            return 'Planning scenes with AI';
        case 'analysing':
            return 'Reading your photos';
        case 'importing':
            return 'Copying the listing photos';
        case 'generating':
            return 'Generating 360° scenes';
        default:
            return 'Linking hotspots & navigation';
    }
});
const percent = computed(() => {
    const current = build.value;

    if (!current) {
        return 0;
    }

    if (current.stage === 'ready') {
        return 100;
    }

    if (current.stage !== 'generating' || current.total === 0) {
        return 6;
    }

    return Math.max(8, Math.round((current.done / current.total) * 100));
});
const placeholders = computed(() =>
    Math.max(0, (build.value?.total ?? 0) - (build.value?.scenes.length ?? 0)),
);
</script>

<template>
    <div class="space-y-4">
        <!-- Progress -->
        <template v-if="build">
            <div class="space-y-2">
                <div class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2">
                        <Loader2
                            v-if="!build.is_finished"
                            class="size-4 animate-spin"
                        />
                        {{
                            build.stage === 'failed'
                                ? 'Something went wrong'
                                : stageLabel
                        }}
                    </span>
                    <span class="font-medium tabular-nums"
                        >{{ build.done }}/{{ build.total }}</span
                    >
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-sky-400 via-violet-500 to-pink-500 transition-all duration-700"
                        :class="
                            build.stage === 'failed'
                                ? 'from-red-400 via-red-500 to-red-500'
                                : ''
                        "
                        :style="{ width: `${percent}%` }"
                    />
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                <div
                    v-for="(scene, index) in build.scenes"
                    :key="`${index}-${scene.title}`"
                    class="relative aspect-[4/3] overflow-hidden rounded-md bg-muted"
                    :title="scene.title"
                >
                    <img
                        v-if="scene.thumbnail_url"
                        :src="scene.thumbnail_url"
                        :alt="scene.title"
                        class="size-full object-cover"
                    />
                    <div
                        v-else-if="scene.status === 'working'"
                        class="size-full animate-pulse bg-gradient-to-br from-muted to-muted-foreground/20"
                    />
                    <div
                        v-else-if="scene.status === 'failed'"
                        class="flex size-full items-center justify-center"
                    >
                        <TriangleAlert class="size-4 text-red-600" />
                    </div>
                </div>
                <div
                    v-for="index in placeholders"
                    :key="`p${index}`"
                    class="aspect-[4/3] animate-pulse rounded-md bg-muted"
                />
            </div>

            <div
                v-if="build.stage === 'ready'"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-9 items-center justify-center rounded-full bg-emerald-600 text-white"
                        ><Check class="size-5"
                    /></span>
                    <div>
                        <p
                            class="text-sm font-semibold text-emerald-700 dark:text-emerald-300"
                        >
                            Tour ready · {{ build.scene_count }} scenes
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ build.title }}
                        </p>
                    </div>
                </div>
                <Button as-child>
                    <Link :href="build.editor_url"
                        >Open <SquareArrowOutUpRight
                    /></Link>
                </Button>
            </div>

            <div
                v-else-if="build.stage === 'failed'"
                class="space-y-3 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm"
            >
                <p>{{ build.error }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" @click="startAgain"
                        ><RotateCcw /> Try again</Button
                    >
                    <Button
                        v-if="build.scene_count"
                        size="sm"
                        variant="ghost"
                        as-child
                    >
                        <Link :href="build.editor_url"
                            >Open the {{ build.scene_count }} finished
                            scenes</Link
                        >
                    </Button>
                </div>
            </div>

            <div
                v-else
                class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
            >
                <span
                    >You can close this. The tour keeps building and appears in
                    your tours list.</span
                >
                <Link
                    :href="build.editor_url"
                    class="underline-offset-4 hover:underline"
                    >Open now</Link
                >
            </div>

            <button
                v-if="build.is_finished"
                type="button"
                class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                @click="startAgain"
            >
                Create another tour
            </button>
        </template>

        <!-- Choose a way in -->
        <template v-else>
            <div
                class="grid grid-cols-4 gap-1 rounded-xl bg-muted p-1"
                role="tablist"
                aria-label="How to start"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    role="tab"
                    :aria-selected="mode === tab.value"
                    class="flex flex-col items-center gap-1 rounded-lg px-2 py-2.5 text-xs font-medium transition-colors sm:text-sm"
                    :class="
                        mode === tab.value
                            ? 'bg-card text-foreground shadow-sm ring-1 ring-border'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="mode = tab.value"
                >
                    <component :is="tab.icon" class="size-4" />
                    {{ tab.label }}
                </button>
            </div>

            <div v-if="loadError" class="text-sm text-red-600">
                {{ loadError }}
            </div>
            <div
                v-else-if="!options"
                class="space-y-4"
                role="status"
                aria-label="Loading"
            >
                <div class="flex items-center justify-between">
                    <Skeleton class="h-3 w-36" />
                    <Skeleton class="h-3 w-10" />
                </div>
                <Skeleton class="h-28 w-full rounded-xl" />
                <div class="flex items-center justify-between gap-3">
                    <Skeleton class="h-3 w-28" />
                    <Skeleton class="h-9 w-32 rounded-lg" />
                </div>
            </div>

            <template v-else>
                <div
                    class="flex items-center justify-between text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    <span>
                        <template v-if="mode === 'describe'"
                            >Describe your space</template
                        >
                        <template v-else-if="mode === 'photos'"
                            >Upload photos</template
                        >
                        <template v-else-if="mode === 'panoramas'"
                            >Upload your 360° photos</template
                        >
                        <template v-else>Paste a listing link</template>
                    </span>
                    <span
                        v-if="mode !== 'panoramas'"
                        class="flex items-center gap-1"
                        ><Sparkles class="size-3" /> AI</span
                    >
                </div>

                <!-- Describe -->
                <div v-if="mode === 'describe'" class="space-y-3">
                    <Textarea
                        v-model="description"
                        rows="4"
                        maxlength="2000"
                        placeholder="A 320 m² sky penthouse on the 58th floor — double-height living room, floor-to-ceiling windows, sunset over the marina."
                        aria-label="Describe the space"
                        class="resize-none text-base"
                    />
                    <div class="flex flex-wrap gap-2">
                        <NativeSelect
                            v-model="sceneCount"
                            :options="sceneOptions"
                            aria-label="Number of scenes"
                            class="w-36"
                        />
                        <NativeSelect
                            v-model="propertyType"
                            :options="options.property_types"
                            aria-label="Kind of property"
                            class="w-48"
                        />
                    </div>
                </div>

                <!-- Photos -->
                <div v-else-if="mode === 'photos'" class="space-y-4">
                    <MediaPickField
                        v-model="photoIds"
                        asset-type="photo"
                        :asset-types="options.asset_types"
                        :recent="options.recent.photos"
                        :max="options.max_photos"
                        :property-id="propertyId"
                        @busy="uploading = $event"
                    />
                    <label
                        v-if="options.features.panoramas"
                        class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3.5 py-3 text-sm"
                    >
                        <span>
                            <span class="block font-medium"
                                >Convert to 360° with AI</span
                            >
                            <span class="block text-xs text-muted-foreground"
                                >Turns each photo into a full 360° scene. Turn
                                off to use photos as they are.</span
                            >
                        </span>
                        <Switch v-model="convertPhotos" />
                    </label>
                </div>

                <!-- 360° photos -->
                <MediaPickField
                    v-else-if="mode === 'panoramas'"
                    v-model="panoramaIds"
                    asset-type="panorama"
                    :asset-types="options.asset_types"
                    :recent="options.recent.panoramas"
                    :max="50"
                    :property-id="propertyId"
                    @busy="uploading = $event"
                />

                <!-- Link -->
                <div v-else class="space-y-3">
                    <form class="flex gap-2" @submit.prevent="readListing">
                        <div class="relative flex-1">
                            <Globe
                                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                            />
                            <Input
                                v-model="listingUrl"
                                placeholder="youragency.com/listing/villa-serena"
                                class="pl-9"
                                aria-label="Listing address"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="readingListing || !listingUrl.trim()"
                        >
                            <Loader2
                                v-if="readingListing"
                                class="animate-spin"
                            />
                            {{ readingListing ? 'Reading…' : 'Read' }}
                        </Button>
                    </form>

                    <p
                        v-if="listing?.status === 'failed'"
                        class="text-sm text-red-600"
                    >
                        {{ listing.error }}
                    </p>

                    <template v-if="listing?.status === 'ready'">
                        <p class="text-sm">
                            <span class="font-medium">{{
                                listing.page_title ?? listing.source_host
                            }}</span>
                            <span class="text-muted-foreground">
                                · choose the photos to turn into 360°
                                scenes</span
                            >
                        </p>
                        <div
                            v-if="listing.images.length"
                            class="grid grid-cols-4 gap-2 sm:grid-cols-6"
                        >
                            <button
                                v-for="image in listing.images"
                                :key="image.url"
                                type="button"
                                class="relative aspect-[4/3] overflow-hidden rounded-md border bg-muted"
                                :class="
                                    imageUrls.includes(image.url)
                                        ? 'ring-2 ring-primary ring-offset-1 ring-offset-background'
                                        : 'opacity-50 hover:opacity-90'
                                "
                                :aria-pressed="imageUrls.includes(image.url)"
                                @click="toggleImage(image.url)"
                            >
                                <img
                                    :src="image.url"
                                    :alt="image.alt ?? 'Listing photo'"
                                    class="size-full object-cover"
                                    loading="lazy"
                                    referrerpolicy="no-referrer"
                                    @error="hideBrokenImage(image.url)"
                                />
                            </button>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            No photos were found on that page.
                        </p>
                        <label
                            v-if="imageUrls.length"
                            class="flex items-start gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="rightsConfirmed"
                                class="mt-0.5"
                                @update:model-value="
                                    rightsConfirmed = $event === true
                                "
                            />
                            I own these photos or have permission to use them.
                        </label>
                    </template>
                </div>

                <p
                    v-if="unavailable"
                    class="flex gap-2 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{ unavailable }}
                </p>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <Button
                    size="lg"
                    class="h-12 w-full bg-gradient-to-r from-sky-500 to-indigo-500 text-base text-white hover:from-sky-500/90 hover:to-indigo-500/90"
                    :disabled="!canGenerate"
                    @click="generate"
                >
                    <Loader2 v-if="starting" class="animate-spin" />
                    <Sparkles v-else />
                    Generate tour
                </Button>

                <div
                    class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
                >
                    <span
                        v-if="credits && isUnlimitedCredits(options.available)"
                        >AI credits are included in your plan</span
                    >
                    <span v-else-if="credits"
                        >Uses about {{ credits }} credits · you have
                        {{ options.available }}</span
                    >
                    <span v-else-if="mode === 'panoramas' || mode === 'photos'"
                        >Free · scenes are linked in the order you add
                        them</span
                    >
                    <span v-else />
                    <span class="flex gap-3">
                        <button
                            v-if="
                                mode === 'link' &&
                                listing?.status === 'ready' &&
                                !options.features.panoramas &&
                                imageUrls.length
                            "
                            type="button"
                            class="underline-offset-4 hover:text-foreground hover:underline"
                            :disabled="!rightsConfirmed || starting"
                            @click="importPhotosOnly"
                        >
                            Import photos to a property instead
                        </button>
                        <button
                            type="button"
                            class="underline-offset-4 hover:text-foreground hover:underline"
                            @click="startBlank"
                        >
                            Start with an empty tour
                        </button>
                    </span>
                </div>
            </template>
        </template>
    </div>
</template>
