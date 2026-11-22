<script setup lang="ts">
import { Users as HeaderIcon } from '@lucide/vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    Building2,
    Clock,
    Crown,
    Eye,
    Film,
    Globe,
    Images,
    Mail,
    MessageSquare,
    Orbit,
    Pencil,
    Send,
    Shield,
    UserPlus,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AvatarChip from '@/components/AvatarChip.vue';
import { useListFilters } from '@/composables/useListFilters';
import { accents } from '@/lib/accent';
import type { Accent } from '@/lib/accent';
import { formatDate, timeAgo } from '@/lib/format';
import team from '@/routes/team';
import invitationRoutes from '@/routes/team/invitations';
import memberRoutes from '@/routes/team/members';
import type { Option, Paginated } from '@/types';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    joined_at: string | null;
    is_you: boolean;
    can_manage: boolean;
};

const props = defineProps<{
    workspaceInfo: { id: string; name: string; is_personal: boolean };
    members: Member[];
    invitations: {
        id: number;
        email: string;
        role: string;
        expired: boolean;
        expires_at: string;
    }[];
    assignableRoles: Option[];
    myRole: string | null;
    can: { manage: boolean; rename: boolean };
    activity: Paginated<{
        id: number;
        action: string;
        description: string;
        user: string | null;
        created_at: string;
    }> | null;
    activityKinds: Option[];
    activityFilters: { member: string; kind: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Team', href: team.index() }],
    },
});

const roleHelp: Record<string, string> = {
    owner: 'Full access, including billing and deleting the workspace.',
    admin: 'Manages properties, tours, media and editors/viewers.',
    editor: 'Creates and edits properties, tours, media and AI generations.',
    viewer: 'Views properties, tours and analytics.',
};

const roleStyle: Record<
    string,
    { icon: LucideIcon; badge: string; tile: string }
> = {
    owner: {
        icon: Crown,
        badge: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
        tile: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200',
    },
    admin: {
        icon: Shield,
        badge: 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200',
        tile: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200',
    },
    editor: {
        icon: Pencil,
        badge: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
        tile: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200',
    },
    viewer: {
        icon: Eye,
        badge: 'bg-slate-200 text-slate-700 dark:bg-slate-500/25 dark:text-slate-200',
        tile: 'bg-slate-200 text-slate-600 dark:bg-slate-500/25 dark:text-slate-200',
    },
};

function styleFor(role: string) {
    return roleStyle[role] ?? roleStyle.viewer;
}

const avatarColors = [
    'bg-sky-600',
    'bg-indigo-600',
    'bg-emerald-600',
    'bg-rose-600',
    'bg-amber-600',
    'bg-violet-600',
    'bg-teal-600',
];

function avatarColor(name: string): string {
    const sum = [...name].reduce(
        (total, char) => total + char.charCodeAt(0),
        0,
    );

    return avatarColors[sum % avatarColors.length];
}

const pendingCount = computed(
    () => props.invitations.filter((invite) => !invite.expired).length,
);

const nameForm = useForm({ name: props.workspaceInfo.name });
const inviteForm = useForm({
    email: '',
    role: props.assignableRoles.at(-2)?.value ?? 'editor',
});
const roleError = ref<string | null>(null);
const inviteOpen = ref(false);
const renameOpen = ref(false);

function rename(): void {
    if (nameForm.name.trim() && nameForm.name !== props.workspaceInfo.name) {
        nameForm.submit(team.rename(), {
            preserveScroll: true,
            onSuccess: () => (renameOpen.value = false),
        });
    }
}

function invite(): void {
    inviteForm.submit(team.invite(), {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset('email');
            inviteOpen.value = false;
        },
    });
}

function changeRole(member: Member, role: string): void {
    roleError.value = null;
    router.patch(
        memberRoutes.update.url(member.id),
        { role },
        {
            preserveScroll: true,
            onError: (errors) => {
                roleError.value = errors.role ?? null;
            },
        },
    );
}

function remove(member: Member): void {
    const message = member.is_you
        ? `Leave ${props.workspaceInfo.name}? You'll lose access to its tours and media.`
        : `Remove ${member.name} from ${props.workspaceInfo.name}?`;

    if (window.confirm(message)) {
        router.delete(memberRoutes.remove.url(member.id), {
            preserveScroll: true,
            onError: (errors) => {
                roleError.value = errors.role ?? null;
            },
        });
    }
}

