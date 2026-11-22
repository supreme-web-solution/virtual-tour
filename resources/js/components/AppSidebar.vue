<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Building2,
    Clapperboard,
    Coins,
    CreditCard,
    FileText,
    Flag,
    Gauge,
    Globe,
    Images,
    KeyRound,
    LayoutGrid,
    Orbit,
    Package,
    Palette,
    Server,
    TriangleAlert,
    UserCog,
    Users,
    Wand2,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import SidebarSearch from '@/components/SidebarSearch.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import admin from '@/routes/admin';
import apiTokens from '@/routes/api-tokens';
import analytics from '@/routes/analytics';
import billing from '@/routes/billing';
import brand from '@/routes/brand';
import media from '@/routes/media';
import properties from '@/routes/properties';
import team from '@/routes/team';
import tours from '@/routes/tours';
import videos from '@/routes/videos';
import type { NavItem } from '@/types';

const page = usePage();

const homeNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid, exact: true },
];

// The work: tours, then everything made from them.
const createNavItems = computed<NavItem[]>(() => [
    { title: 'Tours', href: tours.index(), icon: Orbit },
    ...(page.props.workspace?.plan.features.video_studio
        ? [{ title: 'Videos', href: videos.index(), icon: Clapperboard }]
        : []),
    { title: 'Properties', href: properties.index(), icon: Building2 },
    { title: 'Media library', href: media.index(), icon: Images },
]);

const growNavItems: NavItem[] = [
    { title: 'Analytics', href: analytics.index(), icon: BarChart3 },
];

const hasBrandFeatures = computed(() => {
    const features = page.props.workspace?.plan.features;

    return Boolean(
        features?.remove_branding ||
        features?.custom_domain ||
        features?.tracking_scripts,
    );
});

// Workspace settings; billing and branding only for owners and admins.
const workspaceNavItems = computed<NavItem[]>(() => [
    { title: 'Team', href: team.index(), icon: Users },
    // Brand & domain only exists for plans with white-label, a custom domain or tracking.
    ...(page.props.workspace?.can?.manage && hasBrandFeatures.value
        ? [{ title: 'Brand & domain', href: brand.index(), icon: Palette }]
        : []),
    ...(page.props.workspace?.can?.manage
        ? [{ title: 'Plan & usage', href: billing.index(), icon: CreditCard }]
        : []),
    ...(page.props.workspace?.can?.manage &&
    page.props.workspace?.plan.features.api_access
        ? [{ title: 'API access', href: apiTokens.index(), icon: KeyRound }]
        : []),
]);

const isAdmin = computed(() => page.props.auth.user?.is_admin === true);

const adminNavItems: NavItem[] = [
    { title: 'Overview', href: admin.dashboard(), icon: Gauge, exact: true },
    { title: 'Workspaces', href: admin.workspaces.index(), icon: Building2 },
    { title: 'Users', href: admin.users.index(), icon: UserCog },
    { title: 'Plans', href: admin.plans.index(), icon: Package },
    { title: 'Content', href: admin.content.index(), icon: FileText },
    { title: 'Reports', href: admin.reports.index(), icon: Flag },
    { title: 'AI generations', href: admin.generations.index(), icon: Wand2 },
    { title: 'Credits', href: admin.credits.index(), icon: Coins },
    {
        title: 'Cloudflare hostnames',
        href: admin.cloudflareHostnames.index(),
        icon: Globe,
    },
    { title: 'Failed jobs', href: admin.jobs.index(), icon: TriangleAlert },
    { title: 'System', href: admin.system(), icon: Server },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarSearch class="px-0" />
        </SidebarHeader>

        <SidebarContent
            class="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            <NavMain label="Home" :items="homeNavItems" />
            <NavMain label="Create" :items="createNavItems" />
            <NavMain label="Grow" :items="growNavItems" />
            <NavMain v-if="isAdmin" label="Admin" :items="adminNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavMain
                label="Workspace"
                :items="workspaceNavItems"
                class="px-0 py-0"
            />
            <WorkspaceSwitcher />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
