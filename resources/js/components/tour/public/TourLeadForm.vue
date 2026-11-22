<script setup lang="ts">
import { CheckCircle2, Loader2 } from '@lucide/vue';
import { reactive, ref } from 'vue';
import type { PublicLeadForm } from '@/types';

const props = defineProps<{
    form: PublicLeadForm;
    visitId: string | null;
}>();

const startedAt = Date.now();
const values = reactive({
    name: '',
    email: '',
    phone: '',
    message: '',
});
/** Real key presses and clicks on the form. Scripts that only set values send none. */
let humanEvents = 0;

function countHumanEvent(event: Event): void {
    if (event.isTrusted) {
        humanEvents++;
    }
}

const errors = ref<Record<string, string>>({});
const sending = ref(false);
const sent = ref<string | null>(null);

/** The server rejects forms sent faster than a person could fill them in. */
const MIN_FILL_MS = 2600;

async function submit(): Promise<void> {
    sending.value = true;
    errors.value = {};

    // Browser autofill can complete the form instantly; wait out the minimum.
    const remaining = MIN_FILL_MS - (Date.now() - startedAt);

    if (remaining > 0) {
        await new Promise((resolve) => setTimeout(resolve, remaining));
    }

    try {
        const response = await fetch(props.form.submit_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                ...values,
                phone: values.phone || null,
                message: values.message || null,
                visit_id: props.visitId,
                form_token: props.form.form_token,
                human_events: humanEvents,
            }),
        });
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (response.ok) {
            sent.value = data.message ?? props.form.thank_you;
            // Clear everything so the next visitor never sees these details.
            Object.assign(values, {
                name: '',
                email: '',
                phone: '',
                message: '',
            });

            return;
        }

        errors.value = Object.fromEntries(
            Object.entries(data.errors ?? {}).map(([key, messages]) => [
                key,
                messages[0],
            ]),
        );

        if (!Object.keys(errors.value).length) {
            errors.value = {
                form:
                    response.status === 429
                        ? 'Too many attempts. Please wait a minute and try again.'
                        : 'Your message could not be sent. Please try again.',
            };
        }
    } catch {
        errors.value = {
            form: 'Your message could not be sent. Check your connection and try again.',
        };
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div
        v-if="sent"
        class="flex flex-col items-center gap-3 py-8 text-center"
        role="status"
        aria-live="polite"
    >
        <CheckCircle2 class="size-10 text-[var(--tour-accent)]" />
        <p class="text-base font-medium">Message sent</p>
        <p class="text-sm text-white/80">{{ sent }}</p>
        <button
            type="button"
            class="mt-1 text-xs text-white/70 underline underline-offset-2 hover:text-white"
            @click="sent = null"
        >
            Send another message
        </button>
    </div>
    <form
        v-else
        class="space-y-3"
        @pointerdown.capture="countHumanEvent"
        @keydown.capture="countHumanEvent"
        @submit.prevent="submit"
    >
        <h2 class="text-lg font-semibold">{{ form.title }}</h2>
        <label class="grid gap-1 text-sm">
            Name
            <input
                v-model="values.name"
                required
                maxlength="120"
                autocomplete="name"
                class="tour-input"
            />
            <span v-if="errors.name" class="text-xs text-red-300">{{
                errors.name
            }}</span>
        </label>
        <label class="grid gap-1 text-sm">
            Email
            <input
                v-model="values.email"
                type="email"
                required
                maxlength="255"
                autocomplete="email"
                class="tour-input"
            />
            <span v-if="errors.email" class="text-xs text-red-300">{{
                errors.email
            }}</span>
        </label>
        <label class="grid gap-1 text-sm">
            Phone{{ form.require_phone ? '' : ' (optional)' }}
            <input
                v-model="values.phone"
                type="tel"
                :required="form.require_phone"
                maxlength="40"
                autocomplete="tel"
                class="tour-input"
            />
            <span v-if="errors.phone" class="text-xs text-red-300">{{
                errors.phone
            }}</span>
        </label>
        <label class="grid gap-1 text-sm">
            Message (optional)
            <textarea
                v-model="values.message"
                rows="4"
                maxlength="2000"
                class="tour-input"
            />
            <span v-if="errors.message" class="text-xs text-red-300">{{
                errors.message
            }}</span>
        </label>
        <p v-if="errors.form" class="text-xs text-red-300">{{ errors.form }}</p>
        <button
            type="submit"
            class="flex w-full items-center justify-center gap-2 rounded-md bg-[var(--tour-primary)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-60"
            :disabled="sending"
        >
            <Loader2 v-if="sending" class="size-4 animate-spin" />
            {{ sending ? 'Sending…' : form.button_label }}
        </button>
        <p class="text-[11px] text-white/60">
            Your details are sent only to the owner of this tour.
        </p>
    </form>
</template>
