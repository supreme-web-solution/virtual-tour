<script setup lang="ts">
import PropertyForm from '@/components/property/PropertyForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Property, PropertyOptions } from '@/types';

/**
 * Create or edit a property without leaving the page.
 */
defineProps<{
    options: PropertyOptions;
    property?: Property;
    coverChoices?: {
        id: string;
        thumbnail_url: string | null;
        name: string | null;
        asset_type: string;
    }[];
}>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{
                    property ? `Edit ${property.name}` : 'New property'
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        property
                            ? 'Changes are saved when you press Save.'
                            : 'A property groups the photos, tours and videos for one place. Only the name is required.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <PropertyForm
                :options="options"
                :property="property"
                :cover-choices="coverChoices"
                @saved="open = false"
                @cancel="open = false"
            />
        </DialogContent>
    </Dialog>
</template>
