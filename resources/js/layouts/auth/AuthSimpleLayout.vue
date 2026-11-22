<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Compass, Share2, Wand2 } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { accents } from '@/lib/accent';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();

const points = [
    {
        icon: Wand2,
        tone: accents.violet,
        title: 'Build with AI',
        text: 'Upload photos or describe a space and get a tour.',
    },
    {
        icon: Compass,
        tone: accents.sky,
        title: 'Walk through in 360°',
        text: 'Hotspots, floor plans and smooth scene to scene moves.',
    },
    {
        icon: Share2,
        tone: accents.emerald,
        title: 'Share anywhere',
        text: 'One link, your branding, and every view tracked.',
    },
];

const ticks = Array.from({ length: 72 }, (_, index) => index);
</script>

<template>
    <div class="grid min-h-svh bg-background lg:grid-cols-[1.05fr_1fr]">
        <!-- Cinematic side -->
        <aside
            class="auth360 relative isolate hidden overflow-hidden bg-[#0a1029] p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-14"
        >
            <div
                class="absolute inset-0 -z-10 bg-[radial-gradient(60%_50%_at_75%_30%,#1b3a8a_0%,transparent_70%),radial-gradient(50%_40%_at_10%_90%,#0e4d7a_0%,transparent_70%)] opacity-70"
                aria-hidden="true"
            />

            <svg
                class="pointer-events-none absolute top-6 -right-28 -z-10 h-[62%] text-sky-300"
                viewBox="0 0 400 400"
                fill="none"
                aria-hidden="true"
            >
                <g class="stat360__ring" style="transform-origin: 200px 200px">
                    <circle
                        cx="200"
                        cy="200"
                        r="188"
                        stroke="currentColor"
                        stroke-opacity="0.3"
                        stroke-dasharray="2 7"
                    />
                    <line
                        v-for="tick in ticks"
                        :key="tick"
                        x1="200"
                        y1="8"
                        x2="200"
                        :y2="tick % 6 === 0 ? 24 : 16"
                        stroke="currentColor"
                        :stroke-opacity="tick % 6 === 0 ? 0.7 : 0.3"
                        :transform="`rotate(${tick * 5} 200 200)`"
                    />
                </g>
                <g
                    class="stat360__ring stat360__ring--reverse"
                    style="transform-origin: 200px 200px"
                >
                    <ellipse
                        v-for="angle in [0, 60, 120]"
                        :key="angle"
                        cx="200"
                        cy="200"
                        rx="150"
                        ry="52"
                        stroke="currentColor"
                        :stroke-opacity="angle === 0 ? 0.55 : 0.32"
                        :transform="`rotate(${angle} 200 200)`"
                    />
                    <circle cx="350" cy="200" r="5" fill="currentColor" />
                </g>
                <circle
                    cx="200"
                    cy="200"
                    r="74"
                    fill="#0a1029"
                    stroke="currentColor"
                    stroke-opacity="0.55"
                />
                <text
                    x="200"
                    y="214"
                    text-anchor="middle"
                    fill="white"
                    font-size="44"
                    font-weight="700"
                    letter-spacing="-1"
                >
                    360°
                </text>
            </svg>

            <!-- Floating hotspots -->
            <span
                v-for="(spot, index) in [
                    'top-[22%] left-[18%]',
                    'top-[58%] left-[44%]',
                    'top-[76%] left-[14%]',
                ]"
                :key="index"
                class="auth360__spot absolute"
                :class="spot"
                :style="{ animationDelay: `${index * 0.8}s` }"
                aria-hidden="true"
            />

            <Link
                :href="home()"
                class="flex w-fit items-center gap-3 text-lg font-semibold"
            >
                <span
                    class="grid size-10 place-items-center rounded-xl bg-sky-400 text-[#0a1029]"
                >
                    <AppLogoIcon class="size-6" />
                </span>
                {{ page.props.name }}
            </Link>

            <div class="max-w-md">
                <h2
                    class="text-4xl leading-tight font-semibold tracking-tight xl:text-5xl"
                >
                    Step inside any space, from anywhere.
                </h2>
                <p class="mt-4 text-slate-300">
                    Create immersive 360° virtual tours that sell, rent and
                    impress.
                </p>

                <ul class="mt-8 grid gap-3">
                    <li
                        v-for="point in points"
                        :key="point.title"
                        class="flex items-center gap-3 rounded-2xl border border-[#22346f] bg-[#0f1a45] p-3"
                    >
                        <span
                            class="grid size-10 shrink-0 place-items-center rounded-xl"
                            :class="point.tone"
                        >
                            <component :is="point.icon" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-medium">{{ point.title }}</p>
                            <p class="text-xs text-slate-400">
                                {{ point.text }}
                            </p>
                        </div>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Form side -->
        <main class="relative flex flex-col px-6 py-8 md:px-10">
            <div class="flex items-center justify-between">
                <Link
                    :href="home()"
                    class="flex items-center gap-2 font-semibold lg:invisible"
                >
                    <span
                        class="grid size-9 place-items-center rounded-xl bg-sky-400 text-[#0a1029]"
                    >
                        <AppLogoIcon class="size-5" />
                    </span>
                    {{ page.props.name }}
                </Link>
                <ThemeToggle />
            </div>

            <div class="flex flex-1 items-center justify-center py-8">
                <div class="w-full max-w-sm">
                    <div class="flex flex-col gap-8">
                        <div class="space-y-2">
                            <h1
                                class="text-2xl font-semibold tracking-tight md:text-3xl"
                            >
                                {{ title }}
                            </h1>
                            <p class="text-sm text-muted-foreground">
                                {{ description }}
                            </p>
                        </div>
                        <slot />
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
