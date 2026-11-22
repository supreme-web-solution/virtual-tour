<script setup lang="ts">
import { Gauge as HeaderIcon } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import admin from '@/routes/admin';

defineProps<{
    totals: {
        users: number;
        workspaces: number;
        properties: number;
        tours: number;
        published_tours: number;
        videos: number;
        active_generations: number;
        failed_jobs: number;
        new_users: number;
    };
    usage: {
        provider: string;
        type: string;
        total: number;
        completed: number;
        failed: number;
        credits: number;
        cost: number;
    }[];
    credits: { granted: number; charged: number };
    plans: { name: string; workspaces: number }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin', href: admin.dashboard() }],
    },
});

const money = (value: number): string =>
    new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
    }).format(value);
</script>

<template>
    <Head title="Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Platform overview"
            :icon="HeaderIcon"
            tone="sky"
            description="Totals across every workspace. Usage and credits cover the last 30 days."
        />

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                :href="admin.users.index()"
                class="rounded-lg border p-4 hover:border-foreground/20"
            >
                <p class="text-sm text-muted-foreground">Users</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ totals.users.toLocaleString() }}
                </p>
                <p class="text-xs text-muted-foreground">
                    +{{ totals.new_users }} in 30 days
                </p>
            </Link>
            <Link
                :href="admin.workspaces.index()"
                class="rounded-lg border p-4 hover:border-foreground/20"
            >
                <p class="text-sm text-muted-foreground">Workspaces</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ totals.workspaces.toLocaleString() }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ totals.properties.toLocaleString() }} properties
                </p>
            </Link>
            <Link
                :href="admin.content.index()"
                class="rounded-lg border p-4 hover:border-foreground/20"
            >
                <p class="text-sm text-muted-foreground">Tours</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ totals.tours.toLocaleString() }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ totals.published_tours.toLocaleString() }} published ·
                    {{ totals.videos.toLocaleString() }} videos
                </p>
            </Link>
            <Link
                :href="admin.jobs.index()"
                class="rounded-lg border p-4 hover:border-foreground/20"
            >
                <p class="text-sm text-muted-foreground">Queue health</p>
                <p
                    class="text-2xl font-semibold tabular-nums"
                    :class="totals.failed_jobs ? 'text-red-600' : ''"
                >
                    {{ totals.failed_jobs }} failed
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ totals.active_generations }} generations running
                </p>
            </Link>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <section class="rounded-lg border bg-card shadow-sm">
                <div class="flex items-center justify-between border-b p-4">
                    <h2 class="font-medium">Provider usage</h2>
                    <Link
                        :href="admin.generations.index()"
                        class="text-sm text-muted-foreground hover:text-foreground"
                        >All generations</Link
                    >
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium">Provider</th>
                                <th class="px-4 py-2 font-medium">Operation</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Requests
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Failed
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Credits
                                </th>
                                <th class="px-4 py-2 text-right font-medium">
                                    Provider cost
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in usage"
                                :key="row.provider + row.type"
                                class="border-b last:border-0"
                            >
                                <td class="px-4 py-2 capitalize">
                                    {{ row.provider }}
                                </td>
                                <td class="px-4 py-2">{{ row.type }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ row.total }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :class="row.failed ? 'text-red-600' : ''"
                                >
                                    {{ row.failed }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ row.credits }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ money(row.cost) }}
                                </td>
                            </tr>
                            <tr v-if="!usage.length">
                                <td
                                    colspan="6"
                                    class="px-4 py-8 text-center text-muted-foreground"
                                >
                                    No AI requests in the last 30 days.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="space-y-6">
                <section
                    class="space-y-2 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="font-medium">Credits (30 days)</h2>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Granted</span>
                        <span class="tabular-nums">{{
                            credits.granted.toLocaleString()
                        }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Spent</span>
                        <span class="tabular-nums">{{
                            credits.charged.toLocaleString()
                        }}</span>
                    </div>
                    <Link
                        :href="admin.credits.index()"
                        class="block pt-1 text-sm text-muted-foreground hover:text-foreground"
                        >Transactions</Link
                    >
                </section>
                <section
                    class="space-y-2 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="font-medium">Workspaces by plan</h2>
                    <div
                        v-for="plan in plans"
                        :key="plan.name"
                        class="flex justify-between text-sm"
                    >
                        <span class="text-muted-foreground">{{
                            plan.name
                        }}</span>
                        <span class="tabular-nums">{{ plan.workspaces }}</span>
                    </div>
                    <p
                        v-if="!plans.length"
                        class="text-sm text-muted-foreground"
                    >
                        No plans configured, so nothing is limited.
                    </p>
                    <Link
                        :href="admin.plans.index()"
                        class="block pt-1 text-sm text-muted-foreground hover:text-foreground"
                        >Manage plans</Link
                    >
                </section>
            </div>
        </div>
    </div>
</template>
