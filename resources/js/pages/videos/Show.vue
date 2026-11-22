<script setup lang="ts">
import { formatCredits } from '@/lib/credits';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import {
    ArrowDown,
    ArrowUp,
    Check,
    Copy,
    Download,
    Film,
    Globe,
    Music,
    Pencil,
    RefreshCw,
    Sparkles,
    Trash2,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useGenerationStatus } from '@/composables/useGenerationStatus';
import videos from '@/routes/videos';
import CommentsPanel from '@/components/CommentsPanel.vue';
import type {
    TeamComments,
    VideoLimits,
    VideoProjectDetail,
    VideoShotItem,
    VideoSource,
} from '@/types';

const props = defineProps<{
    video: VideoProjectDetail;
    sources: VideoSource[];
    audio: { id: string; name: string | null; url: string }[];
    cameraMovements: string[];
    transitions: string[];
    moods: string[];
    ambiences: string[];
    visibilityOptions: { value: string; label: string }[];
    limits: VideoLimits;
    clipCost: number;
    musicCost: number;
    available: number;
    features: { video: boolean };
    can: { update: boolean; delete: boolean };
    comments: TeamComments;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Video Studio', href: videos.index() }],
    },
});

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);
const actionErrors = computed(() =>
    ['clips', 'music', 'render', 'publish', 'credits', 'shot', 'shot_ids']
        .map((key) => errors.value[key])
        .filter(Boolean),
);

const busy = ref(false);
const shots = computed(() => props.video.shots);
const hasGenerating = computed(
    () =>
        props.video.is_active ||
        shots.value.some((shot) => shot.status === 'generating') ||
        (props.video.music_generation !== null &&
            !['completed', 'failed', 'cancelled', 'refunded'].includes(
                props.video.music_generation.status,
            )),
);
const waitingShots = computed(() =>
    shots.value.filter(
        (shot) => shot.status === 'pending' || shot.status === 'failed',
    ),
);
const allClipsReady = computed(
    () =>
        shots.value.length > 0 &&
        shots.value.every((shot) => shot.status === 'completed'),
);
const musicBlocking = computed(
    () =>
        props.video.soundtrack_mode === 'ai_music' &&
        props.video.music_generation?.status !== 'completed',
);
const editable = computed(
    () =>
        props.can.update &&
        !['planning', 'rendering'].includes(props.video.status),
);

useGenerationStatus(
    () => hasGenerating.value,
    ['video', 'clipCost', 'available'],
);

function action(
    url: string,
    data: Record<string, string | null> = {},
    method: 'post' | 'delete' = 'post',
): void {
    busy.value = true;
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        onFinish: () => (busy.value = false),
    });
}

/* Shot editing */
const editingId = ref<string | null>(null);
const shotForm = useForm({
    scene_name: '',
    duration: 5,
    camera_movement: '',
    transition: 'crossfade',
    prompt: '',
});

function editShot(shot: VideoShotItem): void {
    editingId.value = shot.id;
    shotForm.defaults({
        scene_name: shot.scene_name ?? '',
        duration: shot.duration,
        camera_movement: shot.camera_movement,
        transition: shot.transition,
        prompt: shot.prompt,
    });
    shotForm.reset();
    shotForm.clearErrors();
}

function saveShot(): void {
    if (!editingId.value) {
        return;
    }

    shotForm.submit(
        videos.shots.update({ video: props.video.id, shot: editingId.value }),
        {
            preserveScroll: true,
            onSuccess: () => (editingId.value = null),
        },
    );
}

