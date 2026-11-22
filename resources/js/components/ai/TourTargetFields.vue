<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TourTarget } from '@/types';

const props = defineProps<{
    tours: { id: string; title: string }[];
    allowNone?: boolean;
    errors?: Record<string, string | undefined>;
}>();

const target = defineModel<TourTarget>({ required: true });

const modeOptions = computed(() => [
    ...(props.allowNone
        ? [{ value: 'none', label: 'Only save to media library' }]
        : []),
    { value: 'new', label: 'Create a new tour' },
    ...(props.tours.length
        ? [{ value: 'existing', label: 'Add to an existing tour' }]
        : []),
]);

function update<K extends keyof TourTarget>(
    key: K,
    value: TourTarget[K],
): void {
    target.value = { ...target.value, [key]: value };
}
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label for="tour-mode">Destination</Label>
            <NativeSelect
                id="tour-mode"
                :model-value="target.tour_mode"
                :options="modeOptions"
                @update:model-value="
                    update('tour_mode', $event as TourTarget['tour_mode'])
                "
            />
            <InputError :message="errors?.tour_mode" />
        </div>
        <div v-if="target.tour_mode === 'new'" class="grid gap-2">
            <Label for="tour-title">New tour title</Label>
            <Input
                id="tour-title"
                :model-value="target.tour_title"
                maxlength="160"
                @update:model-value="update('tour_title', String($event))"
            />
            <InputError :message="errors?.tour_title" />
        </div>
        <div v-else-if="target.tour_mode === 'existing'" class="grid gap-2">
            <Label for="tour-existing">Tour</Label>
            <NativeSelect
                id="tour-existing"
                :model-value="target.tour_id"
                :options="
                    tours.map((tour) => ({ value: tour.id, label: tour.title }))
                "
                placeholder="Choose a tour"
                @update:model-value="update('tour_id', String($event ?? ''))"
            />
            <InputError :message="errors?.tour_id" />
        </div>
    </div>
</template>
