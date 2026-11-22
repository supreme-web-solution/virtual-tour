<script setup lang="ts">
import { FileVideo, ImageOff, Loader2, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import type { UploadItem } from '@/composables/useMediaUpload';

/**
 * A small preview of a file that is being uploaded: the picture itself when
 * the browser can show it, with the upload state on top.
 */
defineProps<{ item: UploadItem }>();

const broken = ref(false);
</script>

<template>
    <div
        class="relative flex h-11 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted"
    >
        <img
            v-if="item.preview && !broken"
            :src="item.preview"
            alt=""
            class="size-full object-cover"
            @error="broken = true"
        />
        <FileVideo
            v-else-if="item.file.type.startsWith('video/')"
            class="size-4 text-muted-foreground"
        />
        <ImageOff v-else class="size-4 text-muted-foreground" />

        <span
            v-if="item.status === 'uploading' || item.status === 'verifying'"
            class="absolute inset-0 flex items-center justify-center bg-black/40"
        >
            <Loader2 class="size-4 animate-spin text-white" />
        </span>
        <span
            v-else-if="item.status === 'error'"
            class="absolute inset-0 flex items-center justify-center bg-black/50"
        >
            <TriangleAlert class="size-4 text-red-300" />
        </span>
    </div>
</template>