function moveShot(index: number, offset: number): void {
    const ids = shots.value.map((shot) => shot.id);
    const target = index + offset;

    if (target < 0 || target >= ids.length) {
        return;
    }

    [ids[index], ids[target]] = [ids[target], ids[index]];
    busy.value = true;
    router.put(
        videos.shots.reorder(props.video.id).url,
        { shot_ids: ids },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

function deleteShot(shot: VideoShotItem): void {
    if (confirm(`Remove shot "${shot.scene_name ?? shot.sort_order}"?`)) {
        action(
            videos.shots.destroy({ video: props.video.id, shot: shot.id }).url,
            {},
            'delete',
        );
    }
}

/* Sound */
const soundForm = useForm({
    soundtrack_mode: props.video.soundtrack_mode,
    soundtrack_asset_id: props.video.soundtrack?.id ?? '',
    voiceover_asset_id: props.video.voiceover?.id ?? '',
    settings: {
        music_mood: props.video.settings.music_mood,
        ambience: props.video.settings.ambience,
        music_volume: props.video.settings.music_volume,
        voiceover_volume: props.video.settings.voiceover_volume,
        audio_fade_in: props.video.settings.audio_fade_in,
        audio_fade_out: props.video.settings.audio_fade_out,
        branded_outro: props.video.settings.branded_outro,
    },
});
const musicPrompt = ref(props.video.storyboard?.music_prompt ?? '');

function saveSound(): void {
    soundForm
        .transform((data) => ({
            ...data,
            soundtrack_asset_id:
                data.soundtrack_mode === 'own_audio'
                    ? data.soundtrack_asset_id || null
                    : null,
            voiceover_asset_id: data.voiceover_asset_id || null,
        }))
        .submit(videos.update(props.video.id), { preserveScroll: true });
}

/* Publishing */
const visibility = ref(
    props.video.visibility === 'private' ? 'unlisted' : props.video.visibility,
);
const copied = ref<'link' | 'embed' | null>(null);
const embedCode = computed(() => {
    const [width, height] = props.video.aspect_ratio.split(':').map(Number);

    return `<div style="position:relative;aspect-ratio:${width}/${height};max-width:100%"><iframe src="${props.video.public_url}?embed=1" style="position:absolute;inset:0;width:100%;height:100%;border:0" allow="fullscreen; autoplay" allowfullscreen loading="lazy" title="${props.video.title.replaceAll('"', '&quot;')}"></iframe></div>`;
});

async function copy(kind: 'link' | 'embed'): Promise<void> {
    await navigator.clipboard.writeText(
        kind === 'link' ? props.video.public_url : embedCode.value,
    );
    copied.value = kind;
    setTimeout(() => (copied.value = null), 2000);
}

function destroyVideo(): void {
    if (
        confirm(
            'Delete this video project? Generated clips stay in the media library.',
        )
    ) {
        router.delete(videos.destroy(props.video.id).url);
    }
}

const statusTone: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    generating: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    completed:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
};
const label = (value: string): string => value.replaceAll('_', ' ');
const frameClass = computed(() =>
    props.video.aspect_ratio === '9:16'
        ? 'aspect-[9/16] max-h-[70vh]'
        : props.video.aspect_ratio === '1:1'
          ? 'aspect-square max-h-[70vh]'
          : 'aspect-video',
);
</script>

<template>
    <Head :title="video.title" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="video.title"
            :description="`${video.style_label} · ${video.aspect_ratio} · ${video.requested_duration}s · ${video.status_label}`"
        >
            <template #actions>
                <Button
                    v-if="can.delete"
                    variant="ghost"
                    size="icon"
                    aria-label="Delete video"
                    @click="destroyVideo"
                    ><Trash2
                /></Button>
            </template>
        </PageHeader>

        <div
            v-if="!features.video"
            class="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" /> AI video isn't
            configured yet. An administrator must add FAL_KEY before clips can
            be generated.
        </div>

        <div
            v-for="message in actionErrors"
            :key="message"
            class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
        >
            {{ message }}
        </div>

        <!-- Planning -->
        <section
            v-if="video.status === 'planning'"
            class="flex flex-col items-center gap-3 rounded-lg border bg-card p-10 text-center shadow-sm"
        >
            <Spinner class="size-6" />
            <p class="font-medium">Planning your storyboard…</p>
            <p class="text-sm text-muted-foreground">
                AI is looking at your images and choosing shots, camera moves
                and music. This takes under a minute.
            </p>
        </section>

        <section
            v-else-if="video.status === 'failed' && !shots.length"
            class="rounded-lg border bg-card p-6 text-sm shadow-sm"
        >
            <p class="font-medium">The storyboard couldn't be planned.</p>
            <p class="mt-1 text-muted-foreground">
                {{ video.storyboard_error ?? 'Please try again.' }} Credits were
                returned.
            </p>
            <Button class="mt-4" variant="outline" as-child
                ><Link :href="videos.create()">Start a new video</Link></Button
            >
        </section>

        <div v-else class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <!-- Final video -->
                <section
                    v-if="video.final"
                    class="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="font-medium">Final video</h2>
                        <a
                            :href="video.final.url"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                            ><Download class="size-4" /> Open file</a
                        >
                    </div>
                    <div class="flex justify-center rounded-md bg-black">
                        <video
                            :src="video.final.url"
                            :poster="video.final.poster_url ?? undefined"
                            controls
                            playsinline
                            class="max-w-full"
                            :class="frameClass"
                        />
                    </div>
                </section>

                <section
                    v-if="video.status === 'rendering'"
                    class="flex items-center gap-3 rounded-lg border bg-card p-4 text-sm shadow-sm"
                >
                    <Spinner /> Assembling clips, transitions and sound… this
                    usually takes a minute or two.
                </section>
                <p
                    v-if="video.latest_export?.status === 'failed'"
                    class="rounded-md border p-3 text-sm text-red-700 dark:text-red-300"
                >
                    The last render failed:
                    {{ video.latest_export.error ?? 'unknown error' }}
                </p>

                <!-- Storyboard -->
                <section class="space-y-4">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 class="font-medium">Storyboard</h2>
                            <p class="text-sm text-muted-foreground">
                                {{ shots.length }} shots ·
                                {{ video.total_seconds }}s of clips ·
                                {{ video.progress.completed }}/{{
                                    video.progress.total
                                }}
                                ready
                            </p>
                            <p
                                v-if="video.storyboard?.creative_direction"
                                class="mt-1 max-w-2xl text-sm"
                            >
                                {{ video.storyboard.creative_direction }}
                            </p>
                        </div>
                        <div v-if="can.update" class="flex flex-wrap gap-2">
                            <Button
                                v-if="waitingShots.length"
                                :disabled="
                                    busy ||
                                    hasGenerating ||
                                    !features.video ||
                                    available < clipCost
                                "
                                @click="
                                    action(videos.clips.generate(video.id).url)
                                "
                            >
                                <Sparkles /> Generate
                                {{ waitingShots.length }} clip{{
                                    waitingShots.length === 1 ? '' : 's'
                                }}
                                · {{ clipCost }} credits
                            </Button>
                            <Button
                                v-if="allClipsReady"
                                :variant="video.final ? 'outline' : 'default'"
                                :disabled="
                                    busy || hasGenerating || musicBlocking
                                "
                                @click="action(videos.render(video.id).url)"
                            >
                                <Film />
                                {{
                                    video.final
                                        ? 'Render again'
                                        : 'Render video'
                                }}
                            </Button>
                        </div>
                    </div>
                    <p
                        v-if="waitingShots.length && available < clipCost"
                        class="text-xs text-muted-foreground"
                    >
                        You have {{ formatCredits(available) }} credits; this
                        needs {{ clipCost }}.
                    </p>

                    <div class="space-y-3">
                        <article
                            v-for="(shot, index) in shots"
                            :key="shot.id"
                            class="grid gap-4 rounded-lg border bg-card p-3 shadow-sm sm:grid-cols-[14rem_minmax(0,1fr)]"
                        >
                            <div
                                class="relative overflow-hidden rounded-md bg-muted"
                                :class="
                                    video.aspect_ratio === '9:16'
                                        ? 'aspect-[9/16] max-h-80'
                                        : 'aspect-video'
                                "
                            >
                                <video
                                    v-if="shot.clip"
                                    :src="shot.clip.url"
                                    :poster="
                                        shot.clip.thumbnail_url ?? undefined
                                    "
                                    controls
                                    muted
                                    playsinline
                                    class="size-full object-cover"
                                />
                                <img
                                    v-else-if="shot.source.thumbnail_url"
                                    :src="shot.source.thumbnail_url"
                                    :alt="shot.source.name ?? ''"
                                    class="size-full object-cover"
                                />
                                <div
                                    v-if="shot.status === 'generating'"
                                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/50 text-xs text-white"
                                >
                                    <Spinner /> Generating clip…
                                </div>
                                <span
                                    class="absolute top-1 left-1 flex size-6 items-center justify-center rounded bg-black/60 text-xs text-white"
                                    >{{ index + 1 }}</span
                                >
                            </div>

                            <div v-if="editingId === shot.id" class="space-y-3">
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div class="grid gap-1.5">
                                        <Label :for="`scene-${shot.id}`"
                                            >Scene</Label
                                        >
                                        <Input
                                            :id="`scene-${shot.id}`"
                                            v-model="shotForm.scene_name"
                                            maxlength="120"
                                        />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label :for="`camera-${shot.id}`"
                                            >Camera</Label
                                        >
                                        <NativeSelect
                                            :id="`camera-${shot.id}`"
                                            v-model="shotForm.camera_movement"
                                            :options="
                                                cameraMovements.map((m) => ({
                                                    value: m,
                                                    label: m,
                                                }))
                                            "
                                        />
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div class="grid gap-1.5">
                                            <Label :for="`duration-${shot.id}`"
                                                >Seconds</Label
                                            >
                                            <Input
                                                :id="`duration-${shot.id}`"
                                                v-model.number="
                                                    shotForm.duration
                                                "
                                                type="number"
                                                :min="limits.min_seconds"
                                                :max="limits.max_seconds"
                                            />
                                        </div>
                                        <div class="grid gap-1.5">
                                            <Label
                                                :for="`transition-${shot.id}`"
                                                >Then</Label
                                            >
                                            <NativeSelect
                                                :id="`transition-${shot.id}`"
                                                v-model="shotForm.transition"
                                                :options="
                                                    transitions.map((t) => ({
                                                        value: t,
                                                        label: label(t),
                                                    }))
                                                "
                                            />
                                        </div>
                                    </div>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`prompt-${shot.id}`"
                                        >Motion prompt</Label
                                    >
                                    <Textarea
                                        :id="`prompt-${shot.id}`"
                                        v-model="shotForm.prompt"
                                        rows="3"
                                        maxlength="1000"
                                    />
                                </div>
                                <InputError
                                    v-for="(message, key) in shotForm.errors"
                                    :key="key"
                                    :message="message"
                                />
                                <p
                                    v-if="shot.clip"
                                    class="text-xs text-muted-foreground"
                                >
                                    Changing the scene, camera, length or prompt
                                    discards this clip; it will need to be
                                    generated again.
                                </p>
                                <div class="flex gap-2">
                                    <Button
                                        size="sm"
                                        :disabled="shotForm.processing"
                                        @click="saveShot"
                                        ><Check /> Save</Button
                                    >
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="editingId = null"
                                        ><X /> Cancel</Button
                                    >
                                </div>
                            </div>

                            <div v-else class="flex min-w-0 flex-col gap-2">
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">
                                            {{
                                                shot.scene_name ??
                                                `Shot ${index + 1}`
                                            }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ shot.duration }}s ·
                                            {{ shot.camera_movement }} · then
                                            {{ label(shot.transition) }}
                                        </p>
                                    </div>
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-xs capitalize"
                                        :class="statusTone[shot.status]"
                                        >{{ shot.status }}</span
                                    >
                                </div>
                                <p
                                    class="line-clamp-3 text-sm text-muted-foreground"
                                >
                                    {{ shot.prompt }}
                                </p>
                                <p
                                    v-if="shot.error"
                                    class="text-xs text-red-700 dark:text-red-300"
                                >
                                    {{ shot.error }}
                                </p>
                                <div
                                    v-if="editable"
                                    class="mt-auto flex flex-wrap gap-1"
                                >
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        :disabled="shot.status === 'generating'"
                                        @click="editShot(shot)"
                                        ><Pencil /> Edit</Button
                                    >
                                    <Button
                                        v-if="
                                            shot.status === 'completed' ||
                                            shot.status === 'failed'
                                        "
                                        size="sm"
                                        variant="ghost"
                                        :disabled="busy || !features.video"
                                        @click="
                                            action(
                                                videos.shots.regenerate({
                                                    video: video.id,
                                                    shot: shot.id,
                                                }).url,
                                            )
                                        "
                                    >
                                        <RefreshCw /> Regenerate
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-8"
                                        :disabled="busy || index === 0"
                                        aria-label="Move earlier"
                                        @click="moveShot(index, -1)"
                                        ><ArrowUp
                                    /></Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-8"
                                        :disabled="
                                            busy || index === shots.length - 1
                                        "
                                        aria-label="Move later"
                                        @click="moveShot(index, 1)"
                                        ><ArrowDown
                                    /></Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-8"
                                        :disabled="
                                            busy ||
                                            shots.length === 1 ||
                                            shot.status === 'generating'
                                        "
                                        aria-label="Remove shot"
                                        @click="deleteShot(shot)"
                                        ><Trash2
                                    /></Button>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <CommentsPanel :comments="comments" />
                <!-- Sound -->
                <section
                    class="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="flex items-center gap-2 font-medium">
                        <Music class="size-4" /> Sound
                    </h2>
                    <fieldset :disabled="!editable" class="space-y-3">
                        <div class="grid gap-1.5">
                            <Label for="sound-mode">Soundtrack</Label>
                            <NativeSelect
                                id="sound-mode"
                                v-model="soundForm.soundtrack_mode"
                                :options="[
                                    { value: 'ai_music', label: 'AI music' },
                                    {
                                        value: 'ambient',
                                        label: 'Ambient sound',
                                    },
                                    {
                                        value: 'own_audio',
                                        label: 'My own audio',
                                    },
                                    { value: 'none', label: 'No sound' },
                                ]"
                            />
                        </div>
                        <div
                            v-if="soundForm.soundtrack_mode === 'ai_music'"
                            class="grid gap-1.5"
                        >
                            <Label for="sound-mood">Mood</Label>
                            <NativeSelect
                                id="sound-mood"
                                v-model="soundForm.settings.music_mood"
                                :options="
                                    moods.map((m) => ({
                                        value: m,
                                        label: label(m),
                                    }))
                                "
                            />
                        </div>
                        <div
                            v-if="soundForm.soundtrack_mode === 'ambient'"
                            class="grid gap-1.5"
                        >
                            <Label for="sound-ambience">Ambience</Label>
                            <NativeSelect
                                id="sound-ambience"
                                v-model="soundForm.settings.ambience"
                                :options="
                                    ambiences.map((a) => ({
                                        value: a,
                                        label: label(a),
                                    }))
                                "
                            />
                            <p class="text-xs text-muted-foreground">
                                Ambience is generated with each clip, so
                                changing it applies to clips generated
                                afterwards.
                            </p>
                        </div>
                        <div
                            v-if="soundForm.soundtrack_mode === 'own_audio'"
                            class="grid gap-1.5"
                        >
                            <Label for="sound-file">Audio file</Label>
                            <NativeSelect
                                id="sound-file"
                                v-model="soundForm.soundtrack_asset_id"
                                :options="
                                    audio.map((a) => ({
                                        value: a.id,
                                        label: a.name ?? 'Audio',
                                    }))
                                "
                                placeholder="Choose a file"
                            />
                            <InputError
                                :message="soundForm.errors.soundtrack_asset_id"
                            />
                        </div>
                        <div
                            v-if="
                                soundForm.soundtrack_mode === 'ai_music' ||
                                soundForm.soundtrack_mode === 'own_audio'
                            "
                            class="grid gap-1.5"
                        >
                            <Label for="sound-volume"
                                >Music volume ·
                                {{ soundForm.settings.music_volume }}%</Label
                            >
                            <Slider
                                v-model="soundForm.settings.music_volume"
                                :min="0"
                                :max="100"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="sound-voice">Narration</Label>
                            <NativeSelect
                                id="sound-voice"
                                v-model="soundForm.voiceover_asset_id"
                                :options="
                                    audio.map((a) => ({
                                        value: a.id,
                                        label: a.name ?? 'Audio',
                                    }))
                                "
                                placeholder="No narration"
                            />
                        </div>
                        <div
                            v-if="soundForm.voiceover_asset_id"
                            class="grid gap-1.5"
                        >
                            <Label for="sound-voice-volume"
                                >Narration volume ·
                                {{
                                    soundForm.settings.voiceover_volume
                                }}%</Label
                            >
                            <Slider
                                v-model="soundForm.settings.voiceover_volume"
                                :min="0"
                                :max="150"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1.5">
                                <Label for="fade-in">Fade in (s)</Label>
                                <Input
                                    id="fade-in"
                                    v-model.number="
                                        soundForm.settings.audio_fade_in
                                    "
                                    type="number"
                                    min="0"
                                    max="10"
                                    step="0.5"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="fade-out">Fade out (s)</Label>
                                <Input
                                    id="fade-out"
                                    v-model.number="
                                        soundForm.settings.audio_fade_out
                                    "
                                    type="number"
                                    min="0"
                                    max="10"
                                    step="0.5"
                                />
                            </div>
                        </div>
                        <label
                            class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                            ><span
                                >Logo outro{{
                                    video.tour
                                        ? ` from ${video.tour.title}`
                                        : ' (link a tour first)'
                                }}</span
                            ><Switch
                                :model-value="soundForm.settings.branded_outro"
                                @update:model-value="
                                    soundForm.settings.branded_outro =
                                        $event === true
                                "
                        /></label>
                        <Button
                            v-if="editable"
                            size="sm"
                            variant="outline"
                            :disabled="
                                soundForm.processing || !soundForm.isDirty
                            "
                            @click="saveSound"
                            >Save sound</Button
                        >
                    </fieldset>

                    <div
                        v-if="video.soundtrack_mode === 'ai_music'"
                        class="space-y-2 border-t pt-3"
                    >
                        <p class="text-sm">
                            AI music:
                            <span class="text-muted-foreground capitalize">{{
                                video.music_generation?.status ??
                                'not generated'
                            }}</span>
                        </p>
                        <p
                            v-if="video.music_generation?.error"
                            class="text-xs text-red-700 dark:text-red-300"
                        >
                            {{ video.music_generation.error }}
                        </p>
                        <audio
                            v-if="
                                video.soundtrack &&
                                video.music_generation?.status === 'completed'
                            "
                            :src="video.soundtrack.url"
                            controls
                            class="w-full"
                        />
                        <template v-if="editable">
                            <Textarea
                                v-model="musicPrompt"
                                rows="2"
                                maxlength="600"
                                placeholder="Describe the music"
                                aria-label="Music prompt"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="
                                    busy ||
                                    hasGenerating ||
                                    !features.video ||
                                    available < musicCost
                                "
                                @click="
                                    action(
                                        videos.music.generate(video.id).url,
                                        { prompt: musicPrompt || null },
                                    )
                                "
                            >
                                <RefreshCw />
                                {{
                                    video.music_generation
                                        ? 'Regenerate'
                                        : 'Generate'
                                }}
                                music · {{ musicCost }} credits
                            </Button>
                        </template>
                    </div>
                </section>

                <!-- Publish -->
                <section
                    v-if="video.final"
                    class="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                >
                    <h2 class="flex items-center gap-2 font-medium">
                        <Globe class="size-4" /> Share
                    </h2>
                    <template v-if="can.update">
                        <div class="flex gap-2">
                            <NativeSelect
                                v-model="visibility"
                                :options="
                                    visibilityOptions.filter(
                                        (o) => o.value !== 'private',
                                    )
                                "
                                aria-label="Visibility"
                                class="flex-1"
                            />
                            <Button
                                size="sm"
                                :disabled="busy"
                                @click="
                                    action(videos.publish(video.id).url, {
                                        visibility,
                                    })
                                "
                            >
                                {{
                                    video.published_at &&
                                    video.visibility !== 'private'
                                        ? 'Update'
                                        : 'Publish'
                                }}
                            </Button>
                        </div>
                        <Button
                            v-if="
                                video.published_at &&
                                video.visibility !== 'private'
                            "
                            size="sm"
                            variant="ghost"
                            :disabled="busy"
                            @click="
                                action(
                                    videos.unpublish(video.id).url,
                                    {},
                                    'delete',
                                )
                            "
                        >
                            Unpublish
                        </Button>
                    </template>
                    <template
                        v-if="
                            video.published_at && video.visibility !== 'private'
                        "
                    >
                        <div class="flex gap-2">
                            <Input
                                :model-value="video.public_url"
                                readonly
                                aria-label="Public link"
                                class="text-xs"
                            />
                            <Button
                                size="icon"
                                variant="outline"
                                aria-label="Copy link"
                                @click="copy('link')"
                                ><Check v-if="copied === 'link'" /><Copy v-else
                            /></Button>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            class="w-full"
                            @click="copy('embed')"
                        >
                            <Check v-if="copied === 'embed'" /><Copy v-else />
                            Copy embed code
                        </Button>
                        <dl
                            class="grid grid-cols-3 gap-2 border-t pt-3 text-center text-xs"
                        >
                            <div>
                                <dt class="text-muted-foreground">Views</dt>
                                <dd class="text-base font-medium tabular-nums">
                                    {{
                                        video.stats.views +
                                        video.stats.embed_views
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Plays</dt>
                                <dd class="text-base font-medium tabular-nums">
                                    {{ video.stats.plays }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Finished</dt>
                                <dd class="text-base font-medium tabular-nums">
                                    {{ video.stats.completions }}
                                </dd>
                            </div>
                        </dl>
                        <p class="text-center text-xs text-muted-foreground">
                            {{ video.stats.downloads }} downloads ·
                            {{ video.stats.embed_views }} embed views
                        </p>
                    </template>
                    <p v-else class="text-xs text-muted-foreground">
                        Publish to get a share link, embed code and a download
                        page.
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
