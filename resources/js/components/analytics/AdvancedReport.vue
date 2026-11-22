<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Download } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { exportMethod as exportAnalytics } from '@/routes/analytics';
import type { AdvancedReport } from '@/types';

const props = defineProps<{
    report: AdvancedReport;
    filters: { tour: string; range: string };
    canExportLeads: boolean;
}>();

const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function duration(seconds: number): string {
    return seconds < 60
        ? `${seconds}s`
        : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

const metrics = computed(() => [
    {
        label: 'Views',
        value: props.report.comparison.views.current.toLocaleString(),
        change: props.report.comparison.views.change,
    },
    {
        label: 'Unique visitors',
        value: props.report.comparison.unique_visitors.current.toLocaleString(),
        change: props.report.comparison.unique_visitors.change,
    },
    {
        label: 'Avg. time',
        value: duration(
            props.report.comparison.average_duration_seconds.current,
        ),
        change: props.report.comparison.average_duration_seconds.change,
    },
    {
        label: 'Enquiries',
        value: props.report.comparison.leads.current.toLocaleString(),
        change: props.report.comparison.leads.change,
    },
    {
        label: 'Conversion',
        value: `${props.report.comparison.conversion_rate.current}%`,
        change: props.report.comparison.conversion_rate.change,
    },
]);

const heatMax = computed(() => Math.max(1, ...props.report.heatmap.flat()));

function exportUrl(type: 'visits' | 'leads'): string {
    const query: Record<string, string> = { type, range: props.filters.range };

    if (props.filters.tour) {
        query.tour = props.filters.tour;
    }

    return exportAnalytics.url({ query });
}
</script>

<template>
    <div class="space-y-4">
        <section class="rounded-lg border bg-card p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-medium">
                    Compared with the previous {{ filters.range }} days
                </h2>
                <div class="flex gap-2">
                    <Button size="sm" variant="outline" as-child>
                        <a :href="exportUrl('visits')"
                            ><Download /> Daily totals (CSV)</a
                        >
                    </Button>
                    <Button
                        v-if="canExportLeads"
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <a :href="exportUrl('leads')"
                            ><Download /> Enquiries (CSV)</a
                        >
                    </Button>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div v-for="metric in metrics" :key="metric.label">
                    <p class="text-xs text-muted-foreground">
                        {{ metric.label }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ metric.value }}
                    </p>
                    <p
                        v-if="metric.change !== null"
                        class="flex items-center gap-0.5 text-xs tabular-nums"
                        :class="
                            metric.change >= 0
                                ? 'text-emerald-600'
                                : 'text-red-600'
                        "
                    >
                        <ArrowUpRight
                            v-if="metric.change >= 0"
                            class="size-3"
                        />
                        <ArrowDownRight v-else class="size-3" />
                        {{ Math.abs(metric.change) }}%
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        No earlier data
                    </p>
                </div>
            </div>
        </section>

        <section
            v-if="report.tours.length"
            class="rounded-lg border bg-card shadow-sm"
        >
            <h2 class="border-b p-4 text-sm font-medium">Tour performance</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr class="border-b">
                            <th class="px-4 py-2 font-medium">Tour</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Views
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Unique
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Avg. time
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Saw every scene
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Enquiries
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Conversion
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tour in report.tours"
                            :key="tour.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-4 py-2">{{ tour.title }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ tour.views.toLocaleString() }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ tour.unique_visitors.toLocaleString() }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ duration(tour.average_duration_seconds) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ tour.completion_rate }}%
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ tour.leads }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ tour.conversion_rate }}%
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                v-if="report.funnel"
                class="rounded-lg border bg-card p-4 shadow-sm"
            >
                <h2 class="text-sm font-medium">Scene drop-off</h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    Share of visits that reached each scene, in tour order.
                </p>
                <ul class="space-y-2">
                    <li
                        v-for="(step, index) in report.funnel"
                        :key="`${index}-${step.label}`"
                        class="space-y-1 text-sm"
                    >
                        <div class="flex justify-between gap-2">
                            <span class="truncate"
                                >{{ index + 1 }}. {{ step.label }}</span
                            >
                            <span class="text-muted-foreground tabular-nums"
                                >{{ step.percent }}% · {{ step.visits }}</span
                            >
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-primary/80"
                                :style="{ width: `${step.percent}%` }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border bg-card p-4 shadow-sm">
                <h2 class="mb-3 text-sm font-medium">Most clicked hotspots</h2>
                <ul v-if="report.hotspots.length" class="divide-y text-sm">
                    <li
                        v-for="(hotspot, index) in report.hotspots"
                        :key="index"
                        class="flex justify-between gap-2 py-1.5"
                    >
                        <span class="min-w-0">
                            <span class="block truncate">{{
                                hotspot.label
                            }}</span>
                            <span
                                v-if="hotspot.scene"
                                class="text-xs text-muted-foreground"
                                >in {{ hotspot.scene }}</span
                            >
                        </span>
                        <span class="shrink-0 tabular-nums">{{
                            hotspot.clicks
                        }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No hotspot clicks in this period.
                </p>
            </section>

            <section
                class="rounded-lg border bg-card p-4 shadow-sm"
                :class="report.funnel ? 'lg:col-span-2' : ''"
            >
                <h2 class="text-sm font-medium">When people visit</h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    Visits by weekday and hour ({{ report.timezone }}).
                </p>
                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[36rem] grid-cols-[2.5rem_repeat(24,minmax(0,1fr))] gap-0.5 text-[10px]"
                        role="img"
                        aria-label="Visits by weekday and hour"
                    >
                        <span></span>
                        <span
                            v-for="hour in 24"
                            :key="`h${hour}`"
                            class="text-center text-muted-foreground"
                            >{{ (hour - 1) % 6 === 0 ? hour - 1 : '' }}</span
                        >
                        <template
                            v-for="(row, dayIndex) in report.heatmap"
                            :key="dayIndex"
                        >
                            <span class="text-muted-foreground">{{
                                days[dayIndex]
                            }}</span>
                            <span
                                v-for="(count, hour) in row"
                                :key="`${dayIndex}-${hour}`"
                                class="aspect-square rounded-sm bg-primary"
                                :style="{
                                    opacity: count
                                        ? 0.15 + (count / heatMax) * 0.85
                                        : 0.05,
                                }"
                                :title="`${days[dayIndex]} ${hour}:00 · ${count} visit${count === 1 ? '' : 's'}`"
                            />
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
