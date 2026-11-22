<script setup lang="ts">
import { Users as HeaderIcon } from '@lucide/vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    UserCheck,
    UserX,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AvatarChip from '@/components/AvatarChip.vue';
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
import { Switch } from '@/components/ui/switch';
import { useListFilters } from '@/composables/useListFilters';
import { formatDate } from '@/lib/format';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    from_env: boolean;
    suspended: boolean;
    verified: boolean;
    two_factor: boolean;
    workspaces_count: number;
    workspaces: { name: string; role: string; plan: string | null }[];
    created_at: string | null;
    is_you: boolean;
    can: { impersonate: boolean; suspend: boolean; delete: boolean };
};

type Status = 'all' | 'active' | 'suspended' | 'unverified' | 'admins';

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: { q: string; status: Status };
    stats: {
        total: number;
        active: number;
        suspended: number;
        unverified: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Users', href: admin.users.index() },
        ],
    },
});

const filters = useListFilters({ ...props.filters }, admin.users.index.url);

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'unverified', label: 'Unverified' },
    { value: 'admins', label: 'Administrators' },
];

const cards = computed(() => [
    { key: 'all', label: 'Total users', value: props.stats.total },
    { key: 'active', label: 'Active', value: props.stats.active },
    { key: 'suspended', label: 'Suspended', value: props.stats.suspended },
    { key: 'unverified', label: 'Unverified', value: props.stats.unverified },
]);

function pickStatus(key: string): void {
    filters.status = key as Status;
}

/* Create / edit */
const editing = ref<AdminUser | null>(null);
const formOpen = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    verified: true,
    is_admin: false,
});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.verified = true;
    formOpen.value = true;
}

function openEdit(user: AdminUser): void {
    editing.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.verified = user.verified;
    form.is_admin = user.is_admin;
    formOpen.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
    };

    if (editing.value) {
        form.patch(admin.users.update(editing.value.id).url, options);
    } else {
        form.post(admin.users.store().url, options);
    }
}

/* Suspend, sign in as, delete */
function toggleSuspension(user: AdminUser): void {
    const verb = user.suspended ? 'Reinstate' : 'Suspend';

    if (confirm(`${verb} ${user.email}?`)) {
        router.patch(
            admin.users.suspension(user.id).url,
            { suspended: !user.suspended },
            { preserveScroll: true },
        );
    }
}

function signInAs(user: AdminUser): void {
    if (
        confirm(
            `Sign in as ${user.email}? You can return to your account from the banner at the top.`,
        )
    ) {
        router.post(admin.users.impersonate(user.id).url);
    }
}

const deleting = ref<AdminUser | null>(null);

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    router.delete(admin.users.destroy(deleting.value.id).url, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
        },
    });
}

function statusFor(user: AdminUser): { label: string; classes: string } {
    if (user.suspended) {
        return {
            label: 'Suspended',
            classes:
                'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
        };
    }

    if (!user.verified) {
        return {
            label: 'Unverified',
            classes:
                'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
        };
    }

    return {
        label: 'Active',
        classes:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
    };
}
</script>

