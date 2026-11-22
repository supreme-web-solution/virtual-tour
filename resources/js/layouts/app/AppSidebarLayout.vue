<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import CreateTourDialog from '@/components/create/CreateTourDialog.vue';
import { Toaster } from '@/components/ui/sonner';
import { stop } from '@/routes/impersonation';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();

function returnToAdmin(): void {
    router.post(stop().url);
}
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <div
                v-if="page.props.impersonating"
                class="flex flex-wrap items-center justify-between gap-2 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950"
                role="status"
            >
                <span>Viewing as {{ page.props.auth.user.name }}</span>
                <button
                    type="button"
                    class="rounded-md bg-amber-950 px-3 py-1 text-xs font-semibold text-amber-50 hover:bg-amber-900"
                    @click="returnToAdmin"
                >
                    Return to admin
                </button>
            </div>
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
        <CreateTourDialog />
    </AppShell>
</template>
