<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, MapPin } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    video: {
        id: string;
        title: string;
        url: string | null;
        poster_url: string | null;
        aspect_ratio: string;
        property: { name: string; location: string | null } | null;
        download_url: string;
        events_url: string;
    };
    embed: boolean;
    brand: {
        name: string;
        logo_url: string | null;
        website: string | null;
        white_label: boolean;
    };
}>();

const sent = ref<{ play: boolean; complete: boolean }>({
    play: false,
    complete: false,
});
const frameClass = computed(() =>
    props.video.aspect_ratio === '9:16'
        ? 'aspect-[9/16] h-full max-h-full'
        : props.video.aspect_ratio === '1:1'
          ? 'aspect-square h-full max-h-full'
          : 'aspect-video w-full max-h-full',
);

/**
 * Count the first play and the first full watch of this page view.
 */
function track(type: 'play' | 'complete'): void {
    if (sent.value[type]) {
        return;
    }

    sent.value[type] = true;
    void fetch(props.video.events_url, {
        method: 'POST',
        keepalive: true,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({ type }),
    }).catch(() => {});
}
</script>

<template>
    <Head :title="video.title" />

    <div class="flex h-dvh flex-col bg-black text-white">
        <div
            class="flex min-h-0 flex-1 items-center justify-center"
            :class="embed ? '' : 'p-4'"
        >
            <video
                v-if="video.url"
                :src="video.url"
                :poster="video.poster_url ?? undefined"
                controls
                playsinline
                preload="metadata"
                class="max-w-full bg-black"
                :class="frameClass"
                @play="track('play')"
                @ended="track('complete')"
            />
            <p v-else class="text-sm text-white/70">
                This video isn't available.
            </p>
        </div>

        <footer
            v-if="!embed"
            class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 px-4 py-3"
        >
            <div class="min-w-0">
                <h1 class="truncate font-medium">{{ video.title }}</h1>
                <p
                    v-if="video.property"
                    class="flex items-center gap-1 truncate text-sm text-white/60"
                >
                    <MapPin class="size-3.5 shrink-0" />
                    {{ video.property.name
                    }}<template v-if="video.property.location">
                        · {{ video.property.location }}</template
                    >
                </p>
            </div>
            <div class="flex items-center gap-3">
                <component
                    :is="brand.website ? 'a' : 'span'"
                    :href="brand.website ?? undefined"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-2 text-sm text-white/70"
                >
                    <img
                        v-if="brand.logo_url"
                        :src="brand.logo_url"
                        :alt="brand.name"
                        class="h-6 w-auto max-w-24 rounded-sm bg-white/90 object-contain p-0.5"
                    />
                    <span v-else>{{ brand.name }}</span>
                </component>
                <a
                    v-if="video.url"
                    :href="video.download_url"
                    class="flex items-center gap-2 rounded-md bg-white/10 px-3 py-1.5 text-sm hover:bg-white/20"
                >
                    <Download class="size-4" /> Download
                </a>
            </div>
        </footer>
        <a
            v-if="!brand.white_label && !embed"
            :href="$page.props.appUrl"
            target="_blank"
            rel="noopener"
            class="pb-2 text-center text-[11px] text-white/40 hover:text-white/70"
            >Powered by {{ $page.props.name }}</a
        >
    </div>
</template>
