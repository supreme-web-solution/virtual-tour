<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ImageOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import media from '@/routes/media';
import type { LibraryPhoto } from '@/types';

const props = defineProps<{
    photos: LibraryPhoto[];
    properties: { id: string; name: string }[];
    multiple?: boolean;
    max?: number;
}>();

const selected = defineModel<string[]>({ default: () => [] });
const propertyFilter = ref('');

const visible = computed(() =>
    propertyFilter.value
        ? props.photos.filter(
              (photo) => photo.property_id === propertyFilter.value,
          )
        : props.photos,
);

function toggle(id: string): void {
    if (!props.multiple) {
        selected.value = selected.value[0] === id ? [] : [id];

        return;
    }

    if (selected.value.includes(id)) {
        selected.value = selected.value.filter((value) => value !== id);
    } else if (!props.max || selected.value.length < props.max) {
        selected.value = [...selected.value, id];
    }
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <NativeSelect
                v-model="propertyFilter"
                :options="
                    properties.map((p) => ({ value: p.id, label: p.name }))
                "
                placeholder="All properties"
                aria-label="Filter photos by property"
                class="w-56"
            />
            <span v-if="multiple" class="text-xs text-muted-foreground">
                {{ selected.length }} selected<template v-if="max">
                    (max {{ max }})</template
                >
            </span>
        </div>

        <div
            v-if="visible.length"
            class="grid max-h-80 grid-cols-3 gap-2 overflow-y-auto pr-1 sm:grid-cols-5"
        >
            <button
                v-for="photo in visible"
                :key="photo.id"
                type="button"
                class="relative aspect-[4/3] overflow-hidden rounded-md border bg-muted"
                :class="
                    selected.includes(photo.id)
                        ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                        : 'hover:opacity-90'
                "
                :title="photo.filename ?? undefined"
                :aria-pressed="selected.includes(photo.id)"
                @click="toggle(photo.id)"
            >
                <img
                    v-if="photo.thumbnail_url"
                    :src="photo.thumbnail_url"
                    :alt="photo.filename ?? 'Photo'"
                    class="size-full object-cover"
                    loading="lazy"
                />
                <ImageOff v-else class="m-auto size-5 text-muted-foreground" />
                <span
                    v-if="selected.includes(photo.id)"
                    class="absolute top-1 right-1 flex size-5 items-center justify-center rounded-full bg-primary text-[10px] font-medium text-primary-foreground"
                >
                    <template v-if="multiple">{{
                        selected.indexOf(photo.id) + 1
                    }}</template>
                    <Check v-else class="size-3" />
                </span>
            </button>
        </div>
        <p
            v-else
            class="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground"
        >
            No photos yet.
            <Link :href="media.index()" class="underline underline-offset-4"
                >Upload property photos</Link
            >
            to the media library first.
        </p>
    </div>
</template>
