<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { Check, Copy, ExternalLink, Globe, Lock } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EditorTour } from '@/types';

const props = defineProps<{
    tour: EditorTour;
    canPublish: boolean;
    busy: boolean;
}>();

const emit = defineEmits<{
    publish: [];
    unpublish: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const height = ref(600);
const { copy, copied, isSupported } = useClipboard({ copiedDuring: 1500 });
const copiedWhat = ref<'link' | 'embed' | 'mls' | null>(null);

const page = usePage();
// MLS-ready links come with white-label: no branding, contact details or enquiry form.
const canMls = computed(
    () => page.props.workspace?.plan.features.remove_branding === true,
);
const mlsUrl = computed(
    () =>
        `${props.tour.public_url}${props.tour.public_url.includes('?') ? '&' : '?'}mls=1`,
);

const published = computed(() => props.tour.status === 'published');
const hasVisibleScene = computed(() =>
    props.tour.scenes.some((scene) => scene.is_visible),
);

const embedMode = ref<'script' | 'iframe'>('script');

// Tours and the loader live on the same host (a custom domain, when set).
const origin = computed(() => new URL(props.tour.public_url).origin);
const publicId = computed(
    () => /\/t\/([a-z0-9]{12})/.exec(props.tour.public_url)?.[1] ?? '',
);
const safeTitle = computed(() => props.tour.title.replaceAll('"', '&quot;'));

const scriptCode = computed(
    () =>
        `<div data-vt-tour="${publicId.value}" data-height="${height.value}" data-title="${safeTitle.value}"></div>\n<script async src="${origin.value}/embed.js"></${'script'}>`,
);

const iframeCode = computed(
    () =>
        `<iframe src="${props.tour.public_url}?embed=1" width="100%" height="${height.value}" style="border:0" allow="fullscreen; gyroscope; accelerometer" allowfullscreen loading="lazy" title="${safeTitle.value}"></iframe>`,
);

const embedCode = computed(() =>
    embedMode.value === 'script' ? scriptCode.value : iframeCode.value,
);

async function copyText(kind: 'link' | 'embed' | 'mls'): Promise<void> {
    copiedWhat.value = kind;
    await copy(
        kind === 'link'
            ? props.tour.public_url
            : kind === 'mls'
              ? mlsUrl.value
              : embedCode.value,
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Publish & share</DialogTitle>
                <DialogDescription>
                    <template v-if="published">
                        This tour is live. Changes you make appear for visitors
                        straight away.
                    </template>
                    <template v-else>
                        This tour is a draft. Publish it to make the link and
                        embed code work for visitors.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex items-center justify-between gap-3 rounded-md border p-3"
            >
                <div class="flex items-center gap-2 text-sm">
                    <Globe v-if="published" class="size-4 text-emerald-600" />
                    <Lock v-else class="size-4 text-muted-foreground" />
                    <span>{{ published ? 'Published' : 'Not published' }}</span>
                    <span
                        v-if="tour.visibility !== 'public'"
                        class="text-muted-foreground"
                        >· {{ tour.visibility }}</span
                    >
                </div>
                <template v-if="canPublish">
                    <Button
                        v-if="published"
                        variant="outline"
                        size="sm"
                        :disabled="busy"
                        @click="emit('unpublish')"
                        >Unpublish</Button
                    >
                    <Button
                        v-else
                        size="sm"
                        :disabled="busy || !hasVisibleScene"
                        @click="emit('publish')"
                        >Publish</Button
                    >
                </template>
            </div>
            <p
                v-if="!published && !hasVisibleScene"
                class="-mt-2 text-xs text-muted-foreground"
            >
                Add at least one visible scene before publishing.
            </p>

            <div class="grid gap-2">
                <Label for="share-link">Share link</Label>
                <div class="flex gap-2">
                    <Input
                        id="share-link"
                        :model-value="tour.public_url"
                        readonly
                        class="font-mono text-xs"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button
                        v-if="isSupported"
                        variant="outline"
                        size="icon"
                        :aria-label="'Copy link'"
                        @click="copyText('link')"
                    >
                        <Check v-if="copied && copiedWhat === 'link'" />
                        <Copy v-else />
                    </Button>
                    <Button variant="outline" size="icon" as-child>
                        <a
                            :href="tour.public_url"
                            target="_blank"
                            rel="noopener"
                            aria-label="Open public page"
                        >
                            <ExternalLink />
                        </a>
                    </Button>
                </div>
            </div>

            <div v-if="canMls" class="grid gap-2">
                <Label for="mls-link">MLS-ready link</Label>
                <template v-if="canMls">
                    <div class="flex gap-2">
                        <Input
                            id="mls-link"
                            :model-value="mlsUrl"
                            readonly
                            class="font-mono text-xs"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <Button
                            v-if="isSupported"
                            variant="outline"
                            size="icon"
                            aria-label="Copy MLS-ready link"
                            @click="copyText('mls')"
                        >
                            <Check v-if="copied && copiedWhat === 'mls'" />
                            <Copy v-else />
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        The same tour with no logo, contact details, enquiry
                        form or branding, for listing sites that don't allow
                        them.
                    </p>
                </template>
            </div>

            <div class="grid gap-2">
                <div
                    class="inline-flex w-fit rounded-lg border bg-muted/40 p-0.5 text-xs"
                    role="tablist"
                >
                    <button
                        v-for="mode in ['script', 'iframe'] as const"
                        :key="mode"
                        type="button"
                        role="tab"
                        :aria-selected="embedMode === mode"
                        class="rounded-md px-3 py-1 font-medium transition-colors"
                        :class="
                            embedMode === mode
                                ? 'bg-card shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="embedMode = mode"
                    >
                        {{
                            mode === 'script'
                                ? 'Script (recommended)'
                                : 'iframe'
                        }}
                    </button>
                </div>
                <div class="flex items-end justify-between gap-3">
                    <Label for="embed-code">Embed code</Label>
                    <label
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        Height
                        <Input
                            v-model.number="height"
                            type="number"
                            min="200"
                            max="2000"
                            step="50"
                            class="h-7 w-20 text-xs"
                        />
                        px
                    </label>
                </div>
                <textarea
                    id="embed-code"
                    :value="embedCode"
                    readonly
                    rows="4"
                    class="w-full resize-none rounded-md border bg-muted/40 p-2 font-mono text-xs"
                    @focus="($event.target as HTMLTextAreaElement).select()"
                />
                <Button
                    v-if="isSupported"
                    variant="outline"
                    size="sm"
                    class="justify-self-start"
                    @click="copyText('embed')"
                >
                    <Check v-if="copied && copiedWhat === 'embed'" />
                    <Copy v-else />
                    Copy embed code
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
