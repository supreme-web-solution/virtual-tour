<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { ref, watch } from 'vue';
import {
    SliderRange,
    SliderRoot,
    SliderThumb,
    SliderTrack,
} from 'reka-ui';
import { cn } from '@/lib/utils';

/**
 * A single-value slider. `update:modelValue` fires while dragging, `commit`
 * once on release (use it to save).
 */
const props = withDefaults(
    defineProps<{
        modelValue: number;
        min?: number;
        max?: number;
        step?: number;
        disabled?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { min: 0, max: 100, step: 1 },
);

const emit = defineEmits<{
    'update:modelValue': [value: number];
    commit: [value: number];
}>();

const current = ref(props.modelValue);

watch(
    () => props.modelValue,
    (value) => {
        current.value = value;
    },
);

function onChange(value: number[] | undefined): void {
    if (value?.[0] === undefined) {
        return;
    }

    current.value = value[0];
    emit('update:modelValue', value[0]);
}
</script>

<template>
    <SliderRoot
        data-slot="slider"
        :model-value="[current]"
        :min="min"
        :max="max"
        :step="step"
        :disabled="disabled"
        :class="
            cn(
                'relative flex h-5 w-full touch-none items-center select-none data-[disabled]:opacity-50',
                props.class,
            )
        "
        @update:model-value="onChange"
        @value-commit="emit('commit', $event?.[0] ?? current)"
    >
        <SliderTrack
            class="relative h-1.5 w-full grow overflow-hidden rounded-full bg-muted"
        >
            <SliderRange class="absolute h-full rounded-full bg-primary" />
        </SliderTrack>
        <SliderThumb
            class="block size-4 rounded-full border-2 border-primary bg-background shadow-sm transition-[box-shadow] outline-none hover:ring-4 hover:ring-primary/15 focus-visible:ring-4 focus-visible:ring-primary/25 disabled:pointer-events-none"
        />
    </SliderRoot>
</template>
