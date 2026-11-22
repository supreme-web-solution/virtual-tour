<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Download,
    Mail,
    MailCheck,
    MailOpen,
    Phone,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AvatarChip from '@/components/AvatarChip.vue';
import EmptyState from '@/components/EmptyState.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { formatDate, timeAgo } from '@/lib/format';
import { exportMethod as exportAnalytics } from '@/routes/analytics';
import leadRoutes from '@/routes/leads';
import type { Paginated } from '@/types';

export type Lead = {
    id: string;
    name: string;
    email: string;
    phone: string | null;
    message: string | null;
    tour: string | null;
    is_read: boolean;
    created_at: string | null;
};

/**
 * Enquiries as an inbox: people on the left, the conversation on the right.
 */
const props = defineProps<{
    leads: Paginated<Lead>;
    filters: { tour: string; range: string };
    can: { manageLeads: boolean; deleteLeads: boolean; exportLeads: boolean };
}>();

const selectedId = ref<string | null>(props.leads.data[0]?.id ?? null);
// On phones the list and the conversation take turns.
const showThread = ref(false);

const selected = computed(
    () => props.leads.data.find((lead) => lead.id === selectedId.value) ?? null,
);

watch(
    () => props.leads.data.map((lead) => lead.id).join(),
    () => {
        if (!selected.value) {
            selectedId.value = props.leads.data[0]?.id ?? null;
        }
    },
);

function open(lead: Lead): void {
    selectedId.value = lead.id;
    showThread.value = true;

    if (!lead.is_read && props.can.manageLeads) {
        setRead(lead, true);
    }
}

function setRead(lead: Lead, read: boolean): void {
    router.patch(
        leadRoutes.mark.url(lead.id),
        { read },
        { preserveScroll: true, preserveState: true },
    );
}

function remove(lead: Lead): void {
    if (
        window.confirm(
            `Delete the enquiry from ${lead.name}? This can't be undone.`,
        )
    ) {
        showThread.value = false;
        router.delete(leadRoutes.destroy.url(lead.id), {
            preserveScroll: true,
        });
    }
}

function snippet(lead: Lead): string {
    return (lead.message ?? '').replace(/\s+/g, ' ').trim() || 'No message';
}

function replyHref(lead: Lead): string {
    const subject = `Re: your enquiry${lead.tour ? ` about ${lead.tour}` : ''}`;

    return `mailto:${lead.email}?subject=${encodeURIComponent(subject)}`;
}

const exportHref = computed(() => {
    const query: Record<string, string> = {
        type: 'contacts',
        range: props.filters.range,
    };

    if (props.filters.tour) {
        query.tour = props.filters.tour;
    }

    return exportAnalytics.url({ query });
});

