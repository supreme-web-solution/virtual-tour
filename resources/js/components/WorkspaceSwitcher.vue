<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import workspaces from '@/routes/workspaces';

const page = usePage();
const current = computed(() => page.props.workspace);
const list = computed(() => page.props.workspaces);

function switchTo(id: string): void {
    if (id !== current.value?.id) {
        router.post(workspaces.switch.url(id));
    }
}
</script>

<template>
    <SidebarGroup v-if="list.length > 1" class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <SidebarMenuButton
                            :tooltip="current?.name ?? 'Workspace'"
                        >
                            <span
                                class="flex size-5 items-center justify-center rounded bg-sidebar-accent text-[10px] font-semibold"
                            >
                                {{ current?.name.charAt(0).toUpperCase() }}
                            </span>
                            <span class="truncate">{{ current?.name }}</span>
                            <ChevronsUpDown class="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-60">
                        <DropdownMenuLabel class="text-xs text-muted-foreground"
                            >Switch workspace</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="workspace in list"
                            :key="workspace.id"
                            @click="switchTo(workspace.id)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                workspace.name
                            }}</span>
                            <span
                                class="text-xs text-muted-foreground capitalize"
                                >{{ workspace.role }}</span
                            >
                            <Check
                                v-if="workspace.id === current?.id"
                                class="size-4"
                            />
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
