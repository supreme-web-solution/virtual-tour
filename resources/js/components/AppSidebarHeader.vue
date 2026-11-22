<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Coins, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useCreateTour } from '@/composables/useCreateTour';
import { formatCredits, isUnlimitedCredits } from '@/lib/credits';
import billing from '@/routes/billing';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const canCreate = computed(() => page.props.workspace?.can?.edit === true);
const canManage = computed(() => page.props.workspace?.can?.manage === true);
const credits = computed(() => page.props.workspace?.credits ?? null);
const { openCreateTour } = useCreateTour();
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-[72px] shrink-0 items-center gap-2 border-b bg-background/95 px-4 backdrop-blur md:px-6"
    >
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div class="flex items-center gap-2">
            <component
                :is="canManage ? Link : 'span'"
                v-if="credits !== null"
                v-bind="canManage ? { href: billing.index() } : {}"
                class="flex h-10 items-center gap-2 rounded-full border bg-card px-3.5 text-sm font-medium shadow-xs transition-colors"
                :class="[
                    canManage ? 'hover:border-foreground/30' : '',
                    credits === 0 ? 'text-rose-600' : '',
                ]"
                :title="
                    credits === 0
                        ? 'No AI credits left. AI tools such as photo enhance and video need credits.'
                        : 'AI credits available. Used by photo enhance, 360° generation and video.'
                "
            >
                <span
                    class="grid size-6 place-items-center rounded-full"
                    :class="
                        credits === 0
                            ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300'
                            : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'
                    "
                    ><Coins class="size-3.5"
                /></span>
                <span class="tabular-nums">{{ formatCredits(credits) }}</span>
                <span
                    v-if="!isUnlimitedCredits(credits)"
                    class="hidden text-muted-foreground sm:inline"
                    >credits</span
                >
            </component>
            <button
                v-if="canCreate"
                type="button"
                class="flex h-10 items-center gap-2 rounded-full border border-primary/30 bg-primary/5 px-4 text-sm font-medium text-primary shadow-xs transition-colors hover:bg-primary/10 focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none"
                @click="openCreateTour()"
            >
                New tour <Sparkles class="size-4" />
            </button>
            <ThemeToggle />
        </div>
    </header>
</template>
