<script setup lang="ts">
import { Wand2 as HeaderIcon } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { useListFilters } from '@/composables/useListFilters';
import { timeAgo } from '@/lib/format';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type AdminGeneration = {
    id: string;
    type: string;
    provider: string;
    model: string | null;
    status: string;
    status_label: string;
    workspace: { id: string; name: string } | null;
    user: string | null;
    credits_reserved: number;
    credits_charged: number;
    cost: number | null;
    error: string | null;
    created_at: string | null;
    completed_at: string | null;
};

const props = defineProps<{
    generations: Paginated<AdminGeneration>;
    statuses: { value: string; label: string }[];
    types: { value: string; label: string }[];
    providers: string[];
    filters: { status: string; type: string; provider: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'AI generations', href: admin.generations.index() },
        ],
    },
});

const filters = useListFilters(
    { ...props.filters },
    admin.generations.index.url,
    0,
);

const tone: Record<string, string> = {
    completed: 'text-emerald-700 dark:text-emerald-300',
    failed: 'text-red-700 dark:text-red-300',
    cancelled: 'text-muted-foreground',
    refunded: 'text-muted-foreground',
};
</script>

<template>
    <Head title="AI generations · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="AI generations"
            :icon="HeaderIcon"
            tone="violet"
            :description="`${generations.total} requests to AI providers`"
        />

        <div class="flex flex-wrap gap-3">
            <NativeSelect
                v-model="filters.status"
                :options="statuses"
                placeholder="Any status"
                aria-label="Status"
                class="w-44"
            />
            <NativeSelect
                v-model="filters.type"
                :options="types"
                placeholder="Any operation"
                aria-label="Operation"
                class="w-56"
            />
            <NativeSelect
                v-model="filters.provider"
                :options="providers.map((p) => ({ value: p, label: p }))"
                placeholder="Any provider"
                aria-label="Provider"
                class="w-44"
            />
        </div>

        <div class="overflow-x-auto rounded-lg border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">Operation</th>
                        <th class="px-4 py-2 font-medium">Workspace</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Credits
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Cost</th>
                        <th class="px-4 py-2 font-medium">Started</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="generation in generations.data"
                        :key="generation.id"
                        class="border-b align-top last:border-0"
                    >
                        <td class="px-4 py-2">
                            <p class="font-medium">{{ generation.type }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ generation.provider
                                }}<template v-if="generation.model">
                                    · {{ generation.model }}</template
                                >
                            </p>
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="generation.workspace"
                                :href="
                                    admin.workspaces.show(
                                        generation.workspace.id,
                                    )
                                "
                                class="hover:underline"
                                >{{ generation.workspace.name }}</Link
                            >
                            <p
                                v-if="generation.user"
                                class="text-xs text-muted-foreground"
                            >
                                {{ generation.user }}
                            </p>
                        </td>
                        <td class="max-w-sm px-4 py-2">
                            <span :class="tone[generation.status]">{{
                                generation.status_label
                            }}</span>
                            <p
                                v-if="generation.error"
                                class="mt-0.5 text-xs break-words text-muted-foreground"
                            >
                                {{ generation.error }}
                            </p>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ generation.credits_charged
                            }}<span
                                v-if="
                                    generation.credits_reserved !==
                                    generation.credits_charged
                                "
                                class="text-xs text-muted-foreground"
                            >
                                / {{ generation.credits_reserved }}</span
                            >
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{
                                generation.cost !== null
                                    ? `$${generation.cost.toFixed(2)}`
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ timeAgo(generation.created_at) }}
                        </td>
                    </tr>
                    <tr v-if="!generations.data.length">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No generations match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PaginationLinks :paginator="generations" />
    </div>
</template>
