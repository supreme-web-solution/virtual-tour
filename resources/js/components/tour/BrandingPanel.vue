<script setup lang="ts">
import { Contact, Palette, PanelTop } from '@lucide/vue';
import PanelSection from '@/components/tour/PanelSection.vue';
import { usePage } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch';
import { Loader2, Upload } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { useMediaUpload } from '@/composables/useMediaUpload';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { AssetTypeOption, EditorTour, LibraryAsset } from '@/types';

const props = defineProps<{
    tour: EditorTour;
    images: LibraryAsset[];
    assetTypes: AssetTypeOption[];
    canEdit: boolean;
}>();

const emit = defineEmits<{
    update: [data: Record<string, unknown>];
}>();

const page = usePage();
const canRemoveBadge = computed(
    () => page.props.workspace?.plan.features.remove_branding ?? true,
);

// Text fields are edited locally and saved on blur.
const introDraft = reactive({ title: '', text: '', button: '' });
const fields = reactive({
    contactPerson: '',
    contactWebsite: '',
    contactEmail: '',
    contactPhone: '',
});

const contactFields = [
    {
        key: 'name',
        model: 'contactPerson',
        label: 'Name or agency',
        type: 'text',
        max: 120,
    },
    {
        key: 'email',
        model: 'contactEmail',
        label: 'Email',
        type: 'email',
        max: 255,
    },
    {
        key: 'phone',
        model: 'contactPhone',
        label: 'Phone',
        type: 'tel',
        max: 40,
    },
    {
        key: 'website',
        model: 'contactWebsite',
        label: 'Website',
        type: 'url',
        max: 255,
    },
] as const;

watch(
    () => props.tour.branding,
    (branding) => {
        introDraft.title = branding.intro.title ?? '';
        introDraft.text = branding.intro.text ?? '';
        introDraft.button = branding.intro.button_label ?? '';
        fields.contactPerson = branding.contact.name ?? '';
        fields.contactEmail = branding.contact.email ?? '';
        fields.contactPhone = branding.contact.phone ?? '';
        fields.contactWebsite = branding.contact.website ?? '';
    },
    { immediate: true },
);

// Upload a logo straight from here and use it right away.
const upload = useMediaUpload(props.assetTypes);
const logoInput = ref<HTMLInputElement | null>(null);
const uploadedLogos = ref<LibraryAsset[]>([]);
const logoOptions = computed(() =>
    [...uploadedLogos.value, ...props.images].map((image) => ({
        value: image.id,
        label: image.name ?? 'Image',
    })),
);
const logoError = computed(
    () => upload.items.value.find((item) => item.status === 'error')?.error,
);

function pickLogo(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files?.length) {
        upload.clear();
        upload.add([input.files[0]]);
        void upload.start('logo', null);
    }

    input.value = '';
}

watch(
    () => upload.items.value.map((item) => item.asset?.id ?? null),
    () => {
        for (const item of upload.items.value) {
            if (item.asset) {
                uploadedLogos.value = [
                    {
                        id: item.asset.id,
                        name: item.asset.original_filename,
                        type: 'logo',
                        thumbnail_url: item.asset.thumbnail_url,
                        url: item.asset.secure_url,
                    },
                    ...uploadedLogos.value,
                ];
                emit('update', { branding: { logo_asset_id: item.asset.id } });
                upload.remove(item.key);
            }
        }
    },
);

function save(branding: Record<string, unknown>): void {
    emit('update', { branding });
}

function saveText(
    group: 'intro' | 'contact',
    key: string,
    value: string,
    current: string | null,
): void {
    const trimmed = value.trim();

    if (trimmed !== (current ?? '')) {
        save({ [group]: { [key]: trimmed === '' ? null : trimmed } });
    }
}
</script>

