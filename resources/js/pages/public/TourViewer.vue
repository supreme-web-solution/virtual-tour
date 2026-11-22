<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    FileText,
    Flag,
    Info,
    LayoutPanelTop,
    Loader2,
    Mail,
    Orbit,
    Share2,
    Volume2,
    VolumeX,
    X,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import PublicTourViewer from '@/components/tour/PublicTourViewer.vue';
import TourFloorplanView from '@/components/tour/public/TourFloorplanView.vue';
import TourLeadForm from '@/components/tour/public/TourLeadForm.vue';
import { useTourAnalytics } from '@/composables/useTourAnalytics';
import { useTourAudio } from '@/composables/useTourAudio';
import type { TrackingIds } from '@/composables/useTrackingScripts';
import { useTrackingScripts } from '@/composables/useTrackingScripts';
import { useViewerPrefs } from '@/composables/useViewerPrefs';
import { reports as reportsRoute } from '@/routes/tours/public';
import type { PublicTour, TourHotspot } from '@/types';

const props = defineProps<{
    tour: PublicTour;
    embed: boolean;
    preview: boolean;
    /** MLS-ready link: no branding, contact details, enquiry form, share or report. */
    unbranded?: boolean;
    tracking?: TrackingIds | null;
}>();

useTrackingScripts(props.tracking ?? null);

type Panel = 'floorplan' | 'info' | 'lead' | 'share' | 'scene' | 'report';

const page = usePage();
const viewerRef = ref<InstanceType<typeof PublicTourViewer> | null>(null);
const currentSceneId = ref<string | null>(null);
const panel = ref<Panel | null>(null);
const introOpen = ref(props.tour.branding.intro.enabled && !props.embed);

const branding = computed(() => props.tour.branding);
const audio = useTourAudio(props.tour);
const analytics = useTourAnalytics({
    publicId: props.tour.id,
    enabled: props.tour.analytics_enabled && !props.preview,
    embed: props.embed,
});

const hasInfo = computed(
    () => Boolean(props.tour.description) || branding.value.contact !== null,
);

const prefs = useViewerPrefs();
const classicLayout = computed(
    () => prefs.layoutFor(props.tour.viewer_settings.layout) === 'classic',
);
const currentScene = computed(
    () =>
        props.tour.scenes.find((scene) => scene.id === currentSceneId.value) ??
        null,
);

// Share: the tour link, and the usual places to send it.
const copied = ref(false);
const shareTargets = computed(() => {
    const url = encodeURIComponent(props.tour.public_url);
    const text = encodeURIComponent(props.tour.title);

    return [
        { label: 'WhatsApp', href: `https://wa.me/?text=${text}%20${url}` },
        {
            label: 'Facebook',
            href: `https://www.facebook.com/sharer/sharer.php?u=${url}`,
        },
        {
            label: 'X',
            href: `https://twitter.com/intent/tweet?url=${url}&text=${text}`,
        },
        {
            label: 'LinkedIn',
            href: `https://www.linkedin.com/sharing/share-offsite/?url=${url}`,
        },
        { label: 'Email', href: `mailto:?subject=${text}&body=${url}` },
    ];
});
const canShareNatively =
    typeof navigator !== 'undefined' && typeof navigator.share === 'function';

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.tour.public_url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard blocked (some embeds): the link is selectable in the field.
    }
}

async function shareNatively(): Promise<void> {
    try {
        await navigator.share({
            title: props.tour.title,
            url: props.tour.public_url,
        });
    } catch {
        // Cancelled by the visitor.
    }
}

// Report this tour.
const reasons = [
    { value: 'illegal', label: 'Illegal content' },
    { value: 'sexual', label: 'Sexually explicit or nude content' },
    { value: 'gambling', label: 'Gambling content' },
    { value: 'violence', label: 'Violence or terrorism' },
    { value: 'harassment', label: 'Harassment or hate speech' },
    { value: 'copyright', label: 'Copyright infringement' },
    { value: 'other', label: 'Other' },
];
const report = reactive({
    reason: '',
    details: '',
    website: '',
    sending: false,
    done: false,
    error: '',
});

async function sendReport(): Promise<void> {
    if (!report.reason || report.sending) {
        return;
    }

    report.sending = true;
    report.error = '';

    try {
        const response = await fetch(reportsRoute.url(props.tour.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                reason: report.reason,
                details: report.details || null,
                website: report.website,
            }),
        });

        if (!response.ok) {
            throw new Error('rejected');
        }

        report.done = true;
    } catch {
        report.error =
            'Your report could not be sent. Please try again in a moment.';
    } finally {
        report.sending = false;
    }
}

