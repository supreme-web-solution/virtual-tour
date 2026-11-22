import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { HttpError, requestJson } from '@/lib/http';
import type { SphericalDegrees } from '@/lib/sphere';
import tours from '@/routes/tours';
import hotspotRoutes from '@/routes/tours/hotspots';
import floorplanRoutes from '@/routes/tours/floorplans';
import markerRoutes from '@/routes/tours/floorplans/markers';
import sceneRoutes from '@/routes/tours/scenes';
import type {
    EditorTour,
    HotspotSettings,
    HotspotType,
    InitialView,
    TourHotspot,
    TourScene,
} from '@/types';

export type SaveState = 'idle' | 'saving' | 'saved' | 'error';

/** A hotspot placed in the viewer but not saved yet. */
export type DraftHotspot = SphericalDegrees & { id: 'draft' };

export type HotspotInput = {
    type: HotspotType;
    title: string | null;
    description: string | null;
    external_url: string | null;
    target_scene_id: string | null;
    media_asset_id?: string | null;
    settings: Partial<HotspotSettings>;
};

type TourResponse = { tour: EditorTour; hotspot_id?: string };

/**
 * State and server calls for the tour editor. Every change is saved
 * immediately; the server answers with the full tour, which replaces local
 * state so the editor can never drift from what is stored.
 */
