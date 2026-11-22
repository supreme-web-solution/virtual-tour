<script setup lang="ts">
import { Palette as HeaderIcon } from '@lucide/vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch';
import {
    Check,
    CircleCheck,
    Clock,
    Copy,
    Globe,
    Store,
    RefreshCw,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeAgo } from '@/lib/format';
import { index as brandIndex, update as updateBrand } from '@/routes/brand';
import { update as updateChannel } from '@/routes/brand/channel';
import {
    destroy as destroyDomain,
    store as storeDomain,
    verify as verifyDomain,
} from '@/routes/brand/domain';

type DomainInfo = {
    host: string;
    status: 'pending' | 'verified' | 'failed';
    txt_name: string;
    txt_value: string;
    last_error: string | null;
    last_checked_at: string | null;
    verified_at: string | null;
    is_serving: boolean;
    url: string;
    cloudflare: {
        status: string | null;
        ssl_status: string | null;
        https_live: boolean;
        ownership_txt_name: string | null;
        ownership_txt_value: string | null;
        errors: string[];
    } | null;
};

const props = defineProps<{
    brand: {
        display_name: string | null;
        logo_asset_id: string | null;
        primary_color: string | null;
        website: string | null;
        ga4_id: string | null;
        gtm_id: string | null;
    };
    workspaceName: string;
    logos: {
        id: string;
        name: string | null;
        thumbnail_url: string | null;
        is_logo: boolean;
    }[];
    domain: DomainInfo | null;
    dns: { cname_target: string; a_record: string | null };
    features: {
        white_label: boolean;
        custom_domain: boolean;
        tracking: boolean;
    };
    channel: { enabled: boolean; slug: string | null; url: string | null };
    publicBase: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Brand & domain', href: brandIndex() }],
    },
});

const page = usePage();

// Separate refs rather than a form object: vue-tsc mis-types form fields
// used inside this template (see the project memory note).
const displayName = ref(props.brand.display_name ?? '');
const logoId = ref(props.brand.logo_asset_id ?? '');
const primaryColor = ref(props.brand.primary_color ?? '');
const website = ref(props.brand.website ?? '');
const ga4Id = ref(props.brand.ga4_id ?? '');
const gtmId = ref(props.brand.gtm_id ?? '');
const host = ref('');
const channelEnabled = ref(props.channel.enabled);
const channelSlug = ref(props.channel.slug ?? '');
const savingChannel = ref(false);
const savingBrand = ref(false);
const connecting = ref(false);
const checking = ref(false);
const copied = ref<string | null>(null);

const errors = computed(
    () => page.props.errors as Record<string, string | undefined>,
);
const brandDirty = computed(
    () =>
        displayName.value !== (props.brand.display_name ?? '') ||
        logoId.value !== (props.brand.logo_asset_id ?? '') ||
        primaryColor.value !== (props.brand.primary_color ?? '') ||
        website.value !== (props.brand.website ?? '') ||
        ga4Id.value !== (props.brand.ga4_id ?? '') ||
        gtmId.value !== (props.brand.gtm_id ?? ''),
);
const previewName = computed(
    () => displayName.value.trim() || props.workspaceName,
);
const previewLogo = computed(
    () =>
        props.logos.find((logo) => logo.id === logoId.value)?.thumbnail_url ??
        null,
);
const platformName = computed(() => String(page.props.name));
const previewSite = computed(() =>
    props.features.white_label ? previewName.value : platformName.value,
);
const domainError = computed(() => errors.value.host ?? errors.value.plan);
const canConnect = computed(
    () => !connecting.value && host.value.trim() !== '',
);

function pickColor(event: Event): void {
    primaryColor.value = (event.target as HTMLInputElement).value;
}

function saveBrand(): void {
    savingBrand.value = true;
    router.patch(
        updateBrand().url,
        {
            display_name: displayName.value || null,
            logo_asset_id: logoId.value || null,
            primary_color: primaryColor.value || null,
            website: website.value || null,
            ga4_id: ga4Id.value.trim() || null,
            gtm_id: gtmId.value.trim() || null,
        },
        { preserveScroll: true, onFinish: () => (savingBrand.value = false) },
    );
}

function saveChannel(): void {
    savingChannel.value = true;
    router.patch(
        updateChannel().url,
        {
            channel_enabled: channelEnabled.value,
            channel_slug: channelSlug.value.trim().toLowerCase() || null,
        },
        { preserveScroll: true, onFinish: () => (savingChannel.value = false) },
    );
}

