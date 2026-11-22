<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    Check,
    Eye,
    Loader2,
    Orbit,
    Share2,
    Clapperboard,
    Image as ImageIcon,
    LayoutPanelTop,
    MessageSquare,
    Music,
    Palette,
    Settings,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CommentsPanel from '@/components/CommentsPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AddScenesDialog from '@/components/tour/AddScenesDialog.vue';
import EditorPanorama from '@/components/tour/EditorPanorama.vue';
import HotspotPanel from '@/components/tour/HotspotPanel.vue';
import ScenePanel from '@/components/tour/ScenePanel.vue';
import SceneList from '@/components/tour/SceneList.vue';
import ShareDialog from '@/components/tour/ShareDialog.vue';
import TourSettingsPanel from '@/components/tour/TourSettingsPanel.vue';
import BrandingPanel from '@/components/tour/BrandingPanel.vue';
import ExtrasPanel from '@/components/tour/ExtrasPanel.vue';
import FloorplanPanel from '@/components/tour/FloorplanPanel.vue';
import type { HotspotInput } from '@/composables/useTourEditor';
import { useTourEditor } from '@/composables/useTourEditor';
import type { SphericalDegrees } from '@/lib/sphere';
import type { PinPreview } from '@/lib/tourMarkers';
import tours from '@/routes/tours';
import videos from '@/routes/videos';
import type {
    TeamComments,
    AssetTypeOption,
    EditorTour,
    LibraryAsset,
    LibraryPanorama,
    Option,
    TourScene,
} from '@/types';

const props = defineProps<{
    tour: EditorTour;
    comments: TeamComments;
    panoramas: LibraryPanorama[];
    library: {
        images: LibraryAsset[];
        audio: LibraryAsset[];
        floorplans: LibraryAsset[];
        media: LibraryAsset[];
    };
    properties: { id: string; name: string }[];
    assetTypes: AssetTypeOption[];
    visibilityOptions: Option[];
    can: {
        update: boolean;
        publish: boolean;
        delete: boolean;
        upload: boolean;
    };
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Virtual Tours', href: tours.index() },
        { title: props.tour.title, href: tours.edit(props.tour.id) },
    ],
});

const editor = useTourEditor(props.tour);
const {
    tour,
    currentScene,
    currentSceneId,
    selectedHotspot,
    draftHotspot,
    saveState,
} = editor;

const viewerRef = ref<InstanceType<typeof EditorPanorama> | null>(null);
/** The pin as the open hotspot form describes it, drawn live on the image. */
const pinPreview = ref<PinPreview | null>(null);
const hotspotPanelRef = ref<InstanceType<typeof HotspotPanel> | null>(null);

/** 'add' = placing a new hotspot; 'move' = repositioning the selected one. */
const placementMode = ref<'add' | 'move' | null>(null);
type RightTab = 'scene' | 'tour' | 'brand' | 'extras' | 'plan' | 'notes';
const rightTab = ref<RightTab>('scene');
const rightTabs: { key: RightTab; label: string; icon: typeof Settings }[] = [
    { key: 'scene', label: 'Scene', icon: ImageIcon },
    { key: 'tour', label: 'Tour', icon: Settings },
    { key: 'brand', label: 'Brand', icon: Palette },
    { key: 'extras', label: 'Extras', icon: Music },
    { key: 'plan', label: 'Floor plan', icon: LayoutPanelTop },
    { key: 'notes', label: 'Notes', icon: MessageSquare },
];
/** The Maps tab holds both the floor plan and the scene map. */
const addScenesOpen = ref(false);
const shareOpen = ref(false);
const sceneToDelete = ref<TourScene | null>(null);
const title = ref(props.tour.title);

const busy = computed(() => saveState.value === 'saving');
const sceneTitles = computed(() =>
    Object.fromEntries(
        tour.value.scenes.map((scene) => [scene.id, scene.title]),
    ),
);
const usedMediaIds = computed(() =>
    tour.value.scenes.map((scene) => scene.media.id),
);
const showHotspotPanel = computed(
    () => draftHotspot.value !== null || selectedHotspot.value !== null,
);

watch(
    () => tour.value.title,
    (value) => {
        title.value = value;
    },
);

// Leaving a scene or closing the panel cancels any placement in progress.
watch(currentSceneId, () => {
    placementMode.value = null;
});

function saveTitle(): void {
    const value = title.value.trim();

    if (value === '' || value === tour.value.title) {
        title.value = tour.value.title;

        return;
    }

    void editor.updateTour({ title: value });
}

function startAddHotspot(): void {
    editor.selectedHotspotId.value = null;
    editor.cancelDraft();
    placementMode.value = placementMode.value === 'add' ? null : 'add';
}

function onPlace(position: SphericalDegrees): void {
    if (placementMode.value === 'add') {
        editor.placeDraft(position);
    } else if (placementMode.value === 'move' && selectedHotspot.value) {
        void editor.updateHotspot(selectedHotspot.value.id, position);
    }

    placementMode.value = null;
}

