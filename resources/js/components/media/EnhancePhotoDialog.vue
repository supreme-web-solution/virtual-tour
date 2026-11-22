<script setup lang="ts">
import { isUnlimitedCredits } from '@/lib/credits';
import { router } from '@inertiajs/vue3';
import { Check, Loader2, Sparkles, TriangleAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BeforeAfter from '@/components/media/BeforeAfter.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { getJson, HttpError, postJson } from '@/lib/http';
import { enhance as enhanceRoute } from '@/routes/media';
import { options as optionsRoute } from '@/routes/media/enhance';
import { show as showEnhancement } from '@/routes/media/enhancements';
import type { MediaAsset } from '@/types';

type Mode = { value: string; label: string; description: string };
type Options = {
    configured: boolean;
    upscale_configured: boolean;
    modes: Mode[];
    credits: { medium: number; high: number; upscale: number };
    available: number;
};
type Enhancement = {
    id: string;
    status: 'working' | 'done' | 'failed';
    error: string | null;
    original: {
        url: string;
        width: number | null;
        height: number | null;
    } | null;
    result: {
        id: string;
        url: string;
        width: number | null;
        height: number | null;
    } | null;
};

/**
 * AI photo studio. The original is never changed: the edit is saved as a new
 * photo in the library and compared with the original here.
 */
const props = defineProps<{
    asset: MediaAsset | null;
    /** An edit of this photo that is still running: reopening shows its progress. */
    resumeId?: string | null;
}>();
const open = defineModel<boolean>('open', { required: true });

const options = ref<Options | null>(null);
const mode = ref('enhance');
const quality = ref<'medium' | 'high'>('medium');
const starting = ref(false);
const error = ref<string | null>(null);
const enhancement = ref<Enhancement | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

const isUpscale = computed(() => mode.value === 'upscale');
const ready = computed(() =>
    isUpscale.value
        ? (options.value?.upscale_configured ?? false)
        : (options.value?.configured ?? false),
);
const cost = computed(
    () =>
        options.value?.credits[isUpscale.value ? 'upscale' : quality.value] ??
        0,
);
const enough = computed(() => (options.value?.available ?? 0) >= cost.value);

watch(open, async (isOpen) => {
    clearTimeout(timer);

    if (!isOpen) {
        // A finished edit is a new library photo: refresh what is on screen.
        if (enhancement.value?.status === 'done') {
            router.reload();
        }

        return;
    }

    enhancement.value = null;
    error.value = null;
    options.value = null;

    try {
        options.value = await getJson<Options>(optionsRoute.url());
    } catch {
        error.value =
            'Could not load the photo studio. Reload the page and try again.';

        return;
    }

    // Closing the dialog never stops an edit: pick up where it is now.
    if (props.resumeId) {
        try {
            enhancement.value = (
                await getJson<{ enhancement: Enhancement }>(
                    showEnhancement.url(props.resumeId),
                )
            ).enhancement;
            poll();
        } catch {
            // Fall back to the normal choices.
        }
    }
});

onBeforeUnmount(() => clearTimeout(timer));

async function start(): Promise<void> {
    if (!props.asset) {
        return;
    }

    starting.value = true;
    error.value = null;

    try {
        const response = await postJson<{
            enhancement: Enhancement;
            available: number;
        }>(enhanceRoute.url(props.asset.id), {
            mode: mode.value,
            quality: quality.value,
        });
        enhancement.value = response.enhancement;

        if (options.value) {
            options.value = { ...options.value, available: response.available };
        }

        // Mark the photo as "enhancing" on the page behind, too.
        router.reload({ only: ['assets', 'media'] });
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

    if (!enhancement.value || enhancement.value.status !== 'working') {
        return;
    }

    timer = setTimeout(async () => {
        try {
            enhancement.value = (
                await getJson<{ enhancement: Enhancement }>(
                    showEnhancement.url(enhancement.value?.id ?? ''),
                )
            ).enhancement;
        } catch {
            // Keep trying; one slow request should not stop the wait.
        }

        if (enhancement.value?.status !== 'working') {
            // Finished while open: the page behind shows the new photo too.
            router.reload({ only: ['assets', 'media', 'mediaCounts'] });
        }

        poll();
    }, 3000);
}

function again(): void {
    enhancement.value = null;
    error.value = null;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Enhance photo</DialogTitle>
                <DialogDescription>
                    AI improves a copy. Your original is never changed.
                </DialogDescription>
            </DialogHeader>

            <!-- Working -->
            <div
                v-if="enhancement?.status === 'working'"
                class="flex flex-col items-center gap-3 py-12 text-center"
            >
                <Loader2 class="size-7 animate-spin" />
                <p class="font-medium">Enhancing your photo…</p>
                <p class="text-sm text-muted-foreground">
                    This usually takes under a minute. You can close this and
                    keep working: the photo shows "Enhancing…" in your library,
                    and the result appears there when it is ready.
                </p>
            </div>

            <!-- Done -->
            <div
                v-else-if="
                    enhancement?.status === 'done' &&
                    enhancement.result &&
                    enhancement.original
                "
                class="space-y-3"
            >
                <BeforeAfter
                    :before="enhancement.original.url"
                    :after="enhancement.result.url"
                />
                <p
                    class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300"
                >
                    <Check class="size-4" /> Saved to your media library as a
                    new photo
                    <span
                        v-if="enhancement.result.width"
                        class="text-muted-foreground"
                        >· {{ enhancement.result.width }} ×
                        {{ enhancement.result.height }}</span
                    >
                </p>
                <p class="text-xs text-muted-foreground">
                    AI redraws the photo, so look closely at fine details
                    (edges, labels, small objects) before using it.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button @click="open = false">Done</Button>
                    <Button variant="outline" @click="again"
                        >Try another way</Button
                    >
                </div>
            </div>

            <!-- Failed -->
            <div
                v-else-if="enhancement?.status === 'failed'"
                class="space-y-3 py-4"
            >
                <p
                    class="flex items-start gap-2 text-sm text-red-700 dark:text-red-300"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{
                        enhancement.error ?? 'The photo could not be enhanced.'
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Your credits were returned.
                </p>
                <Button variant="outline" @click="again">Try again</Button>
            </div>

            <!-- Choose -->
            <div v-else-if="options" class="space-y-4">
                <div
                    v-if="asset?.thumbnail_url"
                    class="overflow-hidden rounded-lg bg-muted"
                >
                    <img
                        :src="asset.thumbnail_url"
                        :alt="asset.original_filename ?? 'Photo'"
                        class="mx-auto max-h-48 object-contain"
                    />
                </div>

                <div
                    class="grid gap-2 sm:grid-cols-2"
                    role="radiogroup"
                    aria-label="What to do"
                >
                    <button
                        v-for="item in options.modes"
                        :key="item.value"
                        type="button"
                        role="radio"
                        :aria-checked="mode === item.value"
                        class="rounded-lg border p-3 text-left transition-colors"
                        :class="
                            mode === item.value
                                ? 'border-primary bg-primary/5'
                                : 'hover:border-foreground/30'
                        "
                        @click="mode = item.value"
                    >
                        <p class="text-sm font-medium">{{ item.label }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ item.description }}
                        </p>
                    </button>
                </div>

                <div
                    v-if="!isUpscale"
                    class="flex flex-wrap items-center gap-3 text-sm"
                >
                    <span class="text-muted-foreground">Quality</span>
                    <div class="inline-flex rounded-md border p-0.5">
                        <button
                            v-for="item in [
                                { value: 'medium', label: 'Standard' },
                                { value: 'high', label: 'High' },
                            ] as const"
                            :key="item.value"
                            type="button"
                            class="rounded px-3 py-1"
                            :class="
                                quality === item.value
                                    ? 'bg-muted font-medium'
                                    : 'text-muted-foreground'
                            "
                            @click="quality = item.value"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <p v-if="isUpscale" class="text-xs text-muted-foreground">
                    A super-resolution model makes the photo four times larger
                    in each direction. It sharpens what is there and does not
                    redraw the scene.
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    The result is up to 1536 px on the long edge. The AI is told
                    not to add, remove or change anything in the property. Use
                    Upscale 4× when you need more pixels.
                </p>

                <p
                    v-if="!ready"
                    class="rounded-md bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200"
                >
                    {{
                        isUpscale
                            ? 'Upscaling needs the fal.ai key.'
                            : 'Photo enhancement needs the OpenAI key.'
                    }}
                    An administrator can add it in the server settings.
                </p>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span
                        class="text-xs text-muted-foreground"
                        :class="{ 'text-red-600': !enough }"
                    >
                        <template v-if="isUnlimitedCredits(options.available)"
                            >AI credits are included in your plan</template
                        >
                        <template v-else
                            >Uses {{ cost }} credits · you have
                            {{ options.available }}</template
                        >
                    </span>
                    <Button
                        :disabled="!ready || !enough || starting"
                        @click="start"
                    >
                        <Loader2 v-if="starting" class="animate-spin" />
                        <Sparkles v-else />
                        {{ isUpscale ? 'Upscale' : 'Enhance' }}
                    </Button>
                </div>
            </div>

            <p v-else-if="error" class="py-6 text-sm text-red-600">
                {{ error }}
            </p>
            <div
                v-else
                class="space-y-4 py-2"
                role="status"
                aria-label="Loading"
            >
                <Skeleton class="aspect-video w-full rounded-xl" />
                <div class="grid grid-cols-3 gap-2">
                    <Skeleton class="h-9 rounded-lg" />
                    <Skeleton class="h-9 rounded-lg" />
                    <Skeleton class="h-9 rounded-lg" />
                </div>
                <div class="flex justify-end gap-2">
                    <Skeleton class="h-9 w-20 rounded-lg" />
                    <Skeleton class="h-9 w-28 rounded-lg" />
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