const toolbar = computed(() =>
    [
        currentScene.value?.description
            ? {
                  key: 'scene' as const,
                  label: 'About this scene',
                  icon: FileText,
              }
            : null,
        props.unbranded
            ? null
            : { key: 'share' as const, label: 'Share', icon: Share2 },
        props.tour.floorplans.length
            ? {
                  key: 'floorplan' as const,
                  label: 'Floor plan',
                  icon: LayoutPanelTop,
              }
            : null,
        hasInfo.value
            ? { key: 'info' as const, label: 'About', icon: Info }
            : null,
        props.tour.lead
            ? {
                  key: 'lead' as const,
                  label: props.tour.lead.button_label,
                  icon: Mail,
              }
            : null,
        props.unbranded
            ? null
            : {
                  key: 'report' as const,
                  label: 'Report this tour',
                  icon: Flag,
              },
    ].filter((item) => item !== null),
);

function onSceneChange(sceneId: string): void {
    currentSceneId.value = sceneId;
    audio.setScene(
        props.tour.scenes.find((scene) => scene.id === sceneId) ?? null,
    );
    analytics.trackScene(sceneId);
}

function onHotspot(hotspot: TourHotspot, sceneId: string | null): void {
    analytics.trackHotspot(sceneId, hotspot.id, hotspot.type === 'link');
}

function goTo(sceneId: string): void {
    viewerRef.value?.goTo(sceneId);

    if (window.matchMedia('(max-width: 640px)').matches) {
        panel.value = null;
    }
}

function togglePanel(key: Panel): void {
    panel.value = panel.value === key ? null : key;
}

function startTour(withSound: boolean): void {
    introOpen.value = false;

    if (withSound) {
        audio.enable();
    }
}
</script>

