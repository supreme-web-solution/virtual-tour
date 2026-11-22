<script setup lang="ts">
import { ExternalLink, Globe, Loader2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PanelSection from '@/components/tour/PanelSection.vue';
import { Button } from '@/components/ui/button';
import { getJson, HttpError, postJson, requestJson } from '@/lib/http';
import { connect, disconnect } from '@/routes/google';
import { destroy, show, store } from '@/routes/tours/street-view';

type SceneRow = {
    id: string;
    title: string;
    thumbnail_url: string | null;
    problem: string | null;
    publication: {
        status: 'pending' | 'published' | 'failed';
        share_link: string | null;
        error: string | null;
    } | null;
};

type State = {
    allowed: boolean;
    configured: boolean;
    can_connect: boolean;
    connection: { email: string | null } | null;
    redirect_uri: string;
    scenes: SceneRow[];
};

const props = defineProps<{ tourId: string; canEdit: boolean }>();

const state = ref<State | null>(null);
const selected = ref<Set<string>>(new Set());
const busy = ref(false);
const error = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

const anyPending = computed(() =>
    state.value?.scenes.some(
        (scene) => scene.publication?.status === 'pending',
    ),
);
const connectUrl = computed(() =>
    connect.url({ query: { return: window.location.pathname } }),
);

function apply(next: State): void {
    state.value = next;
    clearTimeout(timer);

    if (anyPending.value) {
        timer = setTimeout(load, 4000);
    }
}

async function load(): Promise<void> {
    try {
        apply(await getJson<State>(show.url(props.tourId)));
    } catch {
        error.value = 'Could not load Street View status.';
    }
}

async function run(action: () => Promise<State>): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        apply(await action());
    } catch (exception) {
        error.value =
            exception instanceof HttpError
                ? exception.message
                : 'Something went wrong. Please try again.';
    } finally {
        busy.value = false;
    }
}

function publish(): Promise<void> {
    const ids = [...selected.value];
    selected.value = new Set();

    return run(() =>
        postJson<State>(store.url(props.tourId), { scene_ids: ids }),
    );
}

function unpublish(scene: SceneRow): Promise<void> {
    return run(() =>
        requestJson<State>(
            'DELETE',
            destroy.url({ tour: props.tourId, scene: scene.id }),
        ),
    );
}

function toggle(id: string): void {
    const next = new Set(selected.value);

    if (!next.delete(id)) {
        next.add(id);
    }

    selected.value = next;
}

onMounted(load);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <PanelSection
        v-if="state?.allowed"
        title="Google Street View"
        :icon="Globe"
        tone="sky"
        description="Publish your own 360° photos to Google Maps."
        help="Sends your uploaded 360° photos to Google Street View so they appear on Google Maps. Needs a connected Google account and photos with a map location."
    >
        <p v-if="!state.configured" class="text-xs text-muted-foreground">
            Street View is not set up on this server yet. An administrator must
            add the Google client ID and secret.
        </p>

        <template v-else-if="!state.connection">
            <p class="text-xs text-muted-foreground">
                Connect the Google account that should own the published photos.
                Photos appear on Google Maps under that account.
            </p>
            <Button v-if="state.can_connect" as-child size="sm">
                <a :href="connectUrl">Connect Google</a>
            </Button>
            <p v-else class="text-xs text-muted-foreground">
                An owner or admin needs to connect Google.
            </p>
        </template>

        <template v-else>
            <div class="flex items-center justify-between gap-2 text-xs">
                <span class="min-w-0 truncate text-muted-foreground"
                    >Connected as
                    <span class="font-medium text-foreground">{{
                        state.connection.email ?? 'a Google account'
                    }}</span></span
                >
                <Button
                    v-if="state.can_connect"
                    size="sm"
                    variant="ghost"
                    class="h-7"
                    @click="
                        $inertia.delete(disconnect.url(), {
                            preserveScroll: true,
                            onSuccess: load,
                        })
                    "
                >
                    Disconnect
                </Button>
            </div>

            <ul class="divide-y rounded-md border">
                <li
                    v-for="scene in state.scenes"
                    :key="scene.id"
                    class="flex items-center gap-2.5 px-2.5 py-2 text-sm"
                >
                    <input
                        type="checkbox"
                        class="size-4"
                        :aria-label="`Publish ${scene.title}`"
                        :disabled="
                            !canEdit ||
                            scene.problem !== null ||
                            scene.publication?.status === 'pending'
                        "
                        :checked="selected.has(scene.id)"
                        @change="toggle(scene.id)"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate">{{ scene.title }}</p>
                        <p
                            v-if="scene.publication?.status === 'pending'"
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Loader2 class="size-3 animate-spin" /> Publishing…
                        </p>
                        <p
                            v-else-if="
                                scene.publication?.status === 'published'
                            "
                            class="text-xs text-emerald-600"
                        >
                            On Street View
                            <a
                                v-if="scene.publication.share_link"
                                :href="scene.publication.share_link"
                                target="_blank"
                                rel="noopener"
                                class="ml-1 inline-flex items-center gap-0.5 underline"
                                >Open <ExternalLink class="size-3"
                            /></a>
                        </p>
                        <p
                            v-else-if="scene.publication?.status === 'failed'"
                            class="text-xs text-red-600"
                        >
                            {{ scene.publication.error }}
                        </p>
                        <p
                            v-else-if="scene.problem"
                            class="text-xs text-muted-foreground"
                        >
                            {{ scene.problem }}
                        </p>
                    </div>
                    <Button
                        v-if="
                            scene.publication &&
                            canEdit &&
                            scene.publication.status !== 'pending'
                        "
                        size="sm"
                        variant="ghost"
                        class="h-7"
                        :disabled="busy"
                        @click="unpublish(scene)"
                    >
                        {{
                            scene.publication.status === 'published'
                                ? 'Remove'
                                : 'Clear'
                        }}
                    </Button>
                </li>
            </ul>

            <Button
                v-if="canEdit"
                size="sm"
                :disabled="busy || selected.size === 0"
                @click="publish"
            >
                <Loader2 v-if="busy" class="animate-spin" />
                Publish {{ selected.size || '' }} to Street View
            </Button>
        </template>

        <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
        <p class="text-xs text-muted-foreground">
            Only your own uploaded 360° photos qualify (at least 3840 px wide),
            placed on the map. AI-made or imported images are never sent. Google
            reviews each photo before it appears.
        </p>
    </PanelSection>
</template>
