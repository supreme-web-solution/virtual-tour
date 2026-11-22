<script setup lang="ts">
import { Package as HeaderIcon } from '@lucide/vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { formatEntitlement, formatPlanPrice } from '@/lib/billing';
import admin from '@/routes/admin';
import type { AdminPlan, EntitlementDefinition } from '@/types';

const props = defineProps<{
    plans: AdminPlan[];
    entitlementDefinitions: EntitlementDefinition[];
    defaults: {
        currency: string;
        entitlements: Record<string, number | boolean | null>;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Plans', href: admin.plans.index() },
        ],
    },
});

const page = usePage();
const planError = computed(
    () => (page.props.errors as Record<string, string>).plan,
);

const limitDefinitions = computed(() =>
    props.entitlementDefinitions.filter((definition) => !definition.feature),
);
const featureDefinitions = computed(() =>
    props.entitlementDefinitions.filter((definition) => definition.feature),
);

/**
 * Limits are edited as text ("" = unlimited) and features as booleans.
 */
const form = useForm({
    name: '',
    slug: '',
    description: '',
    price: '0',
    currency: 'USD',
    is_default: false,
    is_active: true,
    sort_order: 0,
    limits: {} as Record<string, string>,
    features: {} as Record<string, boolean>,
});

const errors = computed(() => form.errors as Record<string, string>);
const editing = ref<AdminPlan | null>(null);
const formOpen = ref(false);

function fill(
    plan: AdminPlan | null,
    source: Record<string, number | boolean | null>,
): void {
    form.clearErrors();
    form.name = plan?.name ?? '';
    form.slug = plan?.slug ?? '';
    form.description = plan?.description ?? '';
    form.price = plan
        ? plan.price_monthly === null
            ? ''
            : String(plan.price_monthly / 100)
        : '0';
    form.currency = plan?.currency ?? props.defaults.currency;
    form.is_default = plan?.is_default ?? false;
    form.is_active = plan?.is_active ?? true;
    form.sort_order = plan?.sort_order ?? 0;
    form.limits = Object.fromEntries(
        limitDefinitions.value.map((definition) => [
            definition.key,
            source[definition.key] === null ||
            source[definition.key] === undefined
                ? ''
                : String(source[definition.key]),
        ]),
    );
    form.features = Object.fromEntries(
        featureDefinitions.value.map((definition) => [
            definition.key,
            source[definition.key] === true,
        ]),
    );
}

function openCreate(): void {
    editing.value = null;
    fill(null, props.defaults.entitlements);
    formOpen.value = true;
}

function openEdit(plan: AdminPlan): void {
    editing.value = plan;
    fill(plan, plan.entitlements);
    formOpen.value = true;
}

function slugify(): void {
    if (!editing.value && form.slug === '') {
        form.slug = form.name
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    }
}

function submit(): void {
    const target = editing.value
        ? admin.plans.update(editing.value.id)
        : admin.plans.store();

    form.transform((data) => ({
        name: data.name,
        slug: data.slug,
        description: data.description || null,
        price_monthly:
            data.price.trim() === ''
                ? null
                : Math.round(Number(data.price) * 100),
        currency: data.currency,
        is_default: data.is_default,
        is_active: data.is_active,
        sort_order: Number(data.sort_order) || 0,
        entitlements: {
            ...Object.fromEntries(
                Object.entries(data.limits).map(([key, value]) => [
                    key,
                    value.trim() === '' ? null : Number(value),
                ]),
            ),
            ...data.features,
        },
    })).submit(target, {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
    });
}

const deleting = ref<AdminPlan | null>(null);

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    router.delete(admin.plans.destroy(deleting.value.id).url, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
        },
    });
}
</script>

