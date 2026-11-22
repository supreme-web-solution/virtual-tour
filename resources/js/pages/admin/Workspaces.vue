<script setup lang="ts">
import { ArrowRight, Building2 as HeaderIcon } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import AvatarChip from '@/components/AvatarChip.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import { formatDate } from '@/lib/format';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type AdminWorkspace = {
    id: string;
    name: string;
    owner: { name: string; email: string };
    plan: string | null;
    members_count: number;
    properties_count: number;
    tours_count: number;
    credits: number;
    credits_held: number;
    created_at: string | null;
};

const props = defineProps<{
    workspaces: Paginated<AdminWorkspace>;
    plans: { value: string; label: string }[];
    filters: { q: string; plan: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Workspaces', href: admin.workspaces.index() },
        ],
    },
});

const filters = useListFilters(
    { ...props.filters },
    admin.workspaces.index.url,
);
</script>

<template>
    <Head title="Workspaces · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Workspaces"
            :icon="HeaderIcon"
            tone="violet"
            :description="`${workspaces.total} workspaces. Open one to change its plan or add credits.`"
        />

        <div class="flex flex-wrap gap-3">
            <Input
                v-model="filters.q"
                placeholder="Search name or owner email"
                class="w-72"
                aria-label="Search workspaces"
            />
            <NativeSelect
                v-model="filters.plan"
                :options="plans"
                placeholder="Any plan"
                aria-label="Filter by plan"
                class="w-48"
            />
        </div>

        <div class="overflow-x-auto rounded-lg border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">Workspace</th>
                        <th class="px-4 py-2 font-medium">Plan</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Members
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Properties
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Tours</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Available credits
                        </th>
                        <th class="px-4 py-2 font-medium">Created</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="workspace in workspaces.data"
                        :key="workspace.id"
                        class="border-b last:border-0 hover:bg-muted/40"
                    >
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-3">
                                <AvatarChip :name="workspace.name" />
                                <div class="min-w-0">
                                    <Link
                                        :href="
                                            admin.workspaces.show(workspace.id)
                                        "
                                        class="font-medium hover:underline"
                                        >{{ workspace.name }}</Link
                                    >
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ workspace.owner.email }}
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2">{{ workspace.plan ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ workspace.members_count }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ workspace.properties_count }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ workspace.tours_count }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ workspace.credits.toLocaleString() }}
                            <p
                                v-if="workspace.credits_held"
                                class="text-xs font-normal text-muted-foreground"
                            >
                                {{ workspace.credits_held }} held
                            </p>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ formatDate(workspace.created_at) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Link
                                :href="admin.workspaces.show(workspace.id)"
                                class="inline-flex h-8 items-center gap-1.5 rounded-full border bg-background px-3 text-xs font-medium shadow-xs transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-primary"
                            >
                                Open <ArrowRight class="size-3.5" />
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!workspaces.data.length">
                        <td
                            colspan="8"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            No workspaces match.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PaginationLinks :paginator="workspaces" />
    </div>
</template>