<template>
    <Head title="Users · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Users"
            :icon="HeaderIcon"
            tone="sky"
            description="Create, edit, suspend, sign in as any user, and manage administrator access."
        >
            <template #actions>
                <Button @click="openCreate"><Plus /> New user</Button>
            </template>
        </PageHeader>

        <!-- Counts (click one to filter) -->
        <section
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
            aria-label="Account counts"
        >
            <button
                v-for="card in cards"
                :key="card.key"
                type="button"
                class="rounded-2xl border bg-card p-4 text-left shadow-sm transition-shadow hover:shadow-md"
                :class="
                    filters.status === card.key ||
                    (card.key === 'all' && filters.status === 'all')
                        ? 'ring-2 ring-primary'
                        : ''
                "
                @click="pickStatus(card.key)"
            >
                <p class="text-xs font-medium text-muted-foreground">
                    {{ card.label }}
                </p>
                <p
                    class="mt-1 text-3xl font-semibold tracking-tight tabular-nums"
                >
                    {{ card.value }}
                </p>
            </button>
        </section>

        <!-- Search -->
        <div class="flex flex-wrap items-center gap-3">
            <label class="relative min-w-60 flex-1">
                <span class="sr-only">Search users</span>
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="filters.q"
                    placeholder="Search name or email"
                    class="h-10 rounded-xl pl-9"
                />
            </label>
            <NativeSelect
                v-model="filters.status"
                class="w-48"
                placeholder="All users"
                :options="statusOptions"
                aria-label="Filter by status"
                @update:model-value="
                    filters.status = ($event || 'all') as Status
                "
            />
        </div>

        <!-- Table -->
        <section class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                        >
                            <th class="px-5 py-3 font-medium">User</th>
                            <th class="px-3 py-3 font-medium">Teams</th>
                            <th class="px-3 py-3 font-medium">Joined</th>
                            <th class="px-3 py-3 font-medium">Status</th>
                            <th class="px-3 py-3 font-medium">Access</th>
                            <th class="px-5 py-3 text-right font-medium">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="user in users.data" :key="user.id">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <AvatarChip
                                        :name="user.name"
                                        :seed="user.email"
                                    />
                                    <div class="min-w-0">
                                        <p
                                            class="flex items-center gap-1.5 font-medium"
                                        >
                                            {{ user.name }}
                                            <ShieldCheck
                                                v-if="user.is_admin"
                                                class="size-3.5 text-emerald-600"
                                                aria-label="Administrator"
                                            />
                                            <span
                                                v-if="user.is_you"
                                                class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                                >You</span
                                            >
                                        </p>
                                        <p
                                            class="max-w-60 truncate text-xs text-muted-foreground"
                                        >
                                            {{ user.email }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-4 tabular-nums">
                                {{ user.workspaces_count }}
                            </td>
                            <td
                                class="px-3 py-4 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDate(user.created_at) }}
                            </td>
                            <td class="px-3 py-4">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="statusFor(user).classes"
                                    >{{ statusFor(user).label }}</span
                                >
                            </td>
                            <td class="px-3 py-4">
                                <div class="flex max-w-xs flex-wrap gap-1.5">
                                    <span
                                        v-if="user.is_admin"
                                        class="rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-500/20 dark:text-violet-200"
                                        >{{
                                            user.from_env
                                                ? 'Admin · ADMIN_EMAILS'
                                                : 'Admin'
                                        }}</span
                                    >
                                    <span
                                        v-for="workspace in user.workspaces.slice(
                                            0,
                                            3,
                                        )"
                                        :key="workspace.name"
                                        class="rounded-full bg-muted px-2.5 py-0.5 text-xs"
                                        :title="`${workspace.name} · ${workspace.role}`"
                                    >
                                        {{ workspace.plan ?? 'No plan' }}
                                    </span>
                                    <span
                                        v-if="user.workspaces.length > 3"
                                        class="rounded-full bg-muted px-2.5 py-0.5 text-xs text-muted-foreground"
                                        >+{{ user.workspaces.length - 3 }}</span
                                    >
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="openEdit(user)"
                                    >
                                        <Pencil /> Edit
                                    </Button>
                                    <Button
                                        v-if="user.can.impersonate"
                                        size="sm"
                                        variant="outline"
                                        @click="signInAs(user)"
                                    >
                                        Sign in as
                                    </Button>
                                    <Button
                                        v-if="user.can.suspend"
                                        size="sm"
                                        variant="outline"
                                        @click="toggleSuspension(user)"
                                    >
                                        <component
                                            :is="
                                                user.suspended
                                                    ? UserCheck
                                                    : UserX
                                            "
                                        />
                                        {{
                                            user.suspended
                                                ? 'Reinstate'
                                                : 'Suspend'
                                        }}
                                    </Button>
                                    <Button
                                        v-if="user.can.delete"
                                        size="sm"
                                        variant="destructive"
                                        @click="deleting = user"
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!users.data.length">
                            <td
                                colspan="6"
                                class="px-5 py-12 text-center text-muted-foreground"
                            >
                                <Users class="mx-auto mb-2 size-6" />
                                No users match.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <PaginationLinks :paginator="users" />

        <!-- Create / edit -->
        <Dialog v-model:open="formOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? 'Edit user' : 'New user'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? 'Change their details, reset the password or adjust access.'
                                : 'Creates the account and its personal workspace.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="user-name">Name</Label>
                        <Input
                            id="user-name"
                            v-model="form.name"
                            required
                            maxlength="255"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user-email">Email</Label>
                        <Input
                            id="user-email"
                            v-model="form.email"
                            type="email"
                            required
                            maxlength="255"
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user-password">{{
                            editing ? 'New password' : 'Password'
                        }}</Label>
                        <Input
                            id="user-password"
                            v-model="form.password"
                            type="password"
                            :required="!editing"
                            autocomplete="new-password"
                            :placeholder="
                                editing
                                    ? 'Leave blank to keep the current one'
                                    : ''
                            "
                        />
                        <InputError :message="form.errors.password" />
                    </div>
                    <label
                        class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2 text-sm"
                    >
                        <span>Email verified</span>
                        <Switch
                            :model-value="form.verified"
                            @update:model-value="
                                form.verified = $event === true
                            "
                        />
                    </label>
                    <label
                        class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2 text-sm"
                        :class="
                            editing?.from_env || editing?.is_you
                                ? 'opacity-60'
                                : ''
                        "
                    >
                        <span>
                            Platform administrator
                            <span
                                v-if="editing?.from_env"
                                class="block text-xs text-muted-foreground"
                                >Set in ADMIN_EMAILS</span
                            >
                        </span>
                        <Switch
                            :model-value="form.is_admin"
                            :disabled="!!editing?.from_env || !!editing?.is_you"
                            @update:model-value="
                                form.is_admin = $event === true
                            "
                        />
                    </label>
                    <InputError :message="form.errors.is_admin" />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="formOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            editing ? 'Save changes' : 'Create user'
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete -->
        <Dialog
            :open="deleting !== null"
            @update:open="(open) => !open && (deleting = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete {{ deleting?.name }}?</DialogTitle>
                    <DialogDescription>
                        This permanently deletes {{ deleting?.email }} and the
                        workspaces they own, including their tours, media and
                        videos. It cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null"
                        >Cancel</Button
                    >
                    <Button variant="destructive" @click="confirmDelete"
                        >Delete account</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