function connect(): void {
    connecting.value = true;
    router.post(
        storeDomain().url,
        { host: host.value },
        {
            preserveScroll: true,
            onSuccess: () => (host.value = ''),
            onFinish: () => (connecting.value = false),
        },
    );
}

function check(): void {
    checking.value = true;
    router.post(
        verifyDomain().url,
        {},
        { preserveScroll: true, onFinish: () => (checking.value = false) },
    );
}

function remove(): void {
    if (
        confirm(
            `Remove ${props.domain?.host}? Links that use it will stop working.`,
        )
    ) {
        router.delete(destroyDomain().url, { preserveScroll: true });
    }
}

async function copy(value: string): Promise<void> {
    await navigator.clipboard.writeText(value);
    copied.value = value;
    setTimeout(() => (copied.value = null), 1500);
}

const httpsReady = computed(
    () =>
        !!props.domain?.is_serving &&
        (!props.domain.cloudflare || props.domain.cloudflare.https_live),
);

const records = computed(() =>
    props.domain
        ? [
              {
                  type: 'TXT',
                  name: props.domain.txt_name,
                  value: props.domain.txt_value,
                  note: 'Proves you own the domain.',
              },
              {
                  type: 'CNAME',
                  name: props.domain.host,
                  value: props.dns.cname_target,
                  note: props.dns.a_record
                      ? `Or an A record to ${props.dns.a_record} for a root domain.`
                      : 'Points the domain at the platform.',
              },
              ...(props.domain.cloudflare?.ownership_txt_name &&
              props.domain.cloudflare.ownership_txt_value
                  ? [
                        {
                            type: 'TXT',
                            name: props.domain.cloudflare.ownership_txt_name,
                            value: props.domain.cloudflare.ownership_txt_value,
                            note: 'Lets the certificate be issued. Needed only if it stays pending.',
                        },
                    ]
                  : []),
          ]
        : [],
);
</script>

