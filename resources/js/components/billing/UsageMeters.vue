<script setup lang="ts">
import { Check, Minus } from '@lucide/vue';
import { formatEntitlement, usagePercent } from '@/lib/billing';
import type { EntitlementSummary } from '@/types';

defineProps<{
    summary: EntitlementSummary;
}>();

function tone(percent: number | null): string {
    if (percent === null) {
        return 'bg-primary/40';
    }

    if (percent >= 100) {
        return 'bg-red-500';
    }

    return percent >= 80 ? 'bg-amber-500' : 'bg-primary';
}
</script>

<template>
    <div class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="limit in summary.limits"
                :key="limit.key"
                class="space-y-1.5"
            >
                <div class="flex items-baseline justify-between gap-2 text-sm">
                    <span>{{ limit.label }}</span>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        <template v-if="limit.used !== null">
                            {{ limit.used.toLocaleString() }}
                            {{ limit.unit ?? '' }} /
                        </template>
                        {{ formatEntitlement(limit.key, limit.limit) }}
                    </span>
                </div>
                <div
                    v-if="limit.used !== null"
                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-label="limit.label"
                    :aria-valuenow="usagePercent(limit.used, limit.limit) ?? 0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full transition-all"
                        :class="tone(usagePercent(limit.used, limit.limit))"
                        :style="{
                            width: `${usagePercent(limit.used, limit.limit) ?? 4}%`,
                        }"
                    />
                </div>
            </div>
        </div>

        <ul class="grid gap-1.5 border-t pt-4 text-sm sm:grid-cols-2">
            <li
                v-for="feature in summary.features"
                :key="feature.key"
                class="flex items-center gap-2"
                :class="feature.included ? '' : 'text-muted-foreground'"
            >
                <Check
                    v-if="feature.included"
                    class="size-4 text-emerald-600"
                />
                <Minus v-else class="size-4" />
                {{ feature.label }}
            </li>
        </ul>
    </div>
</template>