function onMoveHotspot(hotspotId: string, position: SphericalDegrees): void {
    void editor.updateHotspot(hotspotId, position);
}

function onSelectHotspot(hotspotId: string): void {
    editor.cancelDraft();
    placementMode.value = null;
    editor.selectedHotspotId.value = hotspotId;
}

async function onSaveHotspot(input: HotspotInput): Promise<void> {
    try {
        if (draftHotspot.value) {
            await editor.saveDraft(input);
        } else if (selectedHotspot.value) {
            await editor.updateHotspot(selectedHotspot.value.id, input);
        }
    } catch (error) {
        hotspotPanelRef.value?.showErrors(error);
    }
}

function closeHotspotPanel(): void {
    editor.cancelDraft();
    editor.selectedHotspotId.value = null;
    placementMode.value = null;
}

function captureView(): void {
    const view = viewerRef.value?.currentView();

    if (view && currentScene.value) {
        void editor.setInitialView(currentScene.value.id, view);
    }
}

async function onUpdateTour(
    data: Record<string, unknown>,
    onError?: (error: unknown) => void,
): Promise<void> {
    try {
        await editor.updateTour(data);
    } catch (error) {
        onError?.(error);
    }
}

async function addScenes(mediaIds: string[]): Promise<void> {
    await editor.addScenes(mediaIds);
    addScenesOpen.value = false;
}

async function confirmDeleteScene(): Promise<void> {
    if (sceneToDelete.value) {
        await editor.deleteScene(sceneToDelete.value.id);
        sceneToDelete.value = null;
    }
}
</script>

