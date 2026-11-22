<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { Head, router, useForm } from '@inertiajs/vue3';
import UsageMeters from '@/components/billing/UsageMeters.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, timeAgo } from '@/lib/format';
import admin from '@/routes/admin';
import type {
    CreditTransactionItem,
    EntitlementSummary,
    SubscriptionItem,
} from '@/types';

const props = defineProps<{
    account: {
        id: string;
        name: string;
        is_personal: boolean;
        domain: { host: string; status: string } | null;
        owner: { name: string; email: string };
        members: { name: string; email: string; role: string }[];
        created_at: string | null;
    };
    entitlements: EntitlementSummary;
    subscription: SubscriptionItem | null;
    history: SubscriptionItem[];
    credits: {
        balance: number;
        reserved: number;
        available: number;
        recent: CreditTransactionItem[];
    };
    plans: { value: string; label: string }[];
    statuses: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Workspaces', href: admin.workspaces.index() },
        ],
    },
});

const planForm = useForm({
    plan_id: props.entitlements.plan.id ?? '',
    status: 'active',
    period_end: '',
    notes: '',
});

const creditForm = useForm({ amount: 50, reason: '' });

function assignPlan(): void {
    planForm
        .transform((data) => ({
            ...data,
            period_end: data.period_end || null,
            notes: data.notes || null,
        }))
        .submit(admin.workspaces.subscription.store(props.account.id), {
            preserveScroll: true,
            onSuccess: () => planForm.reset('notes', 'period_end'),
        });
}

function cancelSubscription(): void {
    if (
        confirm(
            'Cancel this subscription? The workspace moves to the default plan straight away.',
        )
    ) {
        router.delete(
            admin.workspaces.subscription.destroy(props.account.id).url,
            { preserveScroll: true },
        );
    }
}

function grantCredits(): void {
    creditForm.submit(admin.workspaces.credits.store(props.account.id), {
        preserveScroll: true,
        onSuccess: () => creditForm.reset('reason'),
    });
}
</script>