<template>
    <Head title="Plans · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Plans"
            :icon="HeaderIcon"
            tone="emerald"
            description="Limits and features apply to every workspace on a plan as soon as you save. Checkout is not built yet: assign plans from a workspace's page."
        >
            <template #actions>
                <Button @click="openCreate"><Plus /> New plan</Button>
            </template>
        </PageHeader>

        <p
            v-if="planError"
            class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
        >
            {{ planError }}
        </p>

        <div class="overflow-x-auto rounded-lg border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="sticky left-0 bg-card px-4 py-2"></th>
                        <th
                            v-for="plan in plans"
                            :key="plan.id"
                            class="min-w-36 px-4 py-3 align-top font-normal"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{ plan.name }}
                                    </p>
                                    <p>
                                        {{
                                            formatPlanPrice(
                                                plan.price_monthly,
                                                plan.currency,
                                            )
                                        }}<template v-if="plan.price_monthly">
                                            / month</template
                                        >
                                    </p>
                                    <p class="mt-1 space-x-1">
                                        <span
                                            v-if="plan.is_default"
                                            class="rounded bg-primary/10 px-1.5 py-0.5 text-[11px] text-primary"
                                            >Default</span
                                        >
                                        <span
                                            v-if="!plan.is_active"
                                            class="rounded bg-muted px-1.5 py-0.5 text-[11px]"
                                            >Inactive</span
                                        >
                                    </p>
                                </div>
                                <div class="flex shrink-0">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-7"
                                        :aria-label="`Edit ${plan.name}`"
                                        @click="openEdit(plan)"
                                        ><Pencil
                                    /></Button>
                                    <Button
                                        v-if="!plan.is_default"
                                        size="icon"
                                        variant="ghost"
                                        class="size-7"
                                        :aria-label="`Delete ${plan.name}`"
                                        @click="deleting = plan"
                                        ><Trash2
                                    /></Button>
                                </div>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b text-muted-foreground">
                        <td class="sticky left-0 bg-card px-4 py-2">
                            Workspaces on plan
                        </td>
                        <td
                            v-for="plan in plans"
                            :key="plan.id"
                            class="px-4 py-2 tabular-nums"
                        >
                            {{ plan.workspaces ?? 0 }}
                        </td>
                    </tr>
                    <tr
                        v-for="definition in entitlementDefinitions"
                        :key="definition.key"
                        class="border-b last:border-0"
                    >
                        <td
                            class="sticky left-0 bg-card px-4 py-2 whitespace-nowrap"
                        >
                            {{ definition.label }}
                        </td>
                        <td
                            v-for="plan in plans"
                            :key="plan.id"
                            class="px-4 py-2 tabular-nums"
                        >
                            {{
                                formatEntitlement(
                                    definition.key,
                                    plan.entitlements[definition.key] ??
                                        (definition.feature ? false : null),
                                )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-if="!plans.length"
                class="p-8 text-center text-sm text-muted-foreground"
            >
                No plans yet, so nothing is limited. Run the plan seeder or
                create a plan.
            </p>
        </div>

        <!-- Create / edit -->
        <Dialog v-model:open="formOpen">
            <DialogContent
                class="max-h-[90dvh] gap-5 overflow-y-auto sm:max-w-3xl"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? `Edit ${editing.name}` : 'New plan'
                    }}</DialogTitle>
                    <DialogDescription>
                        Changes apply to every workspace on this plan straight
                        away. Existing content is never deleted when a limit is
                        lowered; workspaces just can't add more.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-6" @submit.prevent="submit">
                    <section class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="plan-name">Name</Label>
                            <Input
                                id="plan-name"
                                v-model="form.name"
                                maxlength="80"
                                @blur="slugify"
                            />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="plan-slug">Slug</Label>
                            <Input
                                id="plan-slug"
                                v-model="form.slug"
                                maxlength="60"
                            />
                            <InputError :message="form.errors.slug" />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="plan-description">Description</Label>
                            <Textarea
                                id="plan-description"
                                v-model="form.description"
                                rows="2"
                                maxlength="500"
                            />
                        </div>
                        <div class="grid grid-cols-[minmax(0,1fr)_6rem] gap-2">
                            <div class="grid gap-1.5">
                                <Label for="plan-price">Monthly price</Label>
                                <Input
                                    id="plan-price"
                                    v-model="form.price"
                                    inputmode="decimal"
                                    placeholder="Empty = contact us"
                                />
                                <InputError :message="errors.price_monthly" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="plan-currency">Currency</Label>
                                <Input
                                    id="plan-currency"
                                    v-model="form.currency"
                                    maxlength="3"
                                    class="uppercase"
                                />
                                <InputError :message="form.errors.currency" />
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="plan-sort">Display order</Label>
                            <Input
                                id="plan-sort"
                                v-model.number="form.sort_order"
                                type="number"
                                min="0"
                                max="1000"
                            />
                        </div>
                        <label
                            class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2 text-sm"
                        >
                            <span>Active</span>
                            <Switch
                                :model-value="form.is_active"
                                @update:model-value="
                                    form.is_active = $event === true
                                "
                            />
                        </label>
                        <div>
                            <label
                                class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2 text-sm"
                            >
                                <span>Default plan</span>
                                <Switch
                                    :model-value="form.is_default"
                                    @update:model-value="
                                        form.is_default = $event === true
                                    "
                                />
                            </label>
                            <InputError :message="errors.is_default" />
                        </div>
                    </section>

                    <section class="grid gap-3">
                        <div>
                            <h3 class="text-sm font-medium">Limits</h3>
                            <p class="text-xs text-muted-foreground">
                                Leave a field empty for unlimited.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="definition in limitDefinitions"
                                :key="definition.key"
                                class="grid gap-1.5"
                            >
                                <Label :for="`limit-${definition.key}`">{{
                                    definition.label
                                }}</Label>
                                <Input
                                    :id="`limit-${definition.key}`"
                                    v-model="form.limits[definition.key]"
                                    type="number"
                                    min="0"
                                    placeholder="Unlimited"
                                />
                                <InputError
                                    :message="
                                        errors[`entitlements.${definition.key}`]
                                    "
                                />
                            </div>
                        </div>
                    </section>

                    <section class="grid gap-3">
                        <h3 class="text-sm font-medium">Features</h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="definition in featureDefinitions"
                                :key="definition.key"
                                class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2 text-sm"
                            >
                                <span>{{ definition.label }}</span>
                                <Switch
                                    :model-value="form.features[definition.key]"
                                    @update:model-value="
                                        form.features[definition.key] =
                                            $event === true
                                    "
                                />
                            </label>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Custom domain and API access are reserved for
                            upcoming features.
                        </p>
                    </section>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="formOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="form.processing">{{
                            editing ? 'Save plan' : 'Create plan'
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
                        The plan is removed for good. Plans with subscriptions
                        can't be deleted; deactivate them instead.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="deleting = null"
                        >Cancel</Button
                    >
                    <Button variant="destructive" @click="confirmDelete"
                        >Delete plan</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
