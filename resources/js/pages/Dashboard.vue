<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    CreditCard,
    Pencil,
    Trophy,
    Upload,
    ArrowDownRight,
    ArrowUpRight,
    Clock,
    Eye,
    Globe,
    Inbox,
    Loader2,
    MousePointerClick,
    Orbit,
    Plus,
    Sparkles,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import AvatarChip from '@/components/AvatarChip.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useCreateTour } from '@/composables/useCreateTour';
import { accents } from '@/lib/accent';
import { timeAgo } from '@/lib/format';
import { dashboard } from '@/routes';
import analyticsRoutes from '@/routes/analytics';
import billing from '@/routes/billing';
import tours from '@/routes/tours';
import type { TourListItem } from '@/types';

type Metrics = {
    views: number;
    unique_visitors: number;
    average_duration_seconds: number;
    completion_rate: number;
    leads: number;
    hotspot_clicks: number;
};

/**
 * Home: how your tours are doing (last 30 days against the 30 before),
 * what needs attention, and a way in to create a tour from anywhere.
 */
const props = defineProps<{
    stats: {
        properties: number;
        media: number;
        photos: number;
        panoramas: number;
        tours: number;
        published_tours: number;
        scenes: number;
    };
    insights: {
        days: number;
        current: Metrics;
        previous: Metrics;
        daily: { date: string; total: number }[];
    };
    topTours: { id: string; title: string; views: number }[];
    recentLeads: {
        id: string;
        name: string;
        email: string;
        tour: string | null;
        is_read: boolean;
        created_at: string | null;
    }[];
    unreadLeads: number;
    plan: { name: string; credits: number };
    building: { id: string; title: string; stage: string }[];
    recentTours: TourListItem[];
    activity: {
        id: number;
        description: string;
        user: string | null;
        created_at: string;
    }[];
    can: { createProperty: boolean; uploadMedia: boolean; createTour: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Home', href: dashboard() }],
    },
});

const page = usePage();
const { openCreateTour } = useCreateTour();

const stageLabel: Record<string, string> = {
    planning: 'Planning…',
    analysing: 'Reading photos…',
    importing: 'Importing…',
    generating: 'Generating…',
    failed: 'Needs attention',
};

/** An icon and tint for an activity line, by what it says happened. */
function activityStyle(description: string): {
    icon: Component;
    tone: string;
} {
    const text = description.toLowerCase();

    if (text.startsWith('upload')) {
        return { icon: Upload, tone: accents.sky };
    }

    if (text.startsWith('publish')) {
        return { icon: Globe, tone: accents.emerald };
    }

    if (text.startsWith('update') || text.startsWith('edit')) {
        return { icon: Pencil, tone: accents.amber };
    }

    if (text.startsWith('creat') || text.startsWith('add')) {
        return { icon: Plus, tone: accents.violet };
    }

    return { icon: Activity, tone: accents.rose };
}

function duration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
}

const cards = computed(() => {
    const { current, previous } = props.insights;

    return [
        {
            key: 'views',
            accent: 'sky' as const,
            label: 'Views',
            icon: Eye,
            value: String(current.views),
            now: current.views,
            before: previous.views,
        },
        {
            key: 'visitors',
            accent: 'violet' as const,
            label: 'Unique visitors',
            icon: Users,
            value: String(current.unique_visitors),
            now: current.unique_visitors,
            before: previous.unique_visitors,
        },
        {
            key: 'time',
            accent: 'amber' as const,
            label: 'Avg. time in tour',
            icon: Clock,
            value: duration(current.average_duration_seconds),
            now: current.average_duration_seconds,
            before: previous.average_duration_seconds,
        },
        {
            key: 'leads',
            accent: 'emerald' as const,
            label: 'Enquiries',
            icon: Inbox,
            value: String(current.leads),
            now: current.leads,
            before: previous.leads,
        },
    ].map((card) => ({ ...card, change: change(card.now, card.before) }));
});

/**
 * Percent change against the earlier period; null when there is nothing to compare.
 */
function change(now: number, before: number): number | null {
    if (before === 0) {
        return now === 0 ? 0 : null;
    }

    return Math.round(((now - before) / before) * 100);
}