<template>
    <Head :title="tour.title" />

    <div
        class="relative h-svh w-full overflow-hidden bg-black text-white"
        :style="{
            '--tour-primary': branding.primary_color,
            '--tour-accent': branding.accent_color,
        }"
    >
        <PublicTourViewer
            v-if="tour.scenes.length"
            ref="viewerRef"
            :tour="tour"
            :compact="embed"
            @scene-change="onSceneChange"
            @hotspot-select="onHotspot"
        />
        <div
            v-else
            class="flex h-full flex-col items-center justify-center gap-3 text-white/70"
        >
            <Orbit class="size-10 text-white/40" />
            <p class="text-sm">This tour has no scenes yet.</p>
        </div>

        <!-- Title and logo -->
        <div
            class="pointer-events-none absolute inset-x-0 top-0 z-10 flex items-start justify-between gap-3 bg-gradient-to-b from-black/60 to-transparent p-4"
        >
            <div class="min-w-0">
                <h1
                    class="truncate font-semibold drop-shadow"
                    :class="embed ? 'text-sm' : 'text-lg'"
                >
                    {{ tour.title }}
                </h1>
                <p
                    v-if="tour.property && !embed"
                    class="truncate text-sm text-white/80 drop-shadow"
                >
                    {{ tour.property.name
                    }}<template v-if="tour.property.location">
                        · {{ tour.property.location }}</template
                    >
                </p>
                <span
                    v-if="preview"
                    class="mt-2 inline-block rounded-full bg-amber-500 px-3 py-1 text-xs font-medium text-black"
                    >Preview — visitors see this once published</span
                >
            </div>
            <img
                v-if="branding.logo_url"
                :src="branding.logo_url"
                alt="Logo"
                class="max-h-12 max-w-[140px] shrink-0 object-contain drop-shadow"
            />
        </div>

        <!-- Toolbar -->
        <div
            v-if="toolbar.length || audio.hasAudio"
            class="absolute top-20 z-20 flex flex-col gap-2"
            :class="classicLayout ? 'right-[15rem]' : 'right-3'"
        >
            <button
                v-if="audio.hasAudio"
                type="button"
                class="flex size-10 items-center justify-center rounded-full bg-black/55 backdrop-blur transition hover:bg-black/75"
                :aria-label="
                    audio.soundOn.value ? 'Mute sound' : 'Turn sound on'
                "
                :aria-pressed="audio.soundOn.value"
                :title="audio.soundOn.value ? 'Mute' : 'Sound on'"
                @click="audio.toggle()"
            >
                <Volume2 v-if="audio.soundOn.value" class="size-5" />
                <VolumeX v-else class="size-5" />
            </button>
            <button
                v-for="item in toolbar"
                :key="item.key"
                type="button"
                class="flex size-10 items-center justify-center rounded-full backdrop-blur transition"
                :class="
                    panel === item.key
                        ? 'bg-[var(--tour-primary)]'
                        : 'bg-black/55 hover:bg-black/75'
                "
                :aria-label="item.label"
                :aria-expanded="panel === item.key"
                :title="item.label"
                @click="togglePanel(item.key)"
            >
                <component :is="item.icon" class="size-5" />
            </button>
        </div>

        <!-- Side panel -->
        <aside
            v-if="panel"
            class="absolute inset-y-0 right-0 z-30 flex w-full max-w-sm flex-col bg-neutral-950/92 shadow-2xl backdrop-blur sm:top-3 sm:right-16 sm:bottom-14 sm:rounded-xl"
        >
            <div
                class="flex items-center justify-between border-b border-white/10 px-4 py-3"
            >
                <h2 class="text-sm font-medium">
                    {{ toolbar.find((item) => item.key === panel)?.label }}
                </h2>
                <button
                    type="button"
                    class="rounded p-1 hover:bg-white/10"
                    aria-label="Close panel"
                    @click="panel = null"
                >
                    <X class="size-4" />
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-4">
                <TourFloorplanView
                    v-if="panel === 'floorplan'"
                    :floorplans="tour.floorplans"
                    :scenes="tour.scenes"
                    :current-scene-id="currentSceneId"
                    @go="goTo"
                />
                <div v-else-if="panel === 'info'" class="space-y-4 text-sm">
                    <p
                        v-if="tour.description"
                        class="whitespace-pre-line text-white/85"
                    >
                        {{ tour.description }}
                    </p>
                    <dl
                        v-if="branding.contact"
                        class="space-y-2 border-t border-white/10 pt-4"
                    >
                        <div v-if="branding.contact.name">
                            <dt class="text-xs text-white/60">Contact</dt>
                            <dd>{{ branding.contact.name }}</dd>
                        </div>
                        <div v-if="branding.contact.phone">
                            <dt class="text-xs text-white/60">Phone</dt>
                            <dd>
                                <a
                                    :href="`tel:${branding.contact.phone}`"
                                    class="underline underline-offset-4"
                                    >{{ branding.contact.phone }}</a
                                >
                            </dd>
                        </div>
                        <div v-if="branding.contact.email">
                            <dt class="text-xs text-white/60">Email</dt>
                            <dd>
                                <a
                                    :href="`mailto:${branding.contact.email}`"
                                    class="underline underline-offset-4"
                                    >{{ branding.contact.email }}</a
                                >
                            </dd>
                        </div>
                        <div v-if="branding.contact.website">
                            <dt class="text-xs text-white/60">Website</dt>
                            <dd>
                                <a
                                    :href="branding.contact.website"
                                    target="_blank"
                                    rel="noopener"
                                    class="break-all underline underline-offset-4"
                                    >{{ branding.contact.website }}</a
                                >
                            </dd>
                        </div>
                    </dl>
                </div>
                <div
                    v-else-if="panel === 'scene' && currentScene"
                    class="space-y-2 text-sm"
                >
                    <h3 class="font-medium">{{ currentScene.title }}</h3>
                    <p class="whitespace-pre-line text-white/85">
                        {{ currentScene.description }}
                    </p>
                </div>
                <div v-else-if="panel === 'share'" class="space-y-5 text-sm">
                    <div class="space-y-2">
                        <label for="share-link" class="text-xs text-white/60"
                            >Tour link</label
                        >
                        <div class="flex gap-2">
                            <input
                                id="share-link"
                                :value="tour.public_url"
                                readonly
                                class="min-w-0 flex-1 rounded-md border border-white/20 bg-white/10 px-3 py-2 text-xs"
                                @focus="
                                    ($event.target as HTMLInputElement).select()
                                "
                            />
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-md bg-white/15 px-3 py-2 text-xs hover:bg-white/25"
                                @click="copyLink"
                            >
                                <Check v-if="copied" class="size-3.5" />
                                <Copy v-else class="size-3.5" />
                                {{ copied ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <p class="text-xs text-white/60">Share to</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="canShareNatively"
                                type="button"
                                class="rounded-md bg-white/15 px-3 py-2 text-xs hover:bg-white/25"
                                @click="shareNatively"
                            >
                                This device…
                            </button>
                            <a
                                v-for="target in shareTargets"
                                :key="target.label"
                                :href="target.href"
                                target="_blank"
                                rel="noopener"
                                class="rounded-md bg-white/15 px-3 py-2 text-xs hover:bg-white/25"
                                >{{ target.label }}</a
                            >
                        </div>
                    </div>
                </div>
                <form
                    v-else-if="panel === 'report'"
                    class="space-y-4 text-sm"
                    @submit.prevent="sendReport"
                >
                    <template v-if="!report.done">
                        <p class="text-white/80">
                            If you believe this tour contains illegal or
                            inappropriate content, please let us know.
                        </p>
                        <fieldset class="space-y-1.5">
                            <legend class="sr-only">Reason</legend>
                            <label
                                v-for="reason in reasons"
                                :key="reason.value"
                                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-white/10"
                            >
                                <input
                                    v-model="report.reason"
                                    type="radio"
                                    name="report-reason"
                                    :value="reason.value"
                                />
                                {{ reason.label }}
                            </label>
                        </fieldset>
                        <textarea
                            v-model="report.details"
                            rows="3"
                            maxlength="1000"
                            placeholder="Anything else we should know (optional)"
                            class="w-full rounded-md border border-white/20 bg-white/10 px-3 py-2 text-sm"
                        />
                        <!-- Spam trap: people never see or fill this in. -->
                        <input
                            v-model="report.website"
                            type="text"
                            name="website"
                            tabindex="-1"
                            autocomplete="off"
                            class="hidden"
                            aria-hidden="true"
                        />
                        <p v-if="report.error" class="text-red-300">
                            {{ report.error }}
                        </p>
                        <button
                            type="submit"
                            :disabled="!report.reason || report.sending"
                            class="flex items-center gap-2 rounded-md bg-red-600 px-4 py-2 text-sm font-medium disabled:opacity-50"
                        >
                            <Loader2
                                v-if="report.sending"
                                class="size-4 animate-spin"
                            />
                            Submit report
                        </button>
                    </template>
                    <p v-else class="flex items-start gap-2 text-emerald-300">
                        <Check class="mt-0.5 size-4 shrink-0" /> Thank you. We
                        will review this tour.
                    </p>
                </form>
                <TourLeadForm
                    v-else-if="panel === 'lead' && tour.lead"
                    :form="tour.lead"
                    :visit-id="analytics.visitId()"
                />
            </div>
        </aside>

        <!-- Platform badge -->
        <a
            v-if="branding.show_platform_branding"
            :href="page.props.appUrl"
            target="_blank"
            rel="noopener"
            class="absolute bottom-12 left-3 z-10 rounded-full bg-black/55 px-2.5 py-1 text-[11px] text-white/80 backdrop-blur hover:text-white"
            >Powered by {{ page.props.name }}</a
        >

        <!-- Intro screen -->
        <div
            v-if="introOpen"
            class="absolute inset-0 z-40 flex items-center justify-center bg-black/70 p-6 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            :aria-label="branding.intro.title ?? tour.title"
        >
            <div class="max-w-md space-y-4 text-center">
                <img
                    v-if="branding.logo_url"
                    :src="branding.logo_url"
                    alt="Logo"
                    class="mx-auto max-h-16 object-contain"
                />
                <h2 class="text-2xl font-semibold">
                    {{ branding.intro.title ?? tour.title }}
                </h2>
                <p
                    v-if="branding.intro.text"
                    class="whitespace-pre-line text-white/85"
                >
                    {{ branding.intro.text }}
                </p>
                <div class="flex flex-wrap justify-center gap-2 pt-2">
                    <button
                        type="button"
                        class="rounded-md bg-[var(--tour-primary)] px-5 py-2.5 text-sm font-medium transition hover:opacity-90"
                        @click="startTour(false)"
                    >
                        {{ branding.intro.button_label || 'Start tour' }}
                    </button>
                    <button
                        v-if="audio.hasAudio"
                        type="button"
                        class="flex items-center gap-2 rounded-md border border-white/30 px-4 py-2.5 text-sm transition hover:bg-white/10"
                        @click="startTour(true)"
                    >
                        <Volume2 class="size-4" /> Start with sound
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
