<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

/**
 * Light / dark switch for the app header.
 */
const { resolvedAppearance, updateAppearance } = useAppearance();
</script>

<template>
    <button
        type="button"
        class="flex size-10 items-center justify-center rounded-full border bg-background text-foreground shadow-xs transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/40 focus-visible:outline-none"
        :aria-label="
            resolvedAppearance === 'dark'
                ? 'Switch to light mode'
                : 'Switch to dark mode'
        "
        :title="resolvedAppearance === 'dark' ? 'Light mode' : 'Dark mode'"
        @click="
            updateAppearance(resolvedAppearance === 'dark' ? 'light' : 'dark')
        "
    >
        <Sun v-if="resolvedAppearance === 'dark'" class="size-[18px]" />
        <Moon v-else class="size-[18px]" />
    </button>
</template>