const unread = computed(
    () => props.leads.data.filter((lead) => !lead.is_read).length,
);
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-muted-foreground">
                <span class="font-medium text-foreground">{{
                    leads.total
                }}</span>
                {{ leads.total === 1 ? 'enquiry' : 'enquiries' }}
                <template v-if="unread">
                    ·
                    <span class="font-medium text-primary"
                        >{{ unread }} new</span
                    >
                </template>
            </p>
            <Button
                v-if="can.exportLeads && leads.total"
                variant="outline"
                size="sm"
                as-child
            >
                <a :href="exportHref"><Download /> Export leads (CSV)</a>
            </Button>
        </div>

        <div
            v-if="leads.data.length"
            class="grid overflow-hidden rounded-xl border bg-card shadow-sm md:h-[clamp(300px,calc(100vh-27rem),520px)] md:grid-cols-[320px_minmax(0,1fr)]"
        >
            <ul
                class="min-w-0 divide-y overflow-y-auto md:border-r"
                :class="{ 'hidden md:block': showThread }"
            >
                <li v-for="lead in leads.data" :key="lead.id">
                    <button
                        type="button"
                        class="flex w-full min-w-0 items-start gap-3 px-3.5 py-3 text-left transition-colors hover:bg-muted/50"
                        :class="{
                            'bg-primary/5': lead.id === selectedId,
                        }"
                        @click="open(lead)"
                    >
                        <AvatarChip :name="lead.name" :seed="lead.email" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span
                                    class="truncate text-sm"
                                    :class="
                                        lead.is_read
                                            ? 'font-medium'
                                            : 'font-semibold'
                                    "
                                    >{{ lead.name }}</span
                                >
                                <span
                                    class="ml-auto shrink-0 text-[11px] text-muted-foreground"
                                    >{{ timeAgo(lead.created_at) }}</span
                                >
                            </span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ lead.tour }}</span
                            >
                            <span class="mt-0.5 flex items-center gap-2">
                                <span
                                    class="truncate text-xs"
                                    :class="
                                        lead.is_read
                                            ? 'text-muted-foreground'
                                            : 'text-foreground'
                                    "
                                    >{{ snippet(lead) }}</span
                                >
                                <span
                                    v-if="!lead.is_read"
                                    class="ml-auto size-2 shrink-0 rounded-full bg-primary"
                                    aria-label="Unread"
                                />
                            </span>
                        </span>
                    </button>
                </li>
            </ul>

            <section
                v-if="selected"
                class="flex min-h-0 min-w-0 flex-col"
                :class="{ 'hidden md:flex': !showThread }"
            >
                <header
                    class="flex items-center gap-3 border-b bg-muted/30 px-4 py-3"
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        class="-ml-2 shrink-0 md:hidden"
                        aria-label="Back to enquiries"
                        @click="showThread = false"
                    >
                        <ArrowLeft />
                    </Button>
                    <AvatarChip :name="selected.name" :seed="selected.email" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">
                            {{ selected.name }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ selected.email
                            }}<template v-if="selected.phone">
                                · {{ selected.phone }}</template
                            >
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Button
                            v-if="can.manageLeads"
                            variant="ghost"
                            size="icon"
                            :aria-label="
                                selected.is_read
                                    ? 'Mark as unread'
                                    : 'Mark as read'
                            "
                            :title="
                                selected.is_read
                                    ? 'Mark as unread'
                                    : 'Mark as read'
                            "
                            @click="setRead(selected, !selected.is_read)"
                        >
                            <MailOpen v-if="selected.is_read" />
                            <MailCheck v-else />
                        </Button>
                        <Button
                            v-if="can.deleteLeads"
                            variant="ghost"
                            size="icon"
                            class="text-destructive hover:text-destructive"
                            aria-label="Delete enquiry"
                            title="Delete enquiry"
                            @click="remove(selected)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </header>

                <div class="flex-1 space-y-4 overflow-y-auto p-4">
                    <p
                        class="text-center text-[11px] tracking-wide text-muted-foreground uppercase"
                    >
                        {{ formatDate(selected.created_at) }}
                        <template v-if="selected.tour">
                            · via {{ selected.tour }}</template
                        >
                    </p>

                    <div class="flex items-end gap-2">
                        <AvatarChip
                            :name="selected.name"
                            :seed="selected.email"
                            size="sm"
                        />
                        <div class="max-w-[85%] min-w-0">
                            <div
                                class="rounded-2xl rounded-bl-md bg-muted px-4 py-2.5 text-sm break-words whitespace-pre-line"
                            >
                                <template v-if="selected.message">{{
                                    selected.message
                                }}</template>
                                <span v-else class="text-muted-foreground"
                                    >No message, just their contact
                                    details.</span
                                >
                            </div>
                            <p
                                class="mt-1 pl-1 text-[11px] text-muted-foreground"
                            >
                                {{ timeAgo(selected.created_at) }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mx-auto flex max-w-sm flex-wrap justify-center gap-2 text-xs"
                    >
                        <a
                            :href="`mailto:${selected.email}`"
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1 hover:border-foreground/30"
                            ><Mail class="size-3.5" /> {{ selected.email }}</a
                        >
                        <a
                            v-if="selected.phone"
                            :href="`tel:${selected.phone}`"
                            class="inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1 hover:border-foreground/30"
                            ><Phone class="size-3.5" /> {{ selected.phone }}</a
                        >
                    </div>
                </div>

                <footer class="flex items-center gap-2 border-t p-3">
                    <div
                        class="min-w-0 flex-1 truncate rounded-full border bg-muted/40 px-4 py-2 text-sm text-muted-foreground"
                    >
                        Reply to {{ selected.name }} by email…
                    </div>
                    <Button as-child class="shrink-0 rounded-full">
                        <a :href="replyHref(selected)"><Mail /> Reply</a>
                    </Button>
                </footer>
            </section>
        </div>

        <EmptyState
            v-else
            :icon="Mail"
            title="No enquiries yet"
            description="Turn on the contact form in a tour's Extras tab to collect enquiries from visitors."
        />
        <PaginationLinks :paginator="leads" />
    </div>
</template>
