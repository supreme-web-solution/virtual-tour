<script setup lang="ts">
import { Globe as HeaderIcon } from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { RefreshCw, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AvatarChip from '@/components/AvatarChip.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';

type Hostname = {
    id: string;
    hostname: string;
    status: string;
    ssl_status: string | null;
    errors: string[];
    type: 'tour_domain' | 'orphan';
    workspace: { id: string; name: string } | null;
    account: { name: string; email: string } | null;
    local_host: string | null;
};

const props = defineProps<{
    enabled: boolean;
    error: string | null;
    hostnames: Hostname[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            {
                title: 'Cloudflare hostnames',
                href: admin.cloudflareHostnames.index(),
            },
        ],
    },
});

const filter = ref<'all' | 'tour_domain' | 'orphan'>('all');
const refreshing = ref(false);

const filters = computed(() => [
    { value: 'all' as const, label: 'All', count: props.hostnames.length },
    {
        value: 'tour_domain' as const,
        label: 'Tour domains',
        count: props.hostnames.filter((row) => row.type === 'tour_domain')
            .length,
    },
    {
        value: 'orphan' as const,
        label: 'Orphans',
        count: props.hostnames.filter((row) => row.type === 'orphan').length,
    },
]);

const rows = computed(() =>
    filter.value === 'all'
        ? props.hostnames
        : props.hostnames.filter((row) => row.type === filter.value),
);

function tone(status: string | null): string {
    if (status === 'active') {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300';
    }

    if (!status || status.startsWith('pending') || status === 'initializing') {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300';
    }

    return 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300';
}

function refresh(): void {
    router.reload({
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}

function remove(row: Hostname): void {
    const note = row.workspace
        ? 'The domain stays connected in the app; only its Cloudflare certificate record is removed.'
        : 'It is not linked to any workspace.';

    if (
        window.confirm(
            `Delete ${row.hostname} from Cloudflare? ${note} This cannot be undone.`,
        )
    ) {
        router.delete(admin.cloudflareHostnames.destroy.url(row.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Cloudflare hostnames · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Cloudflare hostnames"
            :icon="HeaderIcon"
            tone="sky"
            description="Live from your Cloudflare zone. Delete orphans, such as leftover test hostnames, without opening the Cloudflare dashboard."
        >
            <template #actions>
                <Button
                    variant="outline"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <RefreshCw :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </Button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!enabled"
            :icon="HeaderIcon"
            title="Cloudflare is not configured"
            description="Set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID in your environment, then refresh."
        />

        <template v-else>
            <p
                v-if="error"
                class="flex gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ error }}
            </p>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="item in filters"
                    :key="item.value"
                    type="button"
                    class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        filter === item.value
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-card hover:border-foreground/30'
                    "
                    @click="filter = item.value"
                >
                    {{ item.label }}
                    <span class="ml-1 text-xs opacity-70"
                        >({{ item.count }})</span
                    >
                </button>
            </div>

            <div
                v-if="rows.length"
                class="overflow-x-auto rounded-xl border bg-card shadow-sm"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                        >
                            <th class="px-4 py-3 font-medium">Hostname</th>
                            <th class="px-4 py-3 font-medium">Type</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Certificate</th>
                            <th class="px-4 py-3 font-medium">Account</th>
                            <th class="px-4 py-3 font-medium">
                                Linked workspace
                            </th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in rows" :key="row.id" class="align-top">
                            <td class="max-w-[16rem] px-4 py-3">
                                <p
                                    class="truncate font-mono text-[13px]"
                                    :title="row.hostname"
                                >
                                    {{ row.hostname }}
                                </p>
                                <p
                                    v-for="message in row.errors"
                                    :key="message"
                                    class="mt-1 text-xs text-rose-600"
                                >
                                    {{ message }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        row.type === 'orphan'
                                            ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300'
                                            : 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300'
                                    "
                                    >{{
                                        row.type === 'orphan'
                                            ? 'Orphan'
                                            : 'Tour domain'
                                    }}</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="tone(row.status)"
                                    >{{ row.status }}</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="tone(row.ssl_status)"
                                    >{{ row.ssl_status ?? 'none' }}</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    v-if="row.account"
                                    class="flex min-w-0 items-center gap-2"
                                >
                                    <AvatarChip
                                        :name="row.account.name"
                                        :seed="row.account.email"
                                        size="sm"
                                    />
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">
                                            {{ row.account.name }}
                                        </p>
                                        <p
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ row.account.email }}
                                        </p>
                                    </div>
                                </div>
                                <span v-else class="text-muted-foreground"
                                    >Not linked in the app</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    v-if="row.workspace"
                                    :href="
                                        admin.workspaces.show.url(
                                            row.workspace.id,
                                        )
                                    "
                                    class="underline-offset-4 hover:underline"
                                    >{{ row.workspace.name }}</Link
                                >
                                <span v-else class="text-muted-foreground"
                                    >-</span
                                >
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    @click="remove(row)"
                                >
                                    <Trash2 /> Delete
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                :icon="HeaderIcon"
                title="No hostnames here"
                description="Custom hostnames appear when a workspace connects a domain."
            />
        </template>
    </div>
</template>