<template>
    <fieldset :disabled="!canEdit" class="min-w-0 space-y-6 p-3">
        <PanelSection
            title="Logo & colours"
            :icon="Palette"
            tone="violet"
            help="Your logo and accent colour, shown on the tour's buttons and intro screen."
        >
            <div class="grid gap-2">
                <Label for="brand-logo">Logo</Label>
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded border bg-muted"
                    >
                        <img
                            v-if="tour.branding.logo_url"
                            :src="tour.branding.logo_url"
                            alt="Logo"
                            class="max-h-full max-w-full object-contain"
                        />
                    </div>
                    <NativeSelect
                        id="brand-logo"
                        class="flex-1"
                        :model-value="tour.branding.logo_asset_id ?? ''"
                        :options="logoOptions"
                        placeholder="No logo"
                        @update:model-value="
                            save({ logo_asset_id: $event || null })
                        "
                    />
                </div>
                <div class="flex items-center gap-2">
                    <input
                        ref="logoInput"
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp"
                        class="sr-only"
                        tabindex="-1"
                        @change="pickLogo"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="upload.running.value"
                        @click="logoInput?.click()"
                    >
                        <Loader2
                            v-if="upload.running.value"
                            class="animate-spin"
                        />
                        <Upload v-else />
                        {{
                            upload.running.value
                                ? 'Uploading…'
                                : 'Upload a logo'
                        }}
                    </Button>
                    <span class="text-xs text-muted-foreground"
                        >PNG, JPG or WebP</span
                    >
                </div>
                <p v-if="logoError" class="text-xs text-destructive">
                    {{ logoError }}
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <label class="grid gap-1.5 text-sm">
                    Primary colour
                    <input
                        type="color"
                        class="h-9 w-full cursor-pointer rounded-md border bg-transparent p-1"
                        :value="tour.branding.primary_color"
                        @change="
                            save({
                                primary_color: (
                                    $event.target as HTMLInputElement
                                ).value,
                            })
                        "
                    />
                </label>
                <label class="grid gap-1.5 text-sm">
                    Accent colour
                    <input
                        type="color"
                        class="h-9 w-full cursor-pointer rounded-md border bg-transparent p-1"
                        :value="tour.branding.accent_color"
                        @change="
                            save({
                                accent_color: (
                                    $event.target as HTMLInputElement
                                ).value,
                            })
                        "
                    />
                </label>
            </div>
            <label
                v-if="canRemoveBadge"
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Show “Powered by” badge</span
                ><Switch
                    :model-value="
                        tour.branding.show_platform_branding || !canRemoveBadge
                    "
                    :disabled="!canEdit || !canRemoveBadge"
                    @update:model-value="
                        save({ show_platform_branding: $event === true })
                    "
            /></label>
        </PanelSection>

        <PanelSection
            title="Contact details"
            :icon="Contact"
            tone="emerald"
            help="Name, phone and email shown on the tour so visitors can reach you."
        >
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Show contact details in the tour</span
                ><Switch
                    :model-value="tour.branding.contact.enabled"
                    @update:model-value="
                        save({ contact: { enabled: $event === true } })
                    "
            /></label>
            <div v-if="tour.branding.contact.enabled" class="grid gap-4">
                <p class="text-xs text-muted-foreground">
                    Shown to visitors in the tour's info panel.
                </p>
                <div
                    v-for="field in contactFields"
                    :key="field.key"
                    class="grid gap-2"
                >
                    <Label :for="'contact-' + field.key">{{
                        field.label
                    }}</Label>
                    <Input
                        :id="'contact-' + field.key"
                        :model-value="fields[field.model]"
                        :type="field.type"
                        :maxlength="field.max"
                        @update:model-value="
                            fields[field.model] = String($event ?? '')
                        "
                        @blur="
                            saveText(
                                'contact',
                                field.key,
                                fields[field.model],
                                tour.branding.contact[field.key],
                            )
                        "
                    />
                </div>
            </div>
        </PanelSection>

        <PanelSection
            title="Intro screen"
            :icon="PanelTop"
            tone="sky"
            help="The cover visitors see before the tour starts: a picture, the title and a start button."
        >
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Show a welcome screen before the tour</span
                ><Switch
                    :model-value="tour.branding.intro.enabled"
                    @update:model-value="
                        save({ intro: { enabled: $event === true } })
                    "
            /></label>
            <div v-if="tour.branding.intro.enabled" class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="intro-title">Title</Label>
                    <Input
                        id="intro-title"
                        :model-value="introDraft.title"
                        @update:model-value="
                            introDraft.title = String($event ?? '')
                        "
                        maxlength="120"
                        :placeholder="tour.title"
                        @blur="
                            saveText(
                                'intro',
                                'title',
                                introDraft.title,
                                tour.branding.intro.title,
                            )
                        "
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="intro-text">Message</Label>
                    <Textarea
                        id="intro-text"
                        :model-value="introDraft.text"
                        @update:model-value="
                            introDraft.text = String($event ?? '')
                        "
                        rows="3"
                        maxlength="1000"
                        @blur="
                            saveText(
                                'intro',
                                'text',
                                introDraft.text,
                                tour.branding.intro.text,
                            )
                        "
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="intro-button">Button label</Label>
                    <Input
                        id="intro-button"
                        :model-value="introDraft.button"
                        @update:model-value="
                            introDraft.button = String($event ?? '')
                        "
                        maxlength="40"
                        @blur="
                            saveText(
                                'intro',
                                'button_label',
                                introDraft.button,
                                tour.branding.intro.button_label,
                            )
                        "
                    />
                </div>
            </div>
        </PanelSection>
    </fieldset>
</template>
