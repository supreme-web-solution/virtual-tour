<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, ImageOff } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import properties from '@/routes/properties';
import type { Property, PropertyOptions } from '@/types';

type CoverChoice = {
    id: string;
    thumbnail_url: string | null;
    name: string | null;
    asset_type: string;
};

const props = defineProps<{
    options: PropertyOptions;
    property?: Property;
    coverChoices?: CoverChoice[];
}>();

const emit = defineEmits<{ saved: []; cancel: [] }>();

const form = useForm({
    name: props.property?.name ?? '',
    property_type: props.property?.property_type ?? 'residential',
    status: props.property?.status ?? 'draft',
    description: props.property?.description ?? '',
    address: props.property?.address ?? '',
    city: props.property?.city ?? '',
    region: props.property?.region ?? '',
    country: props.property?.country ?? '',
    latitude: props.property?.latitude ?? '',
    longitude: props.property?.longitude ?? '',
    bedrooms: props.property?.bedrooms?.toString() ?? '',
    bathrooms: props.property?.bathrooms ?? '',
    area: props.property?.area ?? '',
    area_unit: props.property?.area_unit ?? 'sqm',
    price: props.property?.price ?? '',
    currency: props.property?.currency ?? '',
    cover_asset_id: props.property?.cover?.id ?? '',
});

const areaUnitOptions = props.options.area_units.map((unit) => ({
    value: unit,
    label: unit === 'sqm' ? 'm²' : 'ft²',
}));

