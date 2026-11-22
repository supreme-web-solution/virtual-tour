<script setup lang="ts">
import { Server as HeaderIcon } from '@lucide/vue';
import { Head } from '@inertiajs/vue3';
import { CircleCheck, CircleX } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import admin from '@/routes/admin';

defineProps<{
    app: {
        environment: string;
        debug: boolean;
        url: string;
        php: string;
        laravel: string;
        timezone: string;
    };
    infrastructure: Record<string, string | null>;
    providers: { name: string; configured: boolean }[];
    schedule: {
        command: string;
        expression: string;
        description: string | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'System', href: admin.system() },
        ],
    },
});

const labels: Record<string, string> = {
    database: 'Database',
    cache: 'Cache',
    queue: 'Queue',
    long_queue: 'Long-running queue',
    session: 'Sessions',
    mail: 'Mail',
    domains_cname: 'Custom domain CNAME target',
    domains_a: 'Custom domain A record',
};
</script>

<template>
    <Head title="System · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="System"
            :icon="HeaderIcon"
            tone="indigo"
            description="Configuration status. Keys and secrets are never shown here; set them in the server's .env file."
        />

        <div
            v-if="app.debug && app.environment === 'production'"
            class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
        >
            Debug mode is on in production. Set APP_DEBUG=false: error pages can
            expose configuration.
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-lg border bg-card p-4 shadow-sm">
                <h2 class="mb-3 font-medium">Integrations</h2>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="provider in providers"
                        :key="provider.name"
                        class="flex items-center justify-between gap-2"
                    >
                        <span>{{ provider.name }}</span>
                        <span
                            class="flex items-center gap-1"
                            :class="
                                provider.configured
                                    ? 'text-emerald-700 dark:text-emerald-300'
                                    : 'text-muted-foreground'
                            "
                        >
                            <CircleCheck
                                v-if="provider.configured"
                                class="size-4"
                            />
                            <CircleX v-else class="size-4" />
                            {{
                                provider.configured
                                    ? 'Configured'
                                    : 'Not configured'
                            }}
                        </span>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg border bg-card p-4 shadow-sm">
                <h2 class="mb-3 font-medium">Application</h2>
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">Environment</dt>
                    <dd>
                        {{ app.environment
                        }}<span v-if="app.debug"> (debug)</span>
                    </dd>
                    <dt class="text-muted-foreground">URL</dt>
                    <dd class="truncate">{{ app.url }}</dd>
                    <dt class="text-muted-foreground">Laravel / PHP</dt>
                    <dd>{{ app.laravel }} / {{ app.php }}</dd>
                    <dt class="text-muted-foreground">Timezone</dt>
                    <dd>{{ app.timezone }}</dd>
                    <template v-for="(value, key) in infrastructure" :key="key">
                        <dt class="text-muted-foreground">
                            {{ labels[key] ?? key }}
                        </dt>
                        <dd>{{ value ?? '—' }}</dd>
                    </template>
                </dl>
            </section>
        </div>

        <section class="rounded-lg border bg-card shadow-sm">
            <h2 class="border-b p-4 font-medium">Scheduled tasks</h2>
            <p class="px-4 pt-3 text-xs text-muted-foreground">
                These only run if the server calls
                <code>php artisan schedule:run</code> every minute.
            </p>
            <table class="w-full text-sm">
                <tbody>
                    <tr
                        v-for="task in schedule"
                        :key="task.command"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ task.command }}
                        </td>
                        <td
                            class="px-4 py-2 font-mono text-xs text-muted-foreground"
                        >
                            {{ task.expression }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