/**
 * Views chart: plain SVG for the grid and curve (drawn in a 0-100 box and
 * stretched), HTML for labels and the hover marker so text never distorts.
 */
function smoothPath(points: { x: number; y: number }[]): string {
    const n = points.length;

    if (n === 0) {
        return '';
    }

    if (n === 1) {
        return `M${points[0].x},${points[0].y}`;
    }

    const slopes: number[] = [];

    for (let i = 0; i < n - 1; i++) {
        slopes.push(
            (points[i + 1].y - points[i].y) / (points[i + 1].x - points[i].x),
        );
    }

    const tangents = points.map((_, i) => {
        if (i === 0) {
            return slopes[0];
        }

        if (i === n - 1) {
            return slopes[n - 2];
        }

        return slopes[i - 1] * slopes[i] <= 0
            ? 0
            : (slopes[i - 1] + slopes[i]) / 2;
    });

    for (let i = 0; i < n - 1; i++) {
        if (slopes[i] === 0) {
            tangents[i] = 0;
            tangents[i + 1] = 0;
            continue;
        }

        const a = tangents[i] / slopes[i];
        const b = tangents[i + 1] / slopes[i];
        const s = a * a + b * b;

        if (s > 9) {
            const tau = 3 / Math.sqrt(s);
            tangents[i] = tau * a * slopes[i];
            tangents[i + 1] = tau * b * slopes[i];
        }
    }

    let d = `M${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`;

    for (let i = 0; i < n - 1; i++) {
        const dx = (points[i + 1].x - points[i].x) / 3;
        d += ` C${(points[i].x + dx).toFixed(2)},${(points[i].y + tangents[i] * dx).toFixed(2)} ${(points[i + 1].x - dx).toFixed(2)},${(points[i + 1].y - tangents[i + 1] * dx).toFixed(2)} ${points[i + 1].x.toFixed(2)},${points[i + 1].y.toFixed(2)}`;
    }

    return d;
}

const chart = computed(() => {
    const data = props.insights.daily;
    const empty = data.every((day) => day.total === 0);
    const peak = Math.max(0, ...data.map((day) => day.total));
    const step = Math.max(1, Math.ceil((empty ? 4 : peak) / 4));
    const max = step * 4;
    const points = data.map((day, index) => ({
        ...day,
        x: data.length > 1 ? (index / (data.length - 1)) * 100 : 50,
        y: 100 - (day.total / max) * 100,
    }));
    const line = smoothPath(points);
    const labelEvery = Math.max(1, Math.round(data.length / 5));

    return {
        max,
        peak,
        line,
        area: points.length ? `${line} L100,100 L0,100 Z` : '',
        points,
        empty,
        ticks: [4, 3, 2, 1, 0].map((index) => index * step),
        labels: points.filter(
            (_, index) =>
                index % labelEvery === 0 &&
                index <= points.length - labelEvery / 2,
        ),
    };
});

const hovered = ref<number | null>(null);
const hoveredPoint = computed(() =>
    hovered.value === null ? null : (chart.value.points[hovered.value] ?? null),
);

function onChartMove(event: MouseEvent): void {
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const ratio = (event.clientX - box.left) / box.width;

    hovered.value = Math.min(
        chart.value.points.length - 1,
        Math.max(0, Math.round(ratio * (chart.value.points.length - 1))),
    );
}
const topMax = computed(() =>
    Math.max(1, ...props.topTours.map((tour) => tour.views)),
);

