import { onBeforeUnmount, ref } from 'vue';
import type { PublicTour, TourScene } from '@/types';

/**
 * Background and per-scene audio for the public viewer.
 *
 * Nothing plays until the visitor turns sound on (browsers block audible
 * autoplay, and visitors shouldn't be surprised by it). A scene's own audio
 * can pause the tour music while that scene is shown.
 */
export function useTourAudio(tour: PublicTour) {
    const soundOn = ref(false);
    const background = tour.audio ? new Audio(tour.audio.url) : null;
    const sceneTrack = new Audio();
    let currentScene: TourScene | null = null;

    if (background && tour.audio) {
        background.loop = tour.audio.loop;
        background.volume = tour.audio.volume / 100;
        background.preload = 'none';
    }

    sceneTrack.preload = 'none';

    const hasAudio =
        background !== null || tour.scenes.some((scene) => scene.audio.url);

    function play(element: HTMLAudioElement): void {
        void element.play().catch(() => {
            // Playback can still be refused (e.g. power saving); stay silent.
        });
    }

    function apply(): void {
        const sceneAudio = currentScene?.audio;
        const sceneUrl = sceneAudio?.url ?? null;

        if (!soundOn.value) {
            background?.pause();
            sceneTrack.pause();

            return;
        }

        if (sceneUrl && sceneAudio) {
            if (sceneTrack.src !== sceneUrl) {
                sceneTrack.src = sceneUrl;
            }

            sceneTrack.loop = sceneAudio.loop;
            sceneTrack.volume = sceneAudio.volume / 100;
            play(sceneTrack);
        } else {
            sceneTrack.pause();
        }

        if (background) {
            if (sceneUrl && sceneAudio?.pause_background) {
                background.pause();
            } else {
                play(background);
            }
        }
    }

    function setScene(scene: TourScene | null): void {
        currentScene = scene;
        apply();
    }

    function toggle(): void {
        soundOn.value = !soundOn.value;
        apply();
    }

    function enable(): void {
        soundOn.value = true;
        apply();
    }

    onBeforeUnmount(() => {
        background?.pause();
        sceneTrack.pause();
    });

    return { soundOn, hasAudio, setScene, toggle, enable };
}