<template>
    <Head title="Brand & domain" />

    <div class="flex max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Brand & domain"
            :icon="HeaderIcon"
            tone="rose"
            description="How your workspace appears to visitors on public tour and video pages."
        />

        <section
            class="grid gap-6 rounded-lg border bg-card p-4 shadow-sm lg:grid-cols-[minmax(0,1fr)_18rem]"
        >
            <div class="space-y-4">
                <div>
                    <h2 class="font-medium">Brand</h2>
                    <p class="text-sm text-muted-foreground">
                        Used on public pages and as the default logo for tours
                        that don't set their own.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="display-name">Brand name</Label>
                        <Input
                            id="display-name"
                            v-model="displayName"
                            maxlength="80"
                            :placeholder="workspaceName"
                        />
                        <InputError :message="errors.display_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="website">Website</Label>
                        <Input
                            id="website"
                            v-model="website"
                            type="url"
                            placeholder="https://youragency.com"
                        />
                        <InputError :message="errors.website" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="color">Brand colour</Label>
                        <div class="flex gap-2">
                            <input
                                id="color"
                                type="color"
                                :value="primaryColor || '#2563eb'"
                                class="h-9 w-12 cursor-pointer rounded-md border bg-transparent p-1"
                                aria-label="Pick brand colour"
                                @input="pickColor"
                            />
                            <Input
                                v-model="primaryColor"
                                placeholder="#2563eb"
                                maxlength="7"
                                aria-label="Brand colour hex code"
                            />
                        </div>
                        <InputError :message="errors.primary_color" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label>Logo</Label>
                    <div
                        v-if="logos.length"
                        class="flex flex-wrap gap-2"
                        role="radiogroup"
                        aria-label="Logo"
                    >
                        <button
                            type="button"
                            class="flex h-14 w-20 items-center justify-center rounded-md border text-xs text-muted-foreground"
                            :class="!logoId ? 'ring-2 ring-primary' : ''"
                            role="radio"
                            :aria-checked="!logoId"
                            @click="logoId = ''"
                        >
                            None
                        </button>
                        <button
                            v-for="logo in logos"
                            :key="logo.id"
                            type="button"
                            class="h-14 w-20 overflow-hidden rounded-md border bg-muted"
                            :class="
                                logoId === logo.id ? 'ring-2 ring-primary' : ''
                            "
                            role="radio"
                            :aria-checked="logoId === logo.id"
                            :title="logo.name ?? undefined"
                            @click="logoId = logo.id"
                        >
                            <img
                                v-if="logo.thumbnail_url"
                                :src="logo.thumbnail_url"
                                :alt="logo.name ?? 'Logo'"
                                class="size-full object-contain"
                            />
                        </button>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        Upload a logo in the media library first.
                    </p>
                    <InputError :message="errors.logo_asset_id" />
                </div>

                <div v-if="features.tracking" class="space-y-3 border-t pt-4">
                    <div>
                        <h3 class="text-sm font-medium">Tracking</h3>
                        <p class="text-sm text-muted-foreground">
                            Send visits on your public tours to your own Google
                            Analytics or Tag Manager. Applies to every published
                            tour, including embeds.
                        </p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="ga4-id">GA4 measurement ID</Label>
                            <Input
                                id="ga4-id"
                                v-model="ga4Id"
                                maxlength="20"
                                placeholder="G-XXXXXXXXXX"
                                :disabled="!features.tracking"
                            />
                            <InputError :message="errors.ga4_id" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="gtm-id">Tag Manager container ID</Label>
                            <Input
                                id="gtm-id"
                                v-model="gtmId"
                                maxlength="20"
                                placeholder="GTM-XXXXXXX"
                                :disabled="!features.tracking"
                            />
                            <InputError :message="errors.gtm_id" />
                        </div>
                    </div>
                </div>

                <Button
                    :disabled="savingBrand || !brandDirty"
                    @click="saveBrand"
                    >Save brand</Button
                >
            </div>

            <aside class="space-y-3">
                <p class="text-xs font-medium text-muted-foreground uppercase">
                    Preview
                </p>
                <div
                    class="overflow-hidden rounded-lg border bg-card shadow-sm"
                >
                    <div
                        class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-white"
                        :style="{
                            backgroundColor: primaryColor || '#111827',
                        }"
                    >
                        <img
                            v-if="previewLogo"
                            :src="previewLogo"
                            alt=""
                            class="h-5 w-auto rounded-sm bg-white/90 object-contain"
                        />
                        {{ previewName }}
                    </div>
                    <div class="space-y-1 p-3 text-xs text-muted-foreground">
                        <p>Seaside Villa – {{ previewSite }}</p>
                        <p v-if="!features.white_label">
                            Powered by {{ platformName }}
                        </p>
                    </div>
                </div>
            </aside>
        </section>

        <section class="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
            <div>
                <h2 class="flex items-center gap-2 font-medium">
                    <Store class="size-4" /> Channel page
                </h2>
                <p class="text-sm text-muted-foreground">
                    A public gallery of all your published tours and videos,
                    with search and sorting. Unlisted and private items never
                    appear.
                </p>
            </div>

            <label
                class="flex max-w-md items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Show my channel page publicly</span
                ><Switch v-model="channelEnabled"
            /></label>

            <div class="grid max-w-md gap-2">
                <Label for="channel-slug">Channel address</Label>
                <div
                    class="flex items-center gap-1 text-sm text-muted-foreground"
                >
                    <span class="font-mono">{{ publicBase }}/c/</span>
                    <Input
                        id="channel-slug"
                        v-model="channelSlug"
                        placeholder="my-agency"
                        maxlength="40"
                        class="max-w-48"
                    />
                </div>
                <InputError :message="errors.channel_slug" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button :disabled="savingChannel" @click="saveChannel"
                    >Save channel</Button
                >
                <a
                    v-if="channel.enabled && channel.url"
                    :href="channel.url"
                    target="_blank"
                    rel="noopener"
                    class="text-sm underline underline-offset-4"
                    >View channel page</a
                >
            </div>
        </section>

        <section
            v-if="features.custom_domain || domain"
            class="space-y-4 rounded-lg border bg-card p-4 shadow-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="flex items-center gap-2 font-medium">
                        <Globe class="size-4" /> Custom domain
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Share tours and videos on your own address, for example
                        <span class="font-mono">tours.youragency.com</span>.
                        Links currently start with
                        <span class="font-mono">{{ publicBase }}</span
                        >.
                    </p>
                </div>
            </div>

            <form
                v-if="!domain"
                class="flex flex-col gap-2 sm:flex-row"
                @submit.prevent="connect"
            >
                <div class="flex-1">
                    <Input
                        v-model="host"
                        placeholder="tours.youragency.com"
                        aria-label="Domain"
                        autocapitalize="off"
                        spellcheck="false"
                    />
                    <InputError :message="domainError" class="mt-1" />
                </div>
                <Button type="submit" :disabled="!canConnect">
                    Connect domain
                </Button>
            </form>

            <template v-else>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3"
                >
                    <div class="flex items-center gap-2">
                        <CircleCheck
                            v-if="
                                domain.is_serving &&
                                (!domain.cloudflare ||
                                    domain.cloudflare.https_live)
                            "
                            class="size-5 text-emerald-600"
                        />
                        <TriangleAlert
                            v-else-if="
                                domain.status === 'failed' ||
                                (domain.status === 'verified' &&
                                    !domain.is_serving)
                            "
                            class="size-5 text-red-600"
                        />
                        <Clock v-else class="size-5 text-amber-600" />
                        <div>
                            <p class="font-mono text-sm">{{ domain.host }}</p>
                            <p class="text-xs text-muted-foreground">
                                <template
                                    v-if="
                                        domain.is_serving &&
                                        domain.cloudflare &&
                                        !domain.cloudflare.https_live
                                    "
                                    >Verified · setting up the secure (HTTPS)
                                    certificate</template
                                >
                                <template v-else-if="domain.is_serving"
                                    >Connected · share links use this
                                    domain</template
                                >
                                <template
                                    v-else-if="domain.status === 'verified'"
                                    >Verified, but your plan no longer includes
                                    custom domains</template
                                >
                                <template v-else-if="domain.status === 'failed'"
                                    >Stopped working · DNS records are
                                    missing</template
                                >
                                <template v-else>Waiting for DNS</template>
                                <template v-if="domain.last_checked_at">
                                    · checked
                                    {{
                                        timeAgo(domain.last_checked_at)
                                    }}</template
                                >
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="checking"
                            @click="check"
                        >
                            <RefreshCw
                                :class="checking ? 'animate-spin' : ''"
                            />
                            Check now
                        </Button>
                        <Button size="sm" variant="ghost" @click="remove"
                            >Remove</Button
                        >
                    </div>
                </div>

                <p
                    v-for="message in domain.cloudflare?.errors ?? []"
                    :key="message"
                    class="text-sm text-red-700 dark:text-red-300"
                >
                    Cloudflare: {{ message }}
                </p>
                <p
                    v-if="domain.last_error && !domain.is_serving"
                    class="text-sm text-red-700 dark:text-red-300"
                >
                    {{ domain.last_error }}
                </p>

                <div v-if="!httpsReady" class="space-y-2">
                    <p class="text-sm">
                        Add these records at your domain provider:
                    </p>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead
                                class="text-left text-xs text-muted-foreground"
                            >
                                <tr class="border-b">
                                    <th class="px-3 py-2 font-medium">Type</th>
                                    <th class="px-3 py-2 font-medium">Name</th>
                                    <th class="px-3 py-2 font-medium">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="record in records"
                                    :key="record.type + record.name"
                                    class="border-b align-top last:border-0"
                                >
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ record.type }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <button
                                            type="button"
                                            class="flex items-center gap-1 font-mono text-xs break-all hover:underline"
                                            @click="copy(record.name)"
                                        >
                                            {{ record.name }}
                                            <Check
                                                v-if="copied === record.name"
                                                class="size-3 shrink-0"
                                            />
                                            <Copy
                                                v-else
                                                class="size-3 shrink-0 opacity-50"
                                            />
                                        </button>
                                    </td>
                                    <td class="px-3 py-2">
                                        <button
                                            type="button"
                                            class="flex items-center gap-1 font-mono text-xs break-all hover:underline"
                                            @click="copy(record.value)"
                                        >
                                            {{ record.value }}
                                            <Check
                                                v-if="copied === record.value"
                                                class="size-3 shrink-0"
                                            />
                                            <Copy
                                                v-else
                                                class="size-3 shrink-0 opacity-50"
                                            />
                                        </button>
                                        <p
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ record.note }}
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        DNS changes can take up to an hour. We check
                        automatically every 30 minutes; a secure (HTTPS)
                        certificate is issued once the domain is verified.
                    </p>
                </div>

                <a
                    v-else
                    :href="domain.url"
                    target="_blank"
                    rel="noopener"
                    class="inline-block text-sm underline"
                >
                    Open {{ domain.host }}
                </a>
            </template>
        </section>
    </div>
</template>
