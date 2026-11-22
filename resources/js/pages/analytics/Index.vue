<script setup lang="ts">
import { BarChart3 as HeaderIcon } from '@lucide/vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { BarChart3, Info, Lock } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import AdvancedReport from '@/components/analytics/AdvancedReport.vue';
import VideoMetrics from '@/components/analytics/VideoMetrics.vue';
import EnquiryInbox from '@/components/analytics/EnquiryInbox.vue';
import type { Lead } from '@/components/analytics/EnquiryInbox.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { formatDate, timeAgo } from '@/lib/format';
import analytics from '@/routes/analytics';
import billing from '@/routes/billing';
import type {
    AdvancedReport as AdvancedReportData,
    Paginated,
    VideoMetrics as VideoMetricsData,
} from '@/types';

type Breakdown = { label: string; total: number }[];

const props = defineProps<{
    report: {
        views: number;
        unique_visitors: number;
        average_duration_seconds: number;
        completion_rate: number;
        hotspot_clicks: number;
        leads: number;
        embed_views: number;
        daily: { date: string; total: number }[];
        devices: Breakdown;
        countries: Breakdown;
        referrers: Breakdown;
        top_scenes: Breakdown;
    };
    advanced: AdvancedReportData | null;
    videos: VideoMetricsData | null;
    features: { advanced: boolean };
    leads: Paginated<Lead>;
    unreadLeads: number;
    tours: { id: string; title: string }[];
    filters: {
        tour: string;
        range: string;
        tab: 'overview' | 'leads' | 'videos';
    };
    retentionDays: number;
    can: { manageLeads: boolean; deleteLeads: boolean; exportLeads: boolean };
}>();

const tabs = [
    { value: 'overview', label: 'Tours' },
    { value: 'leads', label: 'Enquiries' },
    { value: 'videos', label: 'Videos' },
] as const;

const rangeOptions = computed(() => [
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 90 days' },
    ...(props.features.advanced
        ? [
              { value: '180', label: 'Last 6 months' },
              { value: '365', label: 'Last 12 months' },
          ]
        : []),
]);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Analytics', href: analytics.index() }],
    },
});

const page = usePage();
const filters = reactive({ ...props.filters });

