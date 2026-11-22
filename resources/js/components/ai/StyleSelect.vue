<script setup lang="ts">
import { AlertTriangle, Loader2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import aiStudio from '@/routes/ai-studio';
import type { PanoramaStyle } from '@/types';

const props = defineProps<{
    imageToPanorama?: boolean;
    id?: string;
}>();

const model = defineModel<number | null>({ default: null });
const emit = defineEmits<{ loaded: [styles: PanoramaStyle[]] }>();

const styles = ref<PanoramaStyle[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const options = computed(() =>
    styles.value.map((style) => ({
        value: String(style.id),
        label: `${style.name}${style.model ? ` · ${style.model}` : ''}`,
    })),
);

const selected = computed(
    () => styles.value.find((style) => style.id === model.value) ?? null,
);

defineExpose({ selected });

onMounted(async () => {
    try {
        const response = await fetch(
            aiStudio.styles.url({
                query: props.imageToPanorama ? { image_to_panorama: 1 } : {},
            }),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );
        const data = (await response.json()) as {
            styles?: PanoramaStyle[];
            default_style_id?: number | null;
            message?: string;
        };

        if (!response.ok) {
            error.value = data.message ?? 'Styles could not be loaded.';

            return;
        }

        styles.value = data.styles ?? [];

        if (
            model.value === null ||
            !styles.value.some((style) => style.id === model.value)
        ) {
            const preferred = styles.value.find(
                (style) => style.id === data.default_style_id,
            );
            model.value = (preferred ?? styles.value[0])?.id ?? null;
        }

        emit('loaded', styles.value);
    } catch {
        error.value = 'Styles could not be loaded.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="grid gap-1.5">
        <div
            v-if="loading"
            class="flex h-9 items-center gap-2 text-sm text-muted-foreground"
        >
            <Loader2 class="size-4 animate-spin" /> Loading styles…
        </div>
        <p
            v-else-if="error"
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 size-3.5 shrink-0" /> {{ error }}
        </p>
        <template v-else>
            <NativeSelect
                :id="id"
                :model-value="model === null ? '' : String(model)"
                :options="options"
                @update:model-value="model = $event ? Number($event) : null"
            />
            <p
                v-if="imageToPanorama && !styles.length"
                class="text-xs text-muted-foreground"
            >
                No Model 4 styles are available on this Skybox account. Photo to
                360° needs a Standard or Business plan.
            </p>
        </template>
    </div>
</template>
