<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Loader2, Trash2, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import media from '@/routes/media';

const props = defineProps<{ ids: string[]; total: number }>();
const emit = defineEmits<{ clear: []; 'select-all': [] }>();

const confirming = ref(false);
const form = useForm<{ ids: string[] }>({ ids: [] });

function destroy(): void {
    form.ids = props.ids;
    form.delete(media.destroyMany.url(), {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            emit('clear');
        },
    });
}
</script>

<template>
    <div
        v-if="ids.length"
        class="sticky bottom-4 z-10 mx-auto flex w-fit max-w-full flex-wrap items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm shadow-lg"
    >
        <span class="font-medium tabular-nums">{{ ids.length }} selected</span>
        <button
            v-if="ids.length < total"
            type="button"
            class="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            @click="emit('select-all')"
        >
            Select all {{ total }}
        </button>
        <Button
            size="sm"
            variant="destructive"
            class="rounded-full"
            @click="confirming = true"
        >
            <Trash2 /> Delete
        </Button>
        <Button
            size="icon-sm"
            variant="ghost"
            class="rounded-full"
            aria-label="Clear selection"
            @click="emit('clear')"
        >
            <X />
        </Button>
    </div>

    <Dialog v-model:open="confirming">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle
                    >Delete {{ ids.length }} file{{
                        ids.length === 1 ? '' : 's'
                    }}?</DialogTitle
                >
                <DialogDescription>
                    This permanently deletes the selected files from storage and
                    cannot be undone. Panoramas used in a tour are kept.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirming = false"
                    >Cancel</Button
                >
                <Button
                    variant="destructive"
                    :disabled="form.processing"
                    @click="destroy"
                >
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    Delete permanently
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