watch(
    () => [filters.tour, filters.range, filters.tab],
    () => {
        const query = Object.fromEntries(
            Object.entries(filters).filter(
                ([key, value]) =>
                    value !== '' &&
                    !(key === 'range' && value === '30') &&
                    !(key === 'tab' && value === 'overview'),
            ),
        );

        router.get(analytics.index.url({ query }), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    },
);

function duration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

const tiles = computed(() => [
    { label: 'Views', value: props.report.views.toLocaleString() },
    {
        label: 'Unique visitors',
        value: props.report.unique_visitors.toLocaleString(),
        hint: 'Estimated',
    },
    {
        label: 'Avg. time in tour',
        value: duration(props.report.average_duration_seconds),
    },
    { label: 'Saw every scene', value: `${props.report.completion_rate}%` },
    {
        label: 'Hotspot clicks',
        value: props.report.hotspot_clicks.toLocaleString(),
    },
    { label: 'Enquiries', value: props.report.leads.toLocaleString() },
]);

const maxDaily = computed(() =>
    Math.max(1, ...props.report.daily.map((day) => day.total)),
);

const breakdowns = computed(() => [
    { title: 'Most viewed scenes', rows: props.report.top_scenes },
    { title: 'Devices', rows: props.report.devices },
    { title: 'Countries', rows: props.report.countries },
    { title: 'Referring sites', rows: props.report.referrers },
]);

function share(rows: Breakdown, total: number): number {
    const sum = rows.reduce((carry, row) => carry + row.total, 0);

    return sum === 0 ? 0 : Math.round((total / sum) * 100);
}
</script>

<template>
    <Head title="Analytics" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Analytics"
            :icon="HeaderIcon"
            tone="sky"
            description="How visitors explore your published tours, and the enquiries they send."
        >
            <template #actions>
                <NativeSelect
                    v-model="filters.tour"
                    :options="
                        tours.map((tour) => ({
                            value: tour.id,
                            label: tour.title,
                        }))
                    "
                    placeholder="All tours"
                    aria-label="Tour"
                    class="w-52"
                />
                <NativeSelect
                    v-model="filters.range"
                    :options="rangeOptions"
                    aria-label="Date range"
                    class="w-40"
                />
            </template>
        </PageHeader>

        <div class="flex border-b text-sm">
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                class="flex items-center gap-2 border-b-2 px-4 py-2.5 font-medium transition-colors"
                :class="
                    filters.tab === tab.value
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="filters.tab = tab.value"
            >
                {{ tab.label }}
                <span
                    v-if="tab.value === 'leads' && unreadLeads"
                    class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground"
                    >{{ unreadLeads }}</span
                >
            </button>
        </div>

        <template v-if="filters.tab === 'overview'">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <div
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="rounded-lg border bg-card p-4"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ tile.label }}
                    </p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">
                        {{ tile.value }}
                    </p>
                    <p v-if="tile.hint" class="text-xs text-muted-foreground">
                        {{ tile.hint }}
                    </p>
                </div>
            </div>

            <section class="rounded-lg border bg-card p-4 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-medium">Views per day</h2>
                    <span class="text-xs text-muted-foreground"
                        >{{ report.embed_views }} from embeds</span
                    >
                </div>
                <div
                    v-if="report.views"
                    class="flex h-40 items-end gap-0.5"
                    role="img"
                    :aria-label="`Views per day, ${report.views} in total`"
                >
                    <div
                        v-for="day in report.daily"
                        :key="day.date"
                        class="group relative flex-1 rounded-t bg-primary/80 transition-colors hover:bg-primary"
                        :style="{
                            height: `${Math.max(2, (day.total / maxDaily) * 100)}%`,
                        }"
                        :title="`${formatDate(day.date)}: ${day.total} view${day.total === 1 ? '' : 's'}`"
                    />
                </div>
                <EmptyState
                    v-else
                    :icon="BarChart3"
                    title="No visits in this period"
                    description="Views appear here once people open your published tours."
                />
            </section>

            <div v-if="report.views" class="grid gap-4 md:grid-cols-2">
                <section
                    v-for="breakdown in breakdowns"
                    :key="breakdown.title"
                    class="rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="mb-3 text-sm font-medium">
                        {{ breakdown.title }}
                    </h2>
                    <ul v-if="breakdown.rows.length" class="space-y-2.5">
                        <li
                            v-for="row in breakdown.rows"
                            :key="row.label"
                            class="space-y-1 text-sm"
                        >
                            <div class="flex justify-between gap-3">
                                <span class="truncate capitalize">{{
                                    row.label
                                }}</span>
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    >{{ row.total }}</span
                                >
                            </div>
                            <div
                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full bg-primary/70"
                                    :style="{
                                        width: `${share(breakdown.rows, row.total)}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        No data yet.
                    </p>
                </section>
            </div>

            <AdvancedReport
                v-if="advanced"
                :report="advanced"
                :filters="filters"
                :can-export-leads="can.exportLeads"
            />
            <section
                v-else-if="!features.advanced"
                class="flex flex-col items-start gap-3 rounded-lg border bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex gap-3">
                    <Lock
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <div>
                        <p class="text-sm font-medium">Advanced analytics</p>
                        <p class="text-sm text-muted-foreground">
                            Compare periods, see each tour's conversion rate,
                            where visitors drop off, which hotspots get clicked
                            and when people visit, plus 12-month history and CSV
                            export.
                        </p>
                    </div>
                </div>
                <Button
                    v-if="page.props.workspace?.can?.manage"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="billing.index()">See plans</Link>
                </Button>
            </section>

            <p class="flex gap-2 text-xs text-muted-foreground">
                <Info class="mt-0.5 size-3.5 shrink-0" />
                Visitors are anonymous: no names or IP addresses are stored and
                browsers sending Do Not Track are not counted, so unique
                visitors is an estimate. Visit data is kept for
                {{ retentionDays }} days. Countries appear only when your
                hosting provides them.
            </p>
        </template>

        <template v-else-if="filters.tab === 'videos'">
            <VideoMetrics v-if="videos" :metrics="videos" />
        </template>

        <EnquiryInbox v-else :leads="leads" :filters="filters" :can="can" />
    </div>
</template>
