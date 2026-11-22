<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import CreateTourPanel from '@/components/create/CreateTourPanel.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCreateTour } from '@/composables/useCreateTour';
import type { CreateMode } from '@/types';

/**
 * The app-wide "Create tour" dialog. Mounted once in the app layout and
 * opened with useCreateTour().openCreateTour() from any page.
 */
const { isOpen, initialMode, propertyId, openCreateTour } = useCreateTour();

// A fresh panel each time the dialog opens.
const session = ref(0);
watch(isOpen, (open) => {
    if (open) {
        session.value++;
    }
});

// Links such as /dashboard?create=link open the dialog on that tab.
onMounted(() => {
    const requested = new URLSearchParams(window.location.search).get('create');
    const modes: CreateMode[] = ['describe', 'photos', 'panoramas', 'link'];

    if (requested && modes.includes(requested as CreateMode)) {
        openCreateTour(requested as CreateMode);
    }
});
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Create a tour</DialogTitle>
                <DialogDescription>
                    Start with what you have. AI builds the 360° scenes and
                    links them.
                </DialogDescription>
            </DialogHeader>
            <CreateTourPanel
                :key="session"
                :initial-mode="initialMode"
                :property-id="propertyId"
            />
        </DialogContent>
    </Dialog>
</template>