function revoke(id: number): void {
    router.delete(invitationRoutes.revoke.url(id), { preserveScroll: true });
}

function resend(id: number): void {
    router.post(invitationRoutes.resend.url(id), {}, { preserveScroll: true });
}

const activityFilters = useListFilters(
    { ...props.activityFilters },
    team.index.url,
    0,
);
const activityLooks: Record<string, { icon: LucideIcon; tone: Accent }> = {
    property: { icon: Building2, tone: 'sky' },
    tour: { icon: Orbit, tone: 'violet' },
    media: { icon: Images, tone: 'emerald' },
    video: { icon: Film, tone: 'rose' },
    comment: { icon: MessageSquare, tone: 'amber' },
    team: { icon: Users, tone: 'indigo' },
    workspace: { icon: Globe, tone: 'sky' },
    lead: { icon: Mail, tone: 'amber' },
};

function activityLook(action: string): { icon: LucideIcon; tone: Accent } {
    return (
        activityLooks[action.split('.')[0]] ?? { icon: Activity, tone: 'sky' }
    );
}
</script>

<template>
    <Head title="Team" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Team"
            :icon="HeaderIcon"
            tone="violet"
            :description="`Who can work in ${workspaceInfo.name}, and what they can do.`"
        >
            <template #actions>
                <Button
                    v-if="can.rename"
                    variant="outline"
                    @click="renameOpen = true"
                >
                    <Pencil /> Rename workspace
                </Button>
                <Button v-if="can.manage" @click="inviteOpen = true">
                    <UserPlus /> Invite member
                </Button>
            </template>
        </PageHeader>

        <!-- Members -->
        <section class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="flex items-center gap-2 border-b px-5 py-4">
                <Users class="size-4 text-primary" />
                <h2 class="font-semibold">Members</h2>
                <span
                    class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                    >{{ members.length }}</span
                >
            </div>
            <p
                v-if="roleError"
                class="border-b bg-destructive/5 px-5 py-2.5 text-sm text-destructive"
            >
                {{ roleError }}
            </p>

            <div
                class="hidden grid-cols-[minmax(0,1fr)_10rem_9rem_6rem] gap-4 border-b bg-muted/40 px-5 py-2 text-xs font-medium text-muted-foreground md:grid"
            >
                <span>Member</span>
                <span>Role</span>
                <span>Joined</span>
                <span class="text-right">Actions</span>
            </div>

            <ul class="divide-y">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="grid items-center gap-x-4 gap-y-3 px-5 py-3.5 md:grid-cols-[minmax(0,1fr)_10rem_9rem_6rem]"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white"
                            :class="avatarColor(member.name)"
                        >
                            {{ member.name.charAt(0).toUpperCase() }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ member.name }}
                                <span
                                    v-if="member.is_you"
                                    class="ml-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >You</span
                                >
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ member.email }}
                            </p>
                        </div>
                    </div>

                    <div>
                        <NativeSelect
                            v-if="member.can_manage"
                            :model-value="member.role"
                            :options="
                                assignableRoles.some(
                                    (role) => role.value === member.role,
                                )
                                    ? assignableRoles
                                    : [
                                          {
                                              value: member.role,
                                              label: member.role,
                                          },
                                          ...assignableRoles,
                                      ]
                            "
                            :aria-label="`Role for ${member.name}`"
                            @update:model-value="
                                changeRole(member, String($event))
                            "
                        />
                        <span
                            v-else
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium capitalize"
                            :class="styleFor(member.role).badge"
                        >
                            <component
                                :is="styleFor(member.role).icon"
                                class="size-3.5"
                            />
                            {{ member.role }}
                        </span>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        {{ formatDate(member.joined_at) }}
                    </p>

                    <div class="flex justify-end">
                        <Button
                            v-if="
                                member.can_manage ||
                                (member.is_you && !workspaceInfo.is_personal)
                            "
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            @click="remove(member)"
                        >
                            {{ member.is_you ? 'Leave' : 'Remove' }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Pending invitations -->
        <section
            v-if="can.manage && invitations.length"
            class="overflow-hidden rounded-2xl border bg-card shadow-sm"
        >
            <div class="flex items-center gap-2 border-b px-5 py-4">
                <Clock class="size-4 text-primary" />
                <h2 class="font-semibold">Pending invitations</h2>
                <span
                    class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                    >{{ invitations.length }}</span
                >
            </div>
            <ul class="divide-y">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center gap-3 px-5 py-3.5 text-sm"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <Mail class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">
                            {{ invitation.email }}
                        </p>
                        <p
                            class="text-xs"
                            :class="
                                invitation.expired
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            <span class="capitalize">{{
                                invitation.role
                            }}</span>
                            ·
                            {{
                                invitation.expired
                                    ? 'Expired'
                                    : `Expires ${formatDate(invitation.expires_at)}`
                            }}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="resend(invitation.id)"
                        >Resend</Button
                    >
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="revoke(invitation.id)"
                        >Revoke</Button
                    >
                </li>
            </ul>
        </section>

        <!-- Roles -->
        <section class="space-y-3">
            <h2 class="font-semibold">What each role can do</h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="(help, role) in roleHelp"
                    :key="role"
                    class="flex gap-3 rounded-2xl border bg-card p-4 shadow-sm"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                        :class="styleFor(role).tile"
                    >
                        <component :is="styleFor(role).icon" class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-medium capitalize">{{ role }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ help }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Activity -->
        <section
            v-if="activity"
            class="overflow-hidden rounded-2xl border bg-card shadow-sm"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Activity</h2>
                    <p class="text-xs text-muted-foreground">
                        What people in this workspace have done recently.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <NativeSelect
                        v-model="activityFilters.member"
                        :options="
                            members.map((member) => ({
                                value: String(member.id),
                                label: member.name,
                            }))
                        "
                        placeholder="Everyone"
                        aria-label="Filter by member"
                        class="w-44"
                    />
                    <NativeSelect
                        v-model="activityFilters.kind"
                        :options="activityKinds"
                        placeholder="All activity"
                        aria-label="Filter by kind"
                        class="w-40"
                    />
                </div>
            </div>
            <ul v-if="activity.data.length" class="divide-y">
                <li
                    v-for="entry in activity.data"
                    :key="entry.id"
                    class="flex items-center gap-3 px-5 py-3"
                >
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-xl"
                        :class="accents[activityLook(entry.action).tone]"
                        aria-hidden="true"
                    >
                        <component
                            :is="activityLook(entry.action).icon"
                            class="size-4"
                        />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm" :title="entry.description">
                            {{ entry.description }}
                        </p>
                        <p
                            class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <AvatarChip
                                v-if="entry.user"
                                :name="entry.user"
                                size="xs"
                            />
                            <span class="truncate">{{
                                entry.user ?? 'System'
                            }}</span>
                        </p>
                    </div>
                    <span
                        class="shrink-0 text-xs text-muted-foreground"
                        :title="formatDate(entry.created_at)"
                        >{{ timeAgo(entry.created_at) }}</span
                    >
                </li>
            </ul>
            <p v-else class="p-8 text-center text-sm text-muted-foreground">
                No activity matches.
            </p>
            <div class="px-5 pb-4">
                <PaginationLinks :paginator="activity" />
            </div>
        </section>

        <!-- Invite modal -->
        <Dialog v-model:open="inviteOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Invite a team member</DialogTitle>
                    <DialogDescription>
                        They get an email link, valid for 7 days. It can only be
                        accepted with that email address.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="invite">
                    <div class="grid gap-2">
                        <Label for="invite-email">Email</Label>
                        <Input
                            id="invite-email"
                            v-model="inviteForm.email"
                            type="email"
                            required
                            maxlength="255"
                            placeholder="name@company.com"
                        />
                        <InputError :message="inviteForm.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="invite-role">Role</Label>
                        <NativeSelect
                            id="invite-role"
                            v-model="inviteForm.role"
                            :options="assignableRoles"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ roleHelp[inviteForm.role] }}
                        </p>
                        <InputError :message="inviteForm.errors.role" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="inviteOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="inviteForm.processing">
                            <Send /> Send invitation
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Rename modal -->
        <Dialog v-model:open="renameOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Rename workspace</DialogTitle>
                    <DialogDescription>
                        The name shows in the sidebar, invitations and your
                        channel page.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="rename">
                    <div class="grid gap-2">
                        <Label for="workspace-name">Workspace name</Label>
                        <Input
                            id="workspace-name"
                            v-model="nameForm.name"
                            maxlength="120"
                        />
                        <InputError :message="nameForm.errors.name" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="renameOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                nameForm.processing ||
                                !nameForm.name.trim() ||
                                nameForm.name === workspaceInfo.name
                            "
                            >Save name</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
