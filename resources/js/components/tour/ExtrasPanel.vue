<script setup lang="ts">
import { ChartColumn, Loader2, Mail, MapPin, Music, Send } from '@lucide/vue';
import PanelSection from '@/components/tour/PanelSection.vue';
import StreetViewSection from '@/components/tour/StreetViewSection.vue';
import { Link } from '@inertiajs/vue3';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { reactive, ref, watch } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { HttpError, postJson } from '@/lib/http';
import properties from '@/routes/properties';
import { leadTest } from '@/routes/tours';
import type { EditorTour, LibraryAsset } from '@/types';

const props = defineProps<{
    tour: EditorTour;
    audio: LibraryAsset[];
    canEdit: boolean;
}>();

const emit = defineEmits<{
    update: [data: Record<string, unknown>];
}>();

const sendingTest = ref(false);
const testResult = ref<{ ok: boolean; text: string } | null>(null);

async function sendTestEmail(): Promise<void> {
    sendingTest.value = true;
    testResult.value = null;

    try {
        const response = await postJson<{ to: string; delivered: boolean }>(
            leadTest.url(props.tour.id),
            {},
        );
        testResult.value = {
            ok: true,
            text: response.delivered
                ? `Sent to ${response.to}. Check the inbox (and spam).`
                : `Mail is in log-only mode on this server, so nothing was delivered to ${response.to}. The email was written to the log.`,
        };
    } catch (exception) {
        testResult.value = {
            ok: false,
            text:
                exception instanceof HttpError
                    ? exception.message
                    : 'Could not send the test email. Please try again.',
        };
    } finally {
        sendingTest.value = false;
    }
}

const lead = reactive({ title: '', button: '', thankYou: '' });

watch(
    () => props.tour.lead_settings,
    (settings) => {
        lead.title = settings.title;
        lead.button = settings.button_label;
        lead.thankYou = settings.thank_you;
    },
    { immediate: true },
);

function saveLead(key: string, value: string, current: string): void {
    if (value.trim() !== current) {
        emit('update', { lead_settings: { [key]: value.trim() || null } });
    }
}
</script>

<template>
    <fieldset :disabled="!canEdit" class="space-y-6 p-3">
        <PanelSection title="Background music" :icon="Music" tone="rose">
            <NativeSelect
                :model-value="tour.audio.background_asset_id ?? ''"
                :options="
                    audio.map((track) => ({
                        value: track.id,
                        label: track.name ?? 'Audio',
                    }))
                "
                placeholder="No background audio"
                aria-label="Background audio"
                @update:model-value="
                    emit('update', {
                        audio_settings: { background_asset_id: $event || null },
                    })
                "
            />
            <template v-if="tour.audio.background_asset_id">
                <div class="grid gap-2 text-sm">
                    <span>Volume {{ tour.audio.volume }}%</span
                    ><Slider
                        :model-value="tour.audio.volume"
                        :min="0"
                        :max="100"
                        :step="5"
                        @commit="
                            emit('update', {
                                audio_settings: { volume: $event },
                            })
                        "
                    />
                </div>
                <label
                    class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                    ><span>Loop</span
                    ><Switch
                        :model-value="tour.audio.loop"
                        @update:model-value="
                            emit('update', {
                                audio_settings: { loop: $event === true },
                            })
                        "
                /></label>
            </template>
            <p class="text-xs text-muted-foreground">
                Visitors turn sound on with a button; browsers block audio that
                plays by itself. Upload MP3, WAV or M4A files in the media
                library. Per-scene audio is set on each scene.
            </p>
        </PanelSection>

        <PanelSection title="Contact form" :icon="Mail" tone="amber">
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Let visitors send an enquiry</span
                ><Switch
                    :model-value="tour.lead_settings.enabled"
                    @update:model-value="
                        emit('update', {
                            lead_settings: { enabled: $event === true },
                        })
                    "
            /></label>
            <template v-if="tour.lead_settings.enabled">
                <div class="grid gap-2">
                    <Label for="lead-title">Form title</Label>
                    <Input
                        id="lead-title"
                        v-model="lead.title"
                        maxlength="120"
                        @blur="
                            saveLead(
                                'title',
                                lead.title,
                                tour.lead_settings.title,
                            )
                        "
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="lead-button">Button label</Label>
                    <Input
                        id="lead-button"
                        v-model="lead.button"
                        maxlength="40"
                        @blur="
                            saveLead(
                                'button_label',
                                lead.button,
                                tour.lead_settings.button_label,
                            )
                        "
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="lead-thanks">Thank-you message</Label>
                    <Input
                        id="lead-thanks"
                        v-model="lead.thankYou"
                        maxlength="300"
                        @blur="
                            saveLead(
                                'thank_you',
                                lead.thankYou,
                                tour.lead_settings.thank_you,
                            )
                        "
                    />
                </div>
                <label
                    class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                    ><span>Phone number required</span
                    ><Switch
                        :model-value="tour.lead_settings.require_phone"
                        @update:model-value="
                            emit('update', {
                                lead_settings: {
                                    require_phone: $event === true,
                                },
                            })
                        "
                /></label>
                <label
                    class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                    ><span>Email me when someone enquires</span
                    ><Switch
                        :model-value="tour.lead_settings.notify"
                        @update:model-value="
                            emit('update', {
                                lead_settings: { notify: $event === true },
                            })
                        "
                /></label>
                <div v-if="tour.lead_settings.notify" class="space-y-1.5">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="sendingTest || !canEdit"
                        @click="sendTestEmail"
                    >
                        <Loader2 v-if="sendingTest" class="animate-spin" />
                        <Send v-else />
                        Send a test email
                    </Button>
                    <p
                        v-if="testResult"
                        class="text-xs"
                        :class="
                            testResult.ok
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : 'text-red-600'
                        "
                    >
                        {{ testResult.text }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Sends a sample enquiry to whoever receives your real
                        ones, so you know the emails arrive.
                    </p>
                </div>
            </template>
        </PanelSection>

        <StreetViewSection :tour-id="tour.id" :can-edit="canEdit" />

        <PanelSection title="Map" :icon="MapPin" tone="emerald">
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Show the property location on a map</span
                ><Switch
                    :model-value="tour.map_settings.enabled"
                    :disabled="!tour.map_settings.has_coordinates"
                    @update:model-value="
                        emit('update', {
                            map_settings: { enabled: $event === true },
                        })
                    "
            /></label>
            <p
                v-if="!tour.map_settings.has_coordinates"
                class="text-xs text-muted-foreground"
            >
                <template v-if="tour.property">
                    Add latitude and longitude to
                    <Link
                        :href="properties.edit(tour.property.id)"
                        class="underline underline-offset-4"
                        >{{ tour.property.name }}</Link
                    >
                    to enable the map.
                </template>
                <template v-else>
                    Link the tour to a property with coordinates to enable the
                    map.
                </template>
            </p>
        </PanelSection>

        <PanelSection
            title="Visitor analytics"
            :icon="ChartColumn"
            tone="indigo"
        >
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span>Collect anonymous visit statistics</span
                ><Switch
                    :model-value="tour.analytics_enabled"
                    @update:model-value="
                        emit('update', { analytics_enabled: $event === true })
                    "
            /></label>
            <p class="text-xs text-muted-foreground">
                No names or IP addresses are stored. An anonymous random ID is
                kept in the visitor's browser to estimate unique visitors.
                Browsers that send Do Not Track or Global Privacy Control are
                never counted.
            </p>
        </PanelSection>
    </fieldset>
</template>
