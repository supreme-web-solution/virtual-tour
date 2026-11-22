<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { CreditCard as HeaderIcon } from '@lucide/vue';
import { Head } from '@inertiajs/vue3';
import { Check, Minus } from '@lucide/vue';
import UsageMeters from '@/components/billing/UsageMeters.vue';
import PageHeader from '@/components/PageHeader.vue';
import { formatEntitlement, formatPlanPrice } from '@/lib/billing';
import { formatDate, timeAgo } from '@/lib/format';
import billing from '@/routes/billing';
import type { EntitlementSummary } from '@/types';

defineProps<{
    summary: EntitlementSummary;
    subscription: {
        status: string;
        status_label: string;
        current_period_end: string | null;
        auto_renew: boolean;
    } | null;
    credits: {
        balance: number;
        reserved: number;
        available: number;
        recent: {
            id: number;
            type: string;
            amount: number;
            description: string;
            created_at: string;
        }[];
    };
    plans: {
        id: string;
        name: string;
        description: string | null;
        price_monthly: number | null;
        currency: string;
        is_current: boolean;
        limits: {
            key: string;
            label: string;
            value: number | boolean | null;
        }[];
    }[];
    contactEmail: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Plan & usage', href: billing.index() }],
    },
});

/** "Videos per month" reads as "videos per month" after a number; "AI credits" keeps its acronym. */
function lowerFirst(label: string): string {
    return /^[A-Z][a-z]/.test(label)
        ? label.charAt(0).toLowerCase() + label.slice(1)
        : label;
}
</script>

<template>
    <Head title="Plan & usage" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Plan & usage"
            :icon="HeaderIcon"
            tone="emerald"
            description="What your workspace's plan includes, how much of it you use, and your AI credits."
        />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <section class="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="text-lg font-semibold">
                        {{ summary.plan.name }} plan
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            formatPlanPrice(
                                summary.plan.price_monthly,
                                summary.plan.currency,
                            )
                        }}<template v-if="summary.plan.price_monthly">
                            / month</template
                        >
                        <template v-if="subscription?.current_period_end">
                            · credits renew
                            {{ formatDate(subscription.current_period_end) }}
                        </template>
                    </p>
                </div>
                <p
                    v-if="subscription?.status === 'past_due'"
                    class="rounded-md bg-amber-50 p-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    Your payment is past due. Contact us to keep your plan.
                </p>
                <UsageMeters :summary="summary" />
            </section>

            <section class="space-y-3 rounded-lg border bg-card p-4 shadow-sm">
                <h2 class="font-medium">AI credits</h2>
                <p class="text-3xl font-semibold tabular-nums">
                    {{ formatCredits(credits.available) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    available<template v-if="credits.reserved">
                        · {{ credits.reserved }} held for running
                        generations</template
                    >
                </p>
                <ul class="divide-y border-t text-sm">
                    <li
                        v-for="item in credits.recent"
                        :key="item.id"
                        class="flex justify-between gap-2 py-2"
                    >
                        <span class="min-w-0">
                            <span class="block truncate">{{
                                item.description
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                timeAgo(item.created_at)
                            }}</span>
                        </span>
                        <span
                            class="shrink-0 tabular-nums"
                            :class="item.amount > 0 ? 'text-emerald-600' : ''"
                        >
                            {{ item.amount > 0 ? '+' : '' }}{{ item.amount }}
                        </span>
                    </li>
                    <li
                        v-if="!credits.recent.length"
                        class="py-4 text-center text-muted-foreground"
                    >
                        No credit activity yet.
                    </li>
                </ul>
            </section>
        </div>

        <section v-if="plans.length" class="space-y-4">
            <div>
                <h2 class="font-medium">Plans</h2>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="plan in plans"
                    :key="plan.id"
                    class="flex flex-col gap-3 rounded-lg border bg-card p-4 shadow-sm"
                    :class="
                        plan.is_current
                            ? 'border-primary ring-1 ring-primary'
                            : ''
                    "
                >
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-medium">{{ plan.name }}</h3>
                            <span
                                v-if="plan.is_current"
                                class="rounded-full bg-primary px-2 py-0.5 text-[11px] text-primary-foreground"
                                >Current</span
                            >
                        </div>
                        <p class="text-xl font-semibold">
                            {{
                                formatPlanPrice(
                                    plan.price_monthly,
                                    plan.currency,
                                )
                            }}<span
                                v-if="plan.price_monthly"
                                class="text-sm font-normal text-muted-foreground"
                            >
                                / month</span
                            >
                        </p>
                        <p
                            v-if="plan.description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ plan.description }}
                        </p>
                    </div>
                    <ul class="space-y-1.5 text-sm">
                        <li
                            v-for="limit in plan.limits"
                            :key="limit.key"
                            class="flex items-start gap-2"
                            :class="
                                limit.value === false
                                    ? 'text-muted-foreground'
                                    : ''
                            "
                        >
                            <Minus
                                v-if="limit.value === false"
                                class="mt-0.5 size-4 shrink-0"
                            />
                            <Check
                                v-else
                                class="mt-0.5 size-4 shrink-0 text-emerald-600"
                            />
                            <span v-if="typeof limit.value === 'boolean'">{{
                                limit.label
                            }}</span>
                            <span v-else
                                >{{ formatEntitlement(limit.key, limit.value) }}
                                {{ lowerFirst(limit.label) }}</span
                            >
                        </li>
                    </ul>
                </article>
            </div>
        </section>
    </div>
</template>