<template>
    <Head :title="`Edit ${tour.title}`" />

    <div class="flex min-h-[600px] flex-col lg:h-svh">
        <!-- Toolbar -->
        <div
            class="flex flex-wrap items-center gap-2 border-b px-3 py-2 sm:flex-nowrap"
        >
            <Button variant="ghost" size="icon" class="size-8" as-child>
                <Link :href="tours.index()" aria-label="Back to tours">
                    <ArrowLeft />
                </Link>
            </Button>
            <input
                v-model="title"
                :disabled="!can.update"
                maxlength="160"
                aria-label="Tour title"
                class="min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-2 py-1 text-base font-semibold outline-none hover:border-input focus:border-ring disabled:hover:border-transparent"
                @blur="saveTitle"
                @keydown.enter.prevent="
                    ($event.target as HTMLInputElement).blur()
                "
            />
            <span
                class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"
                aria-live="polite"
            >
                <template v-if="saveState === 'saving'">
                    <Loader2 class="size-3.5 animate-spin" /> Saving…
                </template>
                <template v-else-if="saveState === 'saved'">
                    <Check class="size-3.5" /> Saved
                </template>
                <template v-else-if="saveState === 'error'">
                    <AlertCircle class="size-3.5 text-destructive" /> Not saved
                </template>
            </span>
            <span
                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="
                    tour.status === 'published'
                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                        : 'bg-muted text-muted-foreground'
                "
                >{{ tour.status === 'published' ? 'Published' : 'Draft' }}</span
            >
            <Button
                variant="outline"
                size="sm"
                as-child
                :class="{
                    'pointer-events-none opacity-50': !tour.scenes.length,
                }"
            >
                <a
                    :href="tours.preview.url(tour.id)"
                    target="_blank"
                    rel="noopener"
                >
                    <Eye /> Preview
                </a>
            </Button>
            <Button
                v-if="tour.scenes.length"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="videos.create.url({ query: { tour: tour.id } })">
                    <Clapperboard /> Make video
                </Link>
            </Button>
            <Button size="sm" @click="shareOpen = true">
                <Share2 />
                {{ tour.status === 'published' ? 'Share' : 'Publish' }}
            </Button>
        </div>

        <!-- Workspace -->
        <div
            class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[260px_minmax(0,1fr)_340px]"
        >
            <aside
                class="order-2 max-h-80 border-b lg:order-1 lg:max-h-none lg:border-r lg:border-b-0"
            >
                <SceneList
                    :scenes="tour.scenes"
                    :current-scene-id="currentSceneId"
                    :start-scene-id="tour.viewer_settings.start_scene_id"
                    :can-edit="can.update"
                    @select="editor.selectScene"
                    @add="addScenesOpen = true"
                    @reorder="editor.reorderScenes"
                    @duplicate="editor.duplicateScene"
                    @remove="sceneToDelete = $event"
                    @make-start="
                        editor.updateTour({
                            viewer_settings: { start_scene_id: $event },
                        })
                    "
                />
            </aside>

            <main class="order-1 h-[55svh] bg-black lg:order-2 lg:h-auto">
                <EditorPanorama
                    v-if="currentScene"
                    ref="viewerRef"
                    :scene="currentScene"
                    :scene-titles="sceneTitles"
                    :selected-hotspot-id="editor.selectedHotspotId.value"
                    :draft="draftHotspot"
                    :preview="pinPreview"
                    :placing="placementMode !== null"
                    :draggable="can.update"
                    @move-hotspot="onMoveHotspot"
                    @place="onPlace"
                    @select-hotspot="onSelectHotspot"
                />
                <div
                    v-else
                    class="flex size-full flex-col items-center justify-center gap-3 p-6 text-center text-white/80"
                >
                    <Orbit class="size-10 text-white/40" />
                    <p class="text-sm">
                        Add 360° panoramas to start building the tour.
                    </p>
                    <Button
                        v-if="can.update"
                        variant="secondary"
                        @click="addScenesOpen = true"
                        >Add scenes</Button
                    >
                </div>
            </main>

            <aside
                class="order-3 flex min-h-0 min-w-0 flex-col overflow-x-hidden border-t lg:border-t-0 lg:border-l"
            >
                <HotspotPanel
                    v-if="showHotspotPanel && currentScene"
                    ref="hotspotPanelRef"
                    :hotspot="selectedHotspot"
                    :scenes="tour.scenes"
                    :current-scene-id="currentScene.id"
                    :can-edit="can.update"
                    :media-library="library.media"
                    :moving="placementMode === 'move'"
                    :busy="busy"
                    @save="onSaveHotspot"
                    @preview="pinPreview = $event"
                    @remove="
                        selectedHotspot &&
                        editor.deleteHotspot(selectedHotspot.id)
                    "
                    @move="
                        placementMode = placementMode === 'move' ? null : 'move'
                    "
                    @close="closeHotspotPanel"
                />
                <template v-else>
                    <div class="flex border-b text-xs">
                        <button
                            v-for="tab in rightTabs"
                            :key="tab.key"
                            type="button"
                            class="flex flex-1 flex-col items-center gap-0.5 border-b-2 px-1 py-2 font-medium transition-colors"
                            :class="
                                rightTab === tab.key
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            "
                            @click="rightTab = tab.key"
                        >
                            <component :is="tab.icon" class="size-4" />
                            {{ tab.label }}
                        </button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <template v-if="rightTab === 'scene'">
                            <ScenePanel
                                v-if="currentScene"
                                :scene="currentScene"
                                :scene-titles="sceneTitles"
                                :audio-library="library.audio"
                                :can-edit="can.update"
                                :placing="placementMode === 'add'"
                                @update="
                                    editor.updateScene(currentScene.id, $event)
                                "
                                @capture-view="captureView"
                                @preview-view="
                                    viewerRef?.goToView(
                                        currentScene.initial_view,
                                    )
                                "
                                @add-hotspot="startAddHotspot"
                                @select-hotspot="onSelectHotspot"
                            />
                            <p v-else class="p-4 text-sm text-muted-foreground">
                                Select or add a scene to edit it.
                            </p>
                        </template>
                        <TourSettingsPanel
                            v-else-if="rightTab === 'tour'"
                            :tour="tour"
                            :properties="properties"
                            :visibility-options="visibilityOptions"
                            :can-edit="can.update"
                            @update="onUpdateTour"
                        />
                        <BrandingPanel
                            v-else-if="rightTab === 'brand'"
                            :tour="tour"
                            :images="library.images"
                            :asset-types="assetTypes"
                            :can-edit="can.update"
                            @update="onUpdateTour"
                        />
                        <ExtrasPanel
                            v-else-if="rightTab === 'extras'"
                            :tour="tour"
                            :audio="library.audio"
                            :can-edit="can.update"
                            @update="onUpdateTour"
                        />
                        <CommentsPanel
                            v-else-if="rightTab === 'notes'"
                            :comments="comments"
                        />
                        <template v-else>
                            <FloorplanPanel
                                :editor="editor"
                                :library="library.floorplans"
                                :asset-types="assetTypes"
                                :can-edit="can.update"
                            />
                        </template>
                    </div>
                </template>
            </aside>
        </div>
    </div>

    <AddScenesDialog
        v-model:open="addScenesOpen"
        :panoramas="panoramas"
        :used-media-ids="usedMediaIds"
        :asset-types="assetTypes"
        :properties="properties"
        :property-id="tour.property?.id ?? null"
        :can-upload="can.upload"
        :busy="busy"
        @add="addScenes"
    />

    <ShareDialog
        v-model:open="shareOpen"
        :tour="tour"
        :can-publish="can.publish"
        :busy="busy"
        @publish="editor.publish"
        @unpublish="editor.unpublish"
    />

    <Dialog
        :open="sceneToDelete !== null"
        @update:open="(isOpen) => !isOpen && (sceneToDelete = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete “{{ sceneToDelete?.title }}”?</DialogTitle>
                <DialogDescription>
                    The scene and its hotspots are removed from this tour, along
                    with any hotspots in other scenes that lead to it. The
                    panorama stays in your media library.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="ghost" @click="sceneToDelete = null"
                    >Cancel</Button
                >
                <Button
                    variant="destructive"
                    :disabled="busy"
                    @click="confirmDeleteScene"
                    >Delete scene</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
