<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ExternalLink,
    Loader2,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import EnhancePhotoDialog from '@/components/media/EnhancePhotoDialog.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatBytes, formatDate } from '@/lib/format';
import media from '@/routes/media';
import properties from '@/routes/properties';
import type { AssetTypeOption, MediaAsset } from '@/types';

const props = defineProps<{
    asset: MediaAsset | null;
    assetTypes: AssetTypeOption[];
    properties: { id: string; name: string }[];
}>();

const open = defineModel<boolean>('open', { default: false });

const confirmingDelete = ref(false);
const enhanceOpen = ref(false);

const form = useForm({
    original_filename: '',
    property_id: '',
    asset_type: '',
});

const deleteForm = useForm({});

watch(
    () => props.asset,
    (asset) => {
        confirmingDelete.value = false;
        form.clearErrors();
        form.defaults({
            original_filename: asset?.original_filename ?? '',
            property_id: asset?.property?.id ?? '',
            asset_type: asset?.asset_type ?? '',
        });
        form.reset();
    },
    { immediate: true },
);

const typeOptions = computed(() => {
    if (!props.asset) {
        return [];
    }

    if (props.asset.resource_type !== 'image') {
        return props.assetTypes.filter(
            (type) => type.value === props.asset?.asset_type,
        );
    }

    return props.assetTypes.filter((type) =>
        ['photo', 'panorama', 'logo', 'floor_plan'].includes(type.value),
    );
});

const propertyOptions = computed(() =>
    props.properties.map((property) => ({
        value: property.id,
        label: property.name,
    })),
);

function save(): void {
    if (!props.asset) {
        return;
    }

    form.transform((data) => ({
        ...data,
        property_id: data.property_id || null,
    })).submit(media.update(props.asset.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

function destroy(): void {
    if (!props.asset) {
        return;
    }

    deleteForm.submit(media.destroy(props.asset.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 overflow-y-auto sm:max-w-lg">
            <template v-if="asset">
                <SheetHeader class="border-b">
                    <SheetTitle class="truncate pr-6">
                        {{ asset.original_filename ?? 'Untitled' }}
                    </SheetTitle>
                    <SheetDescription>
                        {{ asset.asset_type_label }} · uploaded
                        {{ formatDate(asset.created_at) }}
                        <template v-if="asset.uploader">
                            by {{ asset.uploader }}</template
                        >
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-6 p-4">
                    <div class="overflow-hidden rounded-md border bg-muted">
                        <video
                            v-if="asset.asset_type === 'video'"
                            :src="asset.secure_url"
                            controls
                            preload="metadata"
                            class="w-full"
                        />
                        <audio
                            v-else-if="asset.asset_type === 'audio'"
                            :src="asset.secure_url"
                            controls
                            preload="metadata"
                            class="w-full p-3"
                        />
                        <img
                            v-else
                            :src="asset.thumbnail_url ?? asset.secure_url"
                            :alt="asset.original_filename ?? 'Media preview'"
                            class="w-full object-contain"
                        />
                    </div>

                    <div
                        v-if="asset.panorama?.warnings.length"
                        class="flex gap-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        <ul class="space-y-1">
                            <li
                                v-for="warning in asset.panorama.warnings"
                                :key="warning"
                            >
                                {{ warning }}
                            </li>
                        </ul>
                    </div>

                    <Button
                        v-if="asset.asset_type === 'photo' && asset.can.update"
                        variant="outline"
                        class="w-full"
                        @click="enhanceOpen = true"
                    >
                        <Loader2
                            v-if="asset.enhancement_id"
                            class="animate-spin"
                        />
                        <Sparkles v-else />
                        {{
                            asset.enhancement_id
                                ? 'Enhancing… view progress'
                                : 'Enhance with AI'
                        }}
                    </Button>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Format</dt>
                            <dd class="uppercase">{{ asset.format }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Size</dt>
                            <dd>{{ formatBytes(asset.file_size) }}</dd>
                        </div>
                        <div v-if="asset.width && asset.height">
                            <dt class="text-muted-foreground">Dimensions</dt>
                            <dd>{{ asset.width }} × {{ asset.height }} px</dd>
                        </div>
                        <div v-if="asset.duration">
                            <dt class="text-muted-foreground">Duration</dt>
                            <dd>{{ asset.duration.toFixed(1) }} s</dd>
                        </div>
                        <div v-if="asset.property">
                            <dt class="text-muted-foreground">Property</dt>
                            <dd class="truncate">
                                <Link
                                    :href="properties.show(asset.property.id)"
                                    class="underline-offset-4 hover:underline"
                                    >{{ asset.property.name }}</Link
                                >
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <a
                                :href="asset.secure_url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 text-sm underline-offset-4 hover:underline"
                            >
                                Open original
                                <ExternalLink class="size-3.5" />
                            </a>
                        </div>
                    </dl>

                    <form
                        v-if="asset.can.update"
                        class="space-y-4 border-t pt-6"
                        @submit.prevent="save"
                    >
                        <div class="grid gap-2">
                            <Label for="media-filename">Name</Label>
                            <Input
                                id="media-filename"
                                v-model="form.original_filename"
                                maxlength="255"
                            />
                            <InputError
                                :message="form.errors.original_filename"
                            />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="media-type">Type</Label>
                                <NativeSelect
                                    id="media-type"
                                    v-model="form.asset_type"
                                    :options="typeOptions"
                                />
                                <InputError :message="form.errors.asset_type" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="media-property">Property</Label>
                                <NativeSelect
                                    id="media-property"
                                    v-model="form.property_id"
                                    :options="propertyOptions"
                                    placeholder="Not assigned"
                                />
                                <InputError
                                    :message="form.errors.property_id"
                                />
                            </div>
                        </div>
                        <Button
                            type="submit"
                            :disabled="form.processing || !form.isDirty"
                            >Save changes</Button
                        >
                    </form>

                    <div v-if="asset.can.delete" class="border-t pt-6">
                        <div
                            v-if="confirmingDelete"
                            class="space-y-3 rounded-md border border-destructive/40 p-3"
                        >
                            <p class="text-sm">
                                This permanently deletes the file from storage.
                                It can't be undone.
                            </p>
                            <div class="flex gap-2">
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    :disabled="deleteForm.processing"
                                    @click="destroy"
                                    >Delete permanently</Button
                                >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="confirmingDelete = false"
                                    >Keep it</Button
                                >
                            </div>
                        </div>
                        <Button
                            v-else
                            variant="ghost"
                            size="sm"
                            class="text-destructive hover:text-destructive"
                            @click="confirmingDelete = true"
                        >
                            <Trash2 /> Delete
                        </Button>
                    </div>
                </div>
            </template>
        </SheetContent>
    </Sheet>

    <EnhancePhotoDialog
        v-model:open="enhanceOpen"
        :asset="asset"
        :resume-id="asset?.enhancement_id ?? null"
    />
</template>
