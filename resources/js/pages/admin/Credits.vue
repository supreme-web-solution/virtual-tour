<script setup lang="ts">
import { Coins as HeaderIcon } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { useListFilters } from '@/composables/useListFilters';
import { timeAgo } from '@/lib/format';
import admin from '@/routes/admin';
import type { CreditTransactionItem, Paginated } from '@/types';

const props = defineProps<{
    transactions: Paginated<CreditTransactionItem>;
    types: string[];
    filters: { type: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Credit transactions', href: admin.credits.index() },
        ],
    },
});

const filters = useListFilters(
    { ...props.filters },
    admin.credits.index.url,
    0,
);
</script>

<template>
    <Head title="Credits · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Credit transactions"
            :icon="HeaderIcon"
            tone="amber"
            description="Grants add credits; generations reserve them, then charge on success or release on failure."
        />

        <NativeSelect
            v-model="filters.type"
            :options="
                types.map((type) => ({
                    value: type,
                    label: type.charAt(0).toUpperCase() + type.slice(1),
                }))
            "
            placeholder="All types"
            aria-label="Transaction type"
            class="w-44"
        />

        <div class="overflow-x-auto rounded-lg border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">Type</th>
                        <th class="px-4 py-2 font-medium">Workspace</th>
                        <th class="px-4 py-2 font-medium">Description</th>
                        <th class="px-4 py-2 text-right font-medium">Amount</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Balance after
                        </th>
                        <th class="px-4 py-2 font-medium">When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in transactions.data"
                        :key="item.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-2 capitalize">{{ item.type }}</td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="item.workspace"
                                :href="admin.workspaces.show(item.workspace.id)"
                                class="hover:underline"
                                >{{ item.workspace.name }}</Link
                            >
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
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ item.amount }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ item.balance_after }}
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ timeAgo(item.created_at) }}
                        </td>
                    </tr>
                    <tr v-if="!transactions.data.length">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No transactions.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PaginationLinks :paginator="transactions" />
    </div>
</template>