export function useTourEditor(initial: EditorTour) {
    const tour = ref<EditorTour>(initial);
    const currentSceneId = ref<string | null>(
        initial.viewer_settings.start_scene_id ?? initial.scenes[0]?.id ?? null,
    );
    const selectedHotspotId = ref<string | null>(null);
    const draftHotspot = ref<DraftHotspot | null>(null);

    const pending = ref(0);
    const saveState = ref<SaveState>('idle');
    const lastError = ref<string | null>(null);

    const currentScene = computed<TourScene | null>(
        () =>
            tour.value.scenes.find(
                (scene) => scene.id === currentSceneId.value,
            ) ?? null,
    );

    const selectedHotspot = computed<TourHotspot | null>(
        () =>
            currentScene.value?.hotspots.find(
                (hotspot) => hotspot.id === selectedHotspotId.value,
            ) ?? null,
    );

    function sceneById(id: string | null): TourScene | null {
        return tour.value.scenes.find((scene) => scene.id === id) ?? null;
    }

    /**
     * Run a request, track save status, and apply the returned tour.
     */
    async function save<T extends TourResponse>(
        request: () => Promise<T>,
    ): Promise<T | null> {
        pending.value++;
        saveState.value = 'saving';

        try {
            const response = await request();
            tour.value = response.tour;

            if (!sceneById(currentSceneId.value)) {
                currentSceneId.value = tour.value.scenes[0]?.id ?? null;
            }

            lastError.value = null;

            return response;
        } catch (error) {
            const message =
                error instanceof HttpError || error instanceof Error
                    ? error.message
                    : 'Could not save your changes.';
            lastError.value = message;
            saveState.value = 'error';
            toast.error(message);

            if (error instanceof HttpError && error.status === 422) {
                throw error;
            }

            return null;
        } finally {
            pending.value--;

            if (pending.value === 0 && saveState.value !== 'error') {
                saveState.value = 'saved';
            }
        }
    }

    function selectScene(id: string): void {
        currentSceneId.value = id;
        selectedHotspotId.value = null;
        draftHotspot.value = null;
    }

    // --- Tour ---------------------------------------------------------------

    function updateTour(data: Record<string, unknown>) {
        return save(() =>
            requestJson<TourResponse>(
                'PATCH',
                tours.update.url(tour.value.id),
                data,
            ),
        );
    }

    function publish() {
        return save(() =>
            requestJson<TourResponse>('POST', tours.publish.url(tour.value.id)),
        );
    }

    function unpublish() {
        return save(() =>
            requestJson<TourResponse>(
                'DELETE',
                tours.unpublish.url(tour.value.id),
            ),
        );
    }

    // --- Scenes -------------------------------------------------------------

    async function addScenes(mediaAssetIds: string[]) {
        const previousIds = new Set(tour.value.scenes.map((scene) => scene.id));
        const response = await save(() =>
            requestJson<TourResponse>(
                'POST',
                sceneRoutes.store.url(tour.value.id),
                { media_asset_ids: mediaAssetIds },
            ),
        );
        const added = tour.value.scenes.find(
            (scene) => !previousIds.has(scene.id),
        );

        if (response && added) {
            selectScene(added.id);
        }
    }

    function updateScene(
        sceneId: string,
        data: Partial<Record<string, unknown>>,
    ) {
        return save(() =>
            requestJson<TourResponse>(
                'PATCH',
                sceneRoutes.update.url({ tour: tour.value.id, scene: sceneId }),
                data,
            ),
        );
    }

    function setInitialView(sceneId: string, view: InitialView) {
        return updateScene(sceneId, {
            initial_yaw: view.yaw,
            initial_pitch: view.pitch,
            initial_zoom: Math.round(view.zoom),
        });
    }

    async function duplicateScene(sceneId: string) {
        const previousIds = new Set(tour.value.scenes.map((scene) => scene.id));
        await save(() =>
            requestJson<TourResponse>(
                'POST',
                sceneRoutes.duplicate.url({
                    tour: tour.value.id,
                    scene: sceneId,
                }),
            ),
        );
        const copy = tour.value.scenes.find(
            (scene) => !previousIds.has(scene.id),
        );

        if (copy) {
            selectScene(copy.id);
        }
    }

    function deleteScene(sceneId: string) {
        if (currentSceneId.value === sceneId) {
            selectedHotspotId.value = null;
            draftHotspot.value = null;
        }

        return save(() =>
            requestJson<TourResponse>(
                'DELETE',
                sceneRoutes.destroy.url({
                    tour: tour.value.id,
                    scene: sceneId,
                }),
            ),
        );
    }

    function reorderScenes(sceneIds: string[]) {
        return save(() =>
            requestJson<TourResponse>(
                'PUT',
                sceneRoutes.reorder.url(tour.value.id),
                {
                    scene_ids: sceneIds,
                },
            ),
        );
    }

    // --- Hotspots -----------------------------------------------------------

    function placeDraft(position: SphericalDegrees): void {
        selectedHotspotId.value = null;
        draftHotspot.value = { id: 'draft', ...position };
    }

    function cancelDraft(): void {
        draftHotspot.value = null;
    }

    async function saveDraft(input: HotspotInput) {
        const scene = currentScene.value;
        const draft = draftHotspot.value;

        if (!scene || !draft) {
            return null;
        }

        const response = await save(() =>
            requestJson<TourResponse>(
                'POST',
                hotspotRoutes.store.url({
                    tour: tour.value.id,
                    scene: scene.id,
                }),
                { ...input, yaw: draft.yaw, pitch: draft.pitch },
            ),
        );

        if (response?.hotspot_id) {
            draftHotspot.value = null;
            selectedHotspotId.value = response.hotspot_id;
        }

        return response;
    }

    function updateHotspot(
        hotspotId: string,
        data: Partial<HotspotInput & SphericalDegrees>,
    ) {
        return save(() =>
            requestJson<TourResponse>(
                'PATCH',
                hotspotRoutes.update.url({
                    tour: tour.value.id,
                    hotspot: hotspotId,
                }),
                data,
            ),
        );
    }

    async function deleteHotspot(hotspotId: string) {
        selectedHotspotId.value = null;

        await save(() =>
            requestJson<TourResponse>(
                'DELETE',
                hotspotRoutes.destroy.url({
                    tour: tour.value.id,
                    hotspot: hotspotId,
                }),
            ),
        );
    }

    // --- Floor plans --------------------------------------------------------

    function addFloorplan(mediaAssetId: string, title: string) {
        return save(() =>
            requestJson<TourResponse>(
                'POST',
                floorplanRoutes.store.url(tour.value.id),
                {
                    media_asset_id: mediaAssetId,
                    title,
                },
            ),
        );
    }

    function renameFloorplan(floorplanId: string, title: string) {
        return save(() =>
            requestJson<TourResponse>(
                'PATCH',
                floorplanRoutes.update.url({
                    tour: tour.value.id,
                    floorplan: floorplanId,
                }),
                { title },
            ),
        );
    }

    function deleteFloorplan(floorplanId: string) {
        return save(() =>
            requestJson<TourResponse>(
                'DELETE',
                floorplanRoutes.destroy.url({
                    tour: tour.value.id,
                    floorplan: floorplanId,
                }),
            ),
        );
    }

    /** x and y are fractions (0–1) of the floor plan image. */
    function placeFloorplanMarker(
        floorplanId: string,
        sceneId: string,
        x: number,
        y: number,
    ) {
        return save(() =>
            requestJson<TourResponse>(
                'PUT',
                markerRoutes.place.url({
                    tour: tour.value.id,
                    floorplan: floorplanId,
                }),
                { scene_id: sceneId, x, y },
            ),
        );
    }

    function removeFloorplanMarker(floorplanId: string, sceneId: string) {
        return save(() =>
            requestJson<TourResponse>(
                'DELETE',
                markerRoutes.remove.url({
                    tour: tour.value.id,
                    floorplan: floorplanId,
                }),
                { scene_id: sceneId },
            ),
        );
    }

    return {
        tour,
        currentSceneId,
        currentScene,
        selectedHotspotId,
        selectedHotspot,
        draftHotspot,
        saveState,
        lastError,
        sceneById,
        selectScene,
        updateTour,
        publish,
        unpublish,
        addScenes,
        updateScene,
        setInitialView,
        duplicateScene,
        deleteScene,
        reorderScenes,
        placeDraft,
        cancelDraft,
        saveDraft,
        updateHotspot,
        deleteHotspot,
        addFloorplan,
        renameFloorplan,
        deleteFloorplan,
        placeFloorplanMarker,
        removeFloorplanMarker,
    };
}

export type TourEditor = ReturnType<typeof useTourEditor>;
