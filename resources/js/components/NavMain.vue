<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import NavIcon from '@/components/NavIcon.vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Platform' },
);

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-3 py-1.5">
        <SidebarGroupLabel
            class="mb-1 text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ label }}</SidebarGroupLabel
        >
        <SidebarMenu class="gap-1">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    class="sweep h-10 rounded-xl px-3 text-[0.9rem] font-medium text-sidebar-foreground/80 hover:text-sidebar-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground"
                    :is-active="
                        item.exact
                            ? isCurrentUrl(item.href)
                            : isCurrentOrParentUrl(item.href)
                    "
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <NavIcon v-if="item.icon" :icon="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
