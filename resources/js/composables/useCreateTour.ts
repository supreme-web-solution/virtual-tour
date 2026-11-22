import { ref } from 'vue';
import type { CreateMode } from '@/types';

/**
 * The one "Create tour" dialog, opened from anywhere in the app (sidebar,
 * dashboard, tours list, a property page).
 */
const isOpen = ref(false);
const initialMode = ref<CreateMode>('describe');
const propertyId = ref<string | null>(null);

export function useCreateTour() {
    function openCreateTour(
        mode: CreateMode = 'describe',
        property: string | null = null,
    ): void {
        initialMode.value = mode;
        propertyId.value = property;
        isOpen.value = true;
    }

    return { isOpen, initialMode, propertyId, openCreateTour };
}
