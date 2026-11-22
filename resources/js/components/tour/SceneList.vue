<script setup lang="ts">
import {
    AlertTriangle,
    Copy,
    EyeOff,
    GripVertical,
    MoreHorizontal,
    Plus,
    Star,
    Trash2,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TourScene } from '@/types';

const props = defineProps<{
    scenes: TourScene[];
    currentSceneId: string | null;
    startSceneId: string | null;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    select: [sceneId: string];
    add: [];
    reorder: [sceneIds: string[]];
    duplicate: [sceneId: string];
    remove: [scene: TourScene];
    makeStart: [sceneId: string];
}>();

// Local copy so dragging feels instant; re-synced whenever the server answers.
const items = ref<TourScene[]>([...props.scenes]);

watch(
    () => props.scenes,
    (scenes) => {
        items.value = [...scenes];
    },
);

function onDragEnd(): void {
    const ids = items.value.map((scene) => scene.id);

    if (ids.join() !== props.scenes.map((scene) => scene.id).join()) {
        emit('reorder', ids);
    }
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between border-b px-3 py-2.5">
            <h2 class="text-sm font-medium">
                Scenes
                <span class="font-normal text-muted-foreground">{{
                    scenes.length
                }}</span>
            </h2>
            <Button
                v-if="canEdit"
                size="sm"
                variant="outline"
                class="h-7"
                @click="emit('add')"
            >
                <Plus /> Add
            </Button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-2">
            <VueDraggable
                v-model="items"
                handle=".scene-handle"
                :animation="150"
                :disabled="!canEdit"
                class="space-y-1.5"
                @end="onDragEnd"
            >
                <div
                    v-for="(scene, index) in items"
                    :key="scene.id"
                    class="group flex cursor-pointer items-center gap-2 rounded-md border p-1.5 transition-colors"
                    :class="
                        scene.id === currentSceneId
                            ? 'border-primary bg-accent'
                            : 'border-transparent hover:bg-accent/60'
                    "
                    role="button"
                    tabindex="0"
                    @click="emit('select', scene.id)"
                    @keydown.enter="emit('select', scene.id)"
                >
                    <GripVertical
                        v-if="canEdit"
                        class="scene-handle size-4 shrink-0 cursor-grab text-muted-foreground"
                        aria-label="Drag to reorder"
                        @click.stop
                    />
                    <div
                        class="relative h-10 w-16 shrink-0 overflow-hidden rounded bg-muted"
                    >
                        <img
                            v-if="scene.media.thumbnail_url"
                            :src="scene.media.thumbnail_url"
                            :alt="scene.title"
                            class="size-full object-cover"
                            :class="{ 'opacity-40': !scene.is_visible }"
                            loading="lazy"
                        />
                        <span
                            class="absolute bottom-0 left-0 rounded-tr bg-black/60 px-1 text-[10px] text-white"
                            >{{ index + 1 }}</span
                        >
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm">{{ scene.title }}</p>
                        <p
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <Star
                                v-if="scene.id === startSceneId"
                                class="size-3 fill-current text-amber-500"
                                aria-label="Start scene"
                            />
                            <EyeOff
                                v-if="!scene.is_visible"
                                class="size-3"
                                aria-label="Hidden"
                            />
                            <AlertTriangle
                                v-if="!scene.media.is_equirectangular"
                                class="size-3 text-amber-500"
                                aria-label="Not a 2:1 panorama"
                            />
                            {{ scene.hotspots.length }} hotspot{{
                                scene.hotspots.length === 1 ? '' : 's'
                            }}
                        </p>
                    </div>
                    <DropdownMenu v-if="canEdit">
                        <DropdownMenuTrigger as-child @click.stop>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-7 opacity-60 group-hover:opacity-100"
                                :aria-label="`Actions for ${scene.title}`"
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                :disabled="scene.id === startSceneId"
                                @click="emit('makeStart', scene.id)"
                            >
                                <Star /> Make start scene
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                @click="emit('duplicate', scene.id)"
                            >
                                <Copy /> Duplicate
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                @click="emit('remove', scene)"
                            >
                                <Trash2 /> Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </VueDraggable>

            <button
                v-if="!scenes.length && canEdit"
                type="button"
                class="flex w-full flex-col items-center gap-2 rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground hover:border-foreground/30"
                @click="emit('add')"
            >
                <Plus class="size-5" />
                Add your first panorama
            </button>
        </div>
    </div>
</template>