<template>
    <Head :title="`${account.name} · Admin`" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="account.name"
            :description="`Owner: ${account.owner.name} (${account.owner.email}) · created ${formatDate(account.created_at)}`"
        />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <section
                    class="space-y-4 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h2 class="font-medium">
                            {{ entitlements.plan.name }} plan
                        </h2>
                        <p
                            v-if="subscription"
                            class="text-xs text-muted-foreground"
                        >
                            {{ subscription.status_label }} ·
                            {{ subscription.provider }} · renews
                            {{ formatDate(subscription.current_period_end) }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            No subscription: using the default plan
                        </p>
                    </div>
                    <UsageMeters :summary="entitlements" />
                </section>

                <section class="rounded-lg border bg-card shadow-sm">
                    <h2 class="border-b p-4 font-medium">Credit activity</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody>
                                <tr
                                    v-for="item in credits.recent"
                                    :key="item.id"
                                    class="border-b last:border-0"
                                >
                                    <td class="px-4 py-2 capitalize">
                                        {{ item.type }}
                                    </td>
                                    <td class="px-4 py-2">
                                        {{ item.description }}
                                        <span
                                            v-if="item.user"
                                            class="text-xs text-muted-foreground"
                                        >
                                            · {{ item.user }}</span
                                        >
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right tabular-nums"
                                        :class="
                                            item.amount > 0 &&
                                            item.type === 'grant'
                                                ? 'text-emerald-600'
                                                : ''
                                        "
                                    >
                                        {{
                                            item.amount > 0 &&
                                            item.type === 'grant'
                                                ? '+'
                                                : ''
                                        }}{{ item.amount }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right text-xs text-muted-foreground"
                                    >
                                        {{ timeAgo(item.created_at) }}
                                    </td>
                                </tr>
                                <tr v-if="!credits.recent.length">
                                    <td
                                        class="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        No credit activity yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-lg border bg-card shadow-sm">
                    <h2 class="border-b p-4 font-medium">Plan history</h2>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="item in history"
                            :key="item.id"
                            class="flex flex-wrap justify-between gap-2 px-4 py-2"
                        >
                            <span>
                                <span class="font-medium">{{ item.plan }}</span>
                                · {{ item.status_label }}
                                <span
                                    v-if="item.notes"
                                    class="text-muted-foreground"
                                >
                                    · {{ item.notes }}</span
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ formatDate(item.created_at) }}
                                <template v-if="item.assigned_by">
                                    by {{ item.assigned_by }}</template
                                >
                            </span>
                        </li>
                        <li
                            v-if="!history.length"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No subscriptions yet.
                        </li>
                    </ul>
                </section>
            </div>

            <aside class="space-y-6">
                <section
                    class="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="font-medium">Change plan</h2>
                    <div class="grid gap-1.5">
                        <Label for="plan">Plan</Label>
                        <NativeSelect
                            id="plan"
                            v-model="planForm.plan_id"
                            :options="plans"
                            placeholder="Choose a plan"
                        />
                        <InputError :message="planForm.errors.plan_id" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="grid gap-1.5">
                            <Label for="status">Status</Label>
                            <NativeSelect
                                id="status"
                                v-model="planForm.status"
                                :options="statuses"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="period-end">Renews on</Label>
                            <Input
                                id="period-end"
                                v-model="planForm.period_end"
                                type="date"
                            />
                        </div>
                    </div>
                    <InputError :message="planForm.errors.period_end" />
                    <div class="grid gap-1.5">
                        <Label for="notes">Note (optional)</Label>
                        <Input
                            id="notes"
                            v-model="planForm.notes"
                            maxlength="500"
                            placeholder="e.g. Paid by bank transfer, invoice 1042"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        The new plan's monthly credits are added now and at
                        every renewal. Leave the date empty for one month.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            :disabled="planForm.processing || !planForm.plan_id"
                            @click="assignPlan"
                            >Assign plan</Button
                        >
                        <Button
                            v-if="subscription"
                            size="sm"
                            variant="ghost"
                            @click="cancelSubscription"
                            >Cancel subscription</Button
                        >
                    </div>
                </section>

                <section
                    class="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="font-medium">Credits</h2>
                    <dl class="grid grid-cols-3 gap-2 text-center text-xs">
                        <div>
                            <dt class="text-muted-foreground">Balance</dt>
                            <dd class="text-base font-medium tabular-nums">
                                {{ credits.balance }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Held</dt>
                            <dd class="text-base font-medium tabular-nums">
                                {{ credits.reserved }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Available</dt>
                            <dd class="text-base font-medium tabular-nums">
                                {{ formatCredits(credits.available) }}
                            </dd>
                        </div>
                    </dl>
                    <div class="grid grid-cols-[6rem_minmax(0,1fr)] gap-2">
                        <Input
                            v-model.number="creditForm.amount"
                            type="number"
                            min="1"
                            max="100000"
                            aria-label="Credits to add"
                        />
                        <Input
                            v-model="creditForm.reason"
                            maxlength="160"
                            placeholder="Reason"
                            aria-label="Reason"
                        />
                    </div>
                    <InputError
                        :message="
                            creditForm.errors.amount ?? creditForm.errors.reason
                        "
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="creditForm.processing || !creditForm.reason"
                        @click="grantCredits"
                        >Add credits</Button
                    >
                </section>

                <section
                    v-if="account.domain"
                    class="space-y-1 rounded-lg border bg-card p-4 text-sm shadow-sm"
                >
                    <h2 class="font-medium">Custom domain</h2>
                    <p class="font-mono">{{ account.domain.host }}</p>
                    <p class="text-xs text-muted-foreground capitalize">
                        {{ account.domain.status }}
                    </p>
                </section>

                <section
                    class="space-y-2 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="font-medium">
                        Members ({{ account.members.length }})
                    </h2>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="member in account.members"
                            :key="member.email"
                            class="flex justify-between gap-2"
                        >
                            <span class="truncate">{{ member.email }}</span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground capitalize"
                                >{{ member.role }}</span
                            >
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</template>