function shortDate(iso: string): string {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
</script>

<template>
    <Head title="Home" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="`Welcome back${
                page.props.auth.user?.name
                    ? `, ${String(page.props.auth.user.name).split(' ')[0]}`
                    : ''
            }`"
            :description="`${page.props.workspace?.name ?? ''} · Explore, build and share immersive 360° tours.`"
        >
            <template #actions>
                <Button v-if="recentTours[0]" as-child variant="outline">
                    <Link :href="tours.edit(recentTours[0].id)">
                        Open latest tour
                    </Link>
                </Button>
                <Button v-if="can.createTour" @click="openCreateTour()">
                    <Plus /> New tour
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="!page.props.features.media_uploads"
            class="flex gap-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <p>
                Media storage isn't configured, so uploads are off. Add the
                Cloudinary keys to the server's
                <code class="font-mono text-xs">.env</code>.
            </p>
        </div>

        <!-- Headline numbers -->
        <section
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
            aria-label="Key numbers"
        >
            <div
                v-for="card in cards"
                :key="card.key"
                class="stat360 rounded-2xl border p-4 shadow-sm"
            >
                <svg
                    class="stat360__orb"
                    viewBox="0 0 120 120"
                    fill="none"
                    stroke="currentColor"
                    aria-hidden="true"
                >
                    <g class="stat360__ring">
                        <circle cx="60" cy="60" r="56" stroke-dasharray="2 5" />
                    </g>
                    <g class="stat360__ring stat360__ring--reverse">
                        <ellipse cx="60" cy="60" rx="46" ry="16" />
                        <ellipse
                            cx="60"
                            cy="60"
                            rx="46"
                            ry="16"
                            transform="rotate(60 60 60)"
                        />
                        <ellipse
                            cx="60"
                            cy="60"
                            rx="46"
                            ry="16"
                            transform="rotate(120 60 60)"
                        />
                        <circle cx="106" cy="60" r="3" fill="currentColor" />
                    </g>
                </svg>
                <div
                    class="flex items-center justify-between text-muted-foreground"
                >
                    <p class="text-xs font-medium">{{ card.label }}</p>
                    <span
                        class="mr-10 grid size-7 place-items-center rounded-full"
                        :class="accents[card.accent]"
                        ><component :is="card.icon" class="size-3.5"
                    /></span>
                </div>
                <p
                    class="mt-2 text-2xl font-semibold tracking-tight tabular-nums"
                >
                    {{ card.value }}
                </p>
                <p class="mt-1 flex items-center gap-1 text-xs">
                    <template v-if="card.change === null">
                        <span class="text-muted-foreground"
                            >New this period</span
                        >
                    </template>
                    <template v-else-if="card.change === 0">
                        <span class="text-muted-foreground">No change</span>
                    </template>
                    <template v-else>
                        <component
                            :is="
                                card.change > 0 ? ArrowUpRight : ArrowDownRight
                            "
                            class="size-3.5"
                            :class="
                                card.change > 0
                                    ? 'text-emerald-600'
                                    : 'text-red-600'
                            "
                        />
                        <span
                            :class="
                                card.change > 0
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-red-700 dark:text-red-300'
                            "
                        >
                            {{ Math.abs(card.change) }}%
                        </span>
                        <span class="text-muted-foreground"
                            >vs previous {{ insights.days }} days</span
                        >
                    </template>
                </p>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
            <div class="min-w-0 space-y-6">
                <!-- Views chart -->
                <section class="rounded-2xl border bg-card p-4 shadow-sm">
                    <div class="mb-3 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-medium">Views</h2>
                            <p class="text-xs text-muted-foreground">
                                {{ insights.current.hotspot_clicks }} hotspot
                                clicks · {{ insights.current.completion_rate }}%
                                watched every scene
                            </p>
                        </div>
                        <Link
                            :href="analyticsRoutes.index()"
                            class="text-xs text-muted-foreground hover:text-foreground"
                            >Full analytics</Link
                        >
                    </div>

                    <div class="flex gap-3">
                        <div
                            class="flex h-56 flex-col justify-between text-right text-xs text-muted-foreground tabular-nums"
                            aria-hidden="true"
                        >
                            <span
                                v-for="tick in chart.ticks"
                                :key="tick"
                                class="-my-1.5 leading-none"
                                >{{ tick }}</span
                            >
                        </div>

                        <div class="min-w-0 flex-1">
                            <div
                                class="relative h-56"
                                @mousemove="onChartMove"
                                @mouseleave="hovered = null"
                            >
                                <svg
                                    viewBox="0 0 100 100"
                                    class="absolute inset-0 size-full text-primary"
                                    preserveAspectRatio="none"
                                    role="img"
                                    :aria-label="`Daily views over the last ${insights.days} days, peaking at ${chart.peak}`"
                                >
                                    <defs>
                                        <linearGradient
                                            id="views-fill"
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="1"
                                        >
                                            <stop
                                                offset="0%"
                                                stop-color="currentColor"
                                                stop-opacity="0.28"
                                            />
                                            <stop
                                                offset="100%"
                                                stop-color="currentColor"
                                                stop-opacity="0"
                                            />
                                        </linearGradient>
                                    </defs>
                                    <line
                                        v-for="tick in 5"
                                        :key="tick"
                                        x1="0"
                                        x2="100"
                                        :y1="(tick - 1) * 25"
                                        :y2="(tick - 1) * 25"
                                        class="text-border"
                                        stroke="currentColor"
                                        stroke-width="1"
                                        :stroke-dasharray="
                                            tick === 5 ? undefined : '3 4'
                                        "
                                        vector-effect="non-scaling-stroke"
                                    />
                                    <template v-if="!chart.empty">
                                        <path
                                            :d="chart.area"
                                            fill="url(#views-fill)"
                                        />
                                        <path
                                            :d="chart.line"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2.5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            vector-effect="non-scaling-stroke"
                                        />
                                    </template>
                                    <line
                                        v-else
                                        x1="0"
                                        x2="100"
                                        y1="100"
                                        y2="100"
                                        stroke="currentColor"
                                        stroke-width="2.5"
                                        stroke-linecap="round"
                                        vector-effect="non-scaling-stroke"
                                    />
                                </svg>

                                <p
                                    v-if="chart.empty"
                                    class="absolute inset-x-0 top-1/2 -translate-y-1/2 text-center text-sm text-muted-foreground"
                                >
                                    No views yet. Publish a tour and share its
                                    link to see traffic here.
                                </p>

                                <template v-if="hoveredPoint && !chart.empty">
                                    <div
                                        class="pointer-events-none absolute inset-y-0 w-px bg-primary/40"
                                        :style="{ left: `${hoveredPoint.x}%` }"
                                    />
                                    <div
                                        class="pointer-events-none absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-primary bg-card"
                                        :style="{
                                            left: `${hoveredPoint.x}%`,
                                            top: `${hoveredPoint.y}%`,
                                        }"
                                    />
                                    <div
                                        class="pointer-events-none absolute z-10 -translate-y-full rounded-lg bg-[#0a1029] px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg"
                                        :class="
                                            hoveredPoint.x > 80
                                                ? '-translate-x-full'
                                                : hoveredPoint.x < 20
                                                  ? ''
                                                  : '-translate-x-1/2'
                                        "
                                        :style="{
                                            left: `${hoveredPoint.x}%`,
                                            top: `calc(${hoveredPoint.y}% - 10px)`,
                                        }"
                                    >
                                        <span class="font-semibold">{{
                                            hoveredPoint.total
                                        }}</span>
                                        {{
                                            hoveredPoint.total === 1
                                                ? 'view'
                                                : 'views'
                                        }}
                                        <span class="text-slate-400">{{
                                            shortDate(hoveredPoint.date)
                                        }}</span>
                                    </div>
                                </template>
                            </div>

                            <div
                                class="relative mt-2 h-4 text-xs text-muted-foreground"
                                aria-hidden="true"
                            >
                                <span
                                    v-for="label in chart.labels"
                                    :key="label.date"
                                    class="absolute -translate-x-1/2 first:translate-x-0"
                                    :style="{ left: `${label.x}%` }"
                                    >{{ shortDate(label.date) }}</span
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Top tours + enquiries -->
                <div class="grid gap-6 md:grid-cols-2">
                    <section class="rounded-2xl border bg-card p-4 shadow-sm">
                        <h2
                            class="mb-3 flex items-center gap-2 text-sm font-medium"
                        >
                            <span
                                class="grid size-7 place-items-center rounded-lg"
                                :class="accents.amber"
                                ><Trophy class="size-3.5"
                            /></span>
                            Top tours
                        </h2>
                        <ol v-if="topTours.length" class="space-y-3">
                            <li v-for="(tour, rank) in topTours" :key="tour.id">
                                <Link :href="tours.edit(tour.id)" class="block">
                                    <div
                                        class="flex items-center justify-between gap-2 text-sm"
                                    >
                                        <span
                                            class="flex min-w-0 items-center gap-2"
                                        >
                                            <span
                                                class="grid size-5 shrink-0 place-items-center rounded-full text-[11px] font-semibold"
                                                :class="
                                                    accents[
                                                        (
                                                            [
                                                                'amber',
                                                                'sky',
                                                                'violet',
                                                            ] as const
                                                        )[rank % 3]
                                                    ]
                                                "
                                                >{{ rank + 1 }}</span
                                            >
                                            <span class="truncate">{{
                                                tour.title
                                            }}</span>
                                        </span>
                                        <span
                                            class="text-muted-foreground tabular-nums"
                                            >{{ tour.views }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            class="h-full rounded-full bg-primary"
                                            :style="{
                                                width: `${(tour.views / topMax) * 100}%`,
                                            }"
                                        />
                                    </div>
                                </Link>
                            </li>
                        </ol>
                        <p v-else class="py-4 text-sm text-muted-foreground">
                            Visits will rank your tours here.
                        </p>
                    </section>

                    <section class="rounded-2xl border bg-card p-4 shadow-sm">
                        <div class="mb-3 flex items-center justify-between">
                            <h2
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <span
                                    class="grid size-7 place-items-center rounded-lg"
                                    :class="accents.violet"
                                    ><Inbox class="size-3.5"
                                /></span>
                                Latest enquiries
                                <span
                                    v-if="unreadLeads"
                                    class="ml-1 rounded-full bg-primary px-1.5 py-0.5 text-[10px] text-primary-foreground"
                                    >{{ unreadLeads }} new</span
                                >
                            </h2>
                            <Link
                                :href="
                                    analyticsRoutes.index({
                                        query: { tab: 'leads' },
                                    })
                                "
                                class="text-xs text-muted-foreground hover:text-foreground"
                                >View all</Link
                            >
                        </div>
                        <ul v-if="recentLeads.length" class="space-y-3">
                            <li
                                v-for="lead in recentLeads"
                                :key="lead.id"
                                class="flex items-center gap-3 text-sm"
                            >
                                <AvatarChip
                                    :name="lead.name"
                                    :seed="lead.email"
                                    size="sm"
                                />
                                <div class="min-w-0">
                                    <p
                                        class="flex items-center gap-1.5 truncate"
                                    >
                                        <span
                                            v-if="!lead.is_read"
                                            class="size-1.5 shrink-0 rounded-full bg-primary"
                                        />
                                        <span class="truncate font-medium">{{
                                            lead.name
                                        }}</span>
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ lead.tour ?? 'Tour' }} ·
                                        {{ timeAgo(lead.created_at) }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="py-4 text-sm text-muted-foreground">
                            Enquiries from your tours will land here.
                        </p>
                    </section>
                </div>

                <!-- Recent tours -->
                <section class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-medium">Recent tours</h2>
                        <Link
                            v-if="recentTours.length"
                            :href="tours.index()"
                            class="text-xs text-muted-foreground hover:text-foreground"
                            >View all {{ stats.tours }}</Link
                        >
                    </div>

                    <div
                        v-if="recentTours.length"
                        class="grid gap-3 sm:grid-cols-2"
                    >
                        <Link
                            v-for="tour in recentTours"
                            :key="tour.id"
                            :href="tours.edit(tour.id)"
                            class="group flex gap-3 rounded-lg border bg-card p-2.5 transition-colors hover:border-foreground/20"
                        >
                            <div
                                class="relative h-16 w-24 shrink-0 overflow-hidden rounded-md bg-muted"
                            >
                                <img
                                    v-if="tour.cover_url"
                                    :src="tour.cover_url"
                                    :alt="tour.title"
                                    class="size-full object-cover"
                                    loading="lazy"
                                />
                                <Orbit
                                    v-else
                                    class="m-5 size-6 text-muted-foreground/50"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ tour.title }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ tour.scenes_count }} scene{{
                                        tour.scenes_count === 1 ? '' : 's'
                                    }}
                                    <template v-if="tour.property">
                                        · {{ tour.property.name }}</template
                                    >
                                </p>
                                <p class="mt-1 flex items-center gap-1 text-xs">
                                    <template v-if="tour.build_stage">
                                        <TriangleAlert
                                            v-if="tour.build_stage === 'failed'"
                                            class="size-3 text-red-600"
                                        />
                                        <Loader2
                                            v-else
                                            class="size-3 animate-spin text-sky-600"
                                        />
                                        <span
                                            :class="
                                                tour.build_stage === 'failed'
                                                    ? 'text-red-600'
                                                    : 'text-sky-700 dark:text-sky-300'
                                            "
                                            >{{
                                                stageLabel[tour.build_stage]
                                            }}</span
                                        >
                                    </template>
                                    <template
                                        v-else-if="tour.status === 'published'"
                                    >
                                        <Globe
                                            class="size-3 text-emerald-600"
                                        />
                                        <span
                                            class="text-emerald-700 dark:text-emerald-300"
                                            >Published</span
                                        >
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >Draft ·
                                        {{ timeAgo(tour.updated_at) }}</span
                                    >
                                </p>
                            </div>
                        </Link>
                    </div>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 rounded-2xl border bg-card p-8 text-center shadow-sm"
                    >
                        <Sparkles class="size-6 text-muted-foreground" />
                        <p class="text-sm text-muted-foreground">
                            Your tours will appear here.
                        </p>
                        <Button v-if="can.createTour" @click="openCreateTour()"
                            ><Plus /> Create your first tour</Button
                        >
                    </div>
                </section>
            </div>

            <!-- Side column -->
            <aside class="min-w-0 space-y-6">
                <section class="rounded-2xl border bg-card p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="flex items-center gap-2 text-sm font-medium">
                            <span
                                class="grid size-7 place-items-center rounded-lg"
                                :class="accents.emerald"
                                ><CreditCard class="size-3.5"
                            /></span>
                            Plan &amp; credits
                        </h2>
                        <Link
                            :href="billing.index()"
                            class="text-xs text-muted-foreground hover:text-foreground"
                            >Manage</Link
                        >
                    </div>
                    <p class="mt-3 text-2xl font-semibold tabular-nums">
                        {{ formatCredits(plan.credits) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        AI credits available · {{ plan.name }} plan
                    </p>
                    <dl
                        class="mt-4 grid grid-cols-2 gap-3 border-t pt-3 text-sm"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">Tours</dt>
                            <dd class="font-medium tabular-nums">
                                {{ stats.tours }}
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >({{ stats.published_tours }} live)</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Scenes
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ stats.scenes }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Properties
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{ stats.properties }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Media</dt>
                            <dd class="font-medium tabular-nums">
                                {{ stats.media }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    v-if="building.length"
                    class="rounded-2xl border bg-card p-4 shadow-sm"
                >
                    <h2 class="mb-3 text-sm font-medium">Being built</h2>
                    <ul class="space-y-2">
                        <li v-for="item in building" :key="item.id">
                            <Link
                                :href="tours.edit(item.id)"
                                class="flex items-center gap-2 text-sm"
                            >
                                <TriangleAlert
                                    v-if="item.stage === 'failed'"
                                    class="size-4 shrink-0 text-red-600"
                                />
                                <Loader2
                                    v-else
                                    class="size-4 shrink-0 animate-spin text-sky-600"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    item.title
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    stageLabel[item.stage]
                                }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="rounded-2xl border bg-card p-4 shadow-sm">
                    <h2
                        class="mb-3 flex items-center gap-2 text-sm font-medium"
                    >
                        <span
                            class="grid size-7 place-items-center rounded-lg"
                            :class="accents.rose"
                            ><Activity class="size-3.5"
                        /></span>
                        Recent activity
                    </h2>
                    <ol v-if="activity.length" class="space-y-3">
                        <li
                            v-for="entry in activity"
                            :key="entry.id"
                            class="flex items-center gap-3 text-sm"
                        >
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-full"
                                :class="activityStyle(entry.description).tone"
                                ><component
                                    :is="activityStyle(entry.description).icon"
                                    class="size-4"
                            /></span>
                            <div class="min-w-0">
                                <p class="truncate">{{ entry.description }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="entry.user"
                                        >{{ entry.user }} · </template
                                    >{{ timeAgo(entry.created_at) }}
                                </p>
                            </div>
                        </li>
                    </ol>
                    <p v-else class="text-sm text-muted-foreground">
                        Nothing yet. Activity in this workspace will appear
                        here.
                    </p>
                </section>

                <p
                    class="flex items-center gap-2 px-1 text-xs text-muted-foreground"
                >
                    <MousePointerClick class="size-3.5" /> Numbers update as
                    visitors open your published tours.
                </p>
            </aside>
        </div>
    </div>
</template>
