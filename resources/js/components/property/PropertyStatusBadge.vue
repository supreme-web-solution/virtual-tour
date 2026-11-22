<script setup lang="ts">
import type { PropertyStatus } from '@/types';

defineProps<{
    status: PropertyStatus;
    label: string;
}>();

const styles: Record<PropertyStatus, string> = {
    draft: 'bg-muted text-muted-foreground',
    active: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    archived:
        'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
        :class="styles[status]"
        >{{ label }}</span
    >
</template>
