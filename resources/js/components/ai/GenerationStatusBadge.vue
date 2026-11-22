<script setup lang="ts">
import { AlertCircle, CheckCircle2, Loader2, XCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus }>();

const labels: Record<GenerationStatus, string> = {
    pending: 'Pending',
    queued: 'Queued',
    submitted: 'Submitted',
    processing: 'Generating',
    downloading: 'Retrieving',
    uploading: 'Saving',
    assembling: 'Assembling',
    completed: 'Completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
    refunded: 'Refunded',
};

const tone = computed(() => {
    switch (props.status) {
        case 'completed':
            return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300';
        case 'failed':
            return 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300';
        case 'cancelled':
        case 'refunded':
            return 'bg-muted text-muted-foreground';
        default:
            return 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="tone"
    >
        <CheckCircle2 v-if="status === 'completed'" class="size-3" />
        <AlertCircle v-else-if="status === 'failed'" class="size-3" />
        <XCircle
            v-else-if="status === 'cancelled' || status === 'refunded'"
            class="size-3"
        />
        <Loader2 v-else class="size-3 animate-spin" />
        {{ labels[status] }}
    </span>
</template>
