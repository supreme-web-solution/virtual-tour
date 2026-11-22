<script setup lang="ts">
import { Code, Eye } from '@lucide/vue';
import PanelSection from '@/components/tour/PanelSection.vue';
import { ref, watch } from 'vue';
import { Switch } from '@/components/ui/switch';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { HttpError } from '@/lib/http';
import type { EditorTour, Option, ViewerSettings } from '@/types';

const props = defineProps<{
    tour: EditorTour;
    properties: { id: string; name: string }[];
    visibilityOptions: Option[];
    canEdit: boolean;
}>();

const emit = defineEmits<{
    update: [data: Record<string, unknown>, onError?: (error: unknown) => void];
}>();

const description = ref(props.tour.description ?? '');
const domains = ref(props.tour.allowed_embed_domains.join('\n'));
const domainError = ref<string | null>(null);

watch(
    () => props.tour.description,
    (value) => {
        description.value = value ?? '';
    },
);
watch(
    () => props.tour.allowed_embed_domains,
    (value) => {
        domains.value = value.join('\n');
    },
);

const visibilityHelp: Record<string, string> = {
    public: 'Anyone with the link can view, and search engines may list it.',
    unlisted: 'Anyone with the link can view. Hidden from search engines.',
    private: 'Only members of your workspace can view it.',
};

function saveDescription(): void {
    const value = description.value.trim();

    if (value !== (props.tour.description ?? '')) {
        emit('update', { description: value === '' ? null : value });
    }
}

function saveDomains(): void {
    const list = domains.value
        .split(/[\s,]+/)
        .map((domain) =>
            domain
                .trim()
                .toLowerCase()
                .replace(/^https?:\/\//, '')
                .replace(/\/.*$/, ''),
        )
        .filter(Boolean);

    if (list.join() === props.tour.allowed_embed_domains.join()) {
        return;
    }

    domainError.value = null;
    emit('update', { allowed_embed_domains: list }, (error) => {
        domainError.value =
            error instanceof HttpError
                ? error.message
                : 'Could not save domains.';
    });
}

function setViewerSetting<K extends keyof ViewerSettings>(
    key: K,
    value: ViewerSettings[K],
): void {
    emit('update', { viewer_settings: { [key]: value } });
}
</script>

<template>
    <fieldset :disabled="!canEdit" class="space-y-6 p-3">
        <div class="grid gap-2">
            <Label for="tour-description">Description</Label>
            <Textarea
                id="tour-description"
                v-model="description"
                rows="4"
                maxlength="5000"
                placeholder="Shown on the share page and in link previews."
                @blur="saveDescription"
            />
        </div>

        <div class="grid gap-2">
            <Label for="tour-property">Property</Label>
            <NativeSelect
                id="tour-property"
                :model-value="tour.property?.id ?? ''"
                :options="
                    properties.map((p) => ({ value: p.id, label: p.name }))
                "
                placeholder="Not linked"
                @update:model-value="
                    emit('update', { property_id: $event || null })
                "
            />
        </div>

        <div class="grid gap-2">
            <Label for="tour-visibility">Who can view</Label>
            <NativeSelect
                id="tour-visibility"
                :model-value="tour.visibility"
                :options="visibilityOptions"
                @update:model-value="emit('update', { visibility: $event })"
            />
            <p class="text-xs text-muted-foreground">
                {{ visibilityHelp[tour.visibility] }}
            </p>
        </div>

        <PanelSection title="Viewer" :icon="Eye" tone="sky">
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Show scene thumbnails</span
                ><Switch
                    :model-value="tour.viewer_settings.show_scene_gallery"
                    @update:model-value="
                        setViewerSetting('show_scene_gallery', $event === true)
                    "
            /></label>
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Show previous / next buttons</span
                ><Switch
                    :model-value="tour.viewer_settings.show_prev_next"
                    @update:model-value="
                        setViewerSetting('show_prev_next', $event === true)
                    "
            /></label>
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Slowly rotate until the visitor interacts</span
                ><Switch
                    :model-value="tour.viewer_settings.auto_rotate"
                    @update:model-value="
                        setViewerSetting('auto_rotate', $event === true)
                    "
            /></label>
            <div class="grid gap-2">
                <Label for="tour-rotate-speed">Rotation speed</Label>
                <NativeSelect
                    id="tour-rotate-speed"
                    :model-value="tour.viewer_settings.auto_rotate_speed"
                    :options="[
                        { value: 'slow', label: 'Slow' },
                        { value: 'normal', label: 'Normal' },
                        { value: 'fast', label: 'Fast' },
                    ]"
                    @update:model-value="
                        setViewerSetting(
                            'auto_rotate_speed',
                            $event as ViewerSettings['auto_rotate_speed'],
                        )
                    "
                />
                <p class="text-xs text-muted-foreground">
                    Visitors can also pause it or change the speed in the
                    viewer.
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="tour-layout">Viewer layout</Label>
                <NativeSelect
                    id="tour-layout"
                    :model-value="tour.viewer_settings.layout"
                    :options="[
                        {
                            value: 'carousel',
                            label: 'Carousel: thumbnails along the bottom',
                        },
                        {
                            value: 'classic',
                            label: 'Classic: scene list on the right',
                        },
                        {
                            value: 'sidebar',
                            label: 'Sidebar: scene list and details on the left',
                        },
                        {
                            value: 'gallery',
                            label: 'Gallery: large thumbnails with scene info',
                        },
                        { value: 'minimal', label: 'Minimal: hidden controls' },
                    ]"
                    @update:model-value="
                        setViewerSetting(
                            'layout',
                            $event as ViewerSettings['layout'],
                        )
                    "
                />
                <p class="text-xs text-muted-foreground">
                    The layout visitors see first. They can switch it in the
                    viewer.
                </p>
            </div>
            <div class="grid gap-2">
                <Label for="tour-transition">Scene transition</Label>
                <NativeSelect
                    id="tour-transition"
                    :model-value="tour.viewer_settings.transition"
                    :options="[
                        { value: 'fade', label: 'Crossfade' },
                        { value: 'black', label: 'Fade through black' },
                        { value: 'none', label: 'Instant' },
                    ]"
                    @update:model-value="
                        setViewerSetting(
                            'transition',
                            $event as ViewerSettings['transition'],
                        )
                    "
                />
            </div>
        </PanelSection>

        <PanelSection
            title="Embedding"
            :icon="Code"
            tone="violet"
            description="Where this tour may be embedded"
        >
            <div class="grid gap-2">
                <Label for="tour-domains">Allowed embed domains</Label>
                <Textarea
                    id="tour-domains"
                    v-model="domains"
                    rows="3"
                    placeholder="example.com&#10;*.example.org"
                    @blur="saveDomains"
                />
                <p class="text-xs text-muted-foreground">
                    One per line. Leave empty to allow embedding on any website.
                </p>
                <InputError :message="domainError ?? undefined" />
            </div>
        </PanelSection>
    </fieldset>
</template>