function submit(): void {
    form.transform((data) =>
        Object.fromEntries(
            Object.entries(data).map(([key, value]) => [
                key,
                value === '' ? null : value,
            ]),
        ),
    );

    const options = { preserveScroll: true, onSuccess: () => emit('saved') };

    if (props.property) {
        form.submit(properties.update(props.property.id), options);
    } else {
        form.submit(properties.store(), options);
    }
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <div>
                <h2 class="text-base font-medium">Details</h2>
                <p class="text-sm text-muted-foreground">
                    The basics that identify this property.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    maxlength="160"
                    placeholder="e.g. Banana Island Penthouse"
                    :aria-invalid="!!form.errors.name"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="property_type">Property type</Label>
                    <NativeSelect
                        id="property_type"
                        v-model="form.property_type"
                        :options="options.types"
                    />
                    <InputError :message="form.errors.property_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="status">Status</Label>
                    <NativeSelect
                        id="status"
                        v-model="form.status"
                        :options="options.statuses"
                    />
                    <InputError :message="form.errors.status" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <Textarea
                    id="description"
                    v-model="form.description"
                    rows="5"
                    placeholder="Describe the property, its standout features and surroundings."
                />
                <InputError :message="form.errors.description" />
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-base font-medium">Location</h2>
                <p class="text-sm text-muted-foreground">
                    Coordinates are optional and enable map features later.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="address">Address</Label>
                <Input id="address" v-model="form.address" maxlength="255" />
                <InputError :message="form.errors.address" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input id="city" v-model="form.city" maxlength="120" />
                    <InputError :message="form.errors.city" />
                </div>
                <div class="grid gap-2">
                    <Label for="region">State / region</Label>
                    <Input id="region" v-model="form.region" maxlength="120" />
                    <InputError :message="form.errors.region" />
                </div>
                <div class="grid gap-2">
                    <Label for="country">Country</Label>
                    <Input
                        id="country"
                        v-model="form.country"
                        maxlength="120"
                    />
                    <InputError :message="form.errors.country" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="latitude">Latitude</Label>
                    <Input
                        id="latitude"
                        v-model="form.latitude"
                        inputmode="decimal"
                        placeholder="6.4281"
                    />
                    <InputError :message="form.errors.latitude" />
                </div>
                <div class="grid gap-2">
                    <Label for="longitude">Longitude</Label>
                    <Input
                        id="longitude"
                        v-model="form.longitude"
                        inputmode="decimal"
                        placeholder="3.4219"
                    />
                    <InputError :message="form.errors.longitude" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div>
                <h2 class="text-base font-medium">Specifications</h2>
                <p class="text-sm text-muted-foreground">
                    Leave blank anything that doesn't apply.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="bedrooms">Bedrooms</Label>
                    <Input
                        id="bedrooms"
                        v-model="form.bedrooms"
                        type="number"
                        min="0"
                    />
                    <InputError :message="form.errors.bedrooms" />
                </div>
                <div class="grid gap-2">
                    <Label for="bathrooms">Bathrooms</Label>
                    <Input
                        id="bathrooms"
                        v-model="form.bathrooms"
                        type="number"
                        min="0"
                        step="0.5"
                    />
                    <InputError :message="form.errors.bathrooms" />
                </div>
                <div class="grid gap-2">
                    <Label for="area">Area</Label>
                    <Input
                        id="area"
                        v-model="form.area"
                        type="number"
                        min="0"
                        step="0.01"
                    />
                    <InputError :message="form.errors.area" />
                </div>
                <div class="grid gap-2">
                    <Label for="area_unit">Unit</Label>
                    <NativeSelect
                        id="area_unit"
                        v-model="form.area_unit"
                        :options="areaUnitOptions"
                    />
                    <InputError :message="form.errors.area_unit" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="grid gap-2 sm:col-span-3">
                    <Label for="price">Price (optional)</Label>
                    <Input
                        id="price"
                        v-model="form.price"
                        type="number"
                        min="0"
                        step="0.01"
                    />
                    <InputError :message="form.errors.price" />
                </div>
                <div class="grid gap-2">
                    <Label for="currency">Currency</Label>
                    <Input
                        id="currency"
                        v-model="form.currency"
                        maxlength="3"
                        placeholder="NGN"
                        class="uppercase"
                    />
                    <InputError :message="form.errors.currency" />
                </div>
            </div>
        </section>

        <section v-if="property" class="space-y-4">
            <div>
                <h2 class="text-base font-medium">Cover image</h2>
                <p class="text-sm text-muted-foreground">
                    Choose from this property's images.
                </p>
            </div>

            <div
                v-if="coverChoices && coverChoices.length"
                class="grid grid-cols-3 gap-3 sm:grid-cols-5"
            >
                <button
                    type="button"
                    class="relative flex aspect-[4/3] items-center justify-center overflow-hidden rounded-md border bg-muted text-xs text-muted-foreground"
                    :class="
                        form.cover_asset_id === ''
                            ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                            : ''
                    "
                    @click="form.cover_asset_id = ''"
                >
                    <ImageOff class="size-5" />
                    <span class="sr-only">No cover</span>
                </button>
                <button
                    v-for="choice in coverChoices"
                    :key="choice.id"
                    type="button"
                    class="relative aspect-[4/3] overflow-hidden rounded-md border bg-muted"
                    :class="
                        form.cover_asset_id === choice.id
                            ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                            : ''
                    "
                    :title="choice.name ?? undefined"
                    @click="form.cover_asset_id = choice.id"
                >
                    <img
                        v-if="choice.thumbnail_url"
                        :src="choice.thumbnail_url"
                        :alt="choice.name ?? 'Property image'"
                        class="size-full object-cover"
                        loading="lazy"
                    />
                    <span
                        v-if="form.cover_asset_id === choice.id"
                        class="absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground"
                    >
                        <Check class="size-3" />
                    </span>
                </button>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Upload images to this property to choose a cover.
            </p>
            <InputError :message="form.errors.cover_asset_id" />
        </section>

        <div class="flex items-center gap-3 border-t pt-6">
            <Button type="submit" :disabled="form.processing">
                {{ property ? 'Save changes' : 'Create property' }}
            </Button>
            <Button type="button" variant="ghost" @click="emit('cancel')"
                >Cancel</Button
            >
        </div>
    </form>
</template>
