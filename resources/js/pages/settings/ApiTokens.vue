<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeAgo } from '@/lib/format';
import { index as billingIndex } from '@/routes/billing';
import { destroy, index, store } from '@/routes/api-tokens';

type Token = {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string | null;
};

defineProps<{
    allowed: boolean;
    canManage: boolean;
    tokens: Token[];
    newToken: string | null;
    endpoints: { rest: string; mcp: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API access', href: index() }],
    },
});

const copied = ref<string | null>(null);

async function copy(value: string, key: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        // Clipboard unavailable: the value stays selectable on screen.
    }
}

function remove(token: Token): void {
    if (
        confirm(`Delete the token "${token.name}"? Apps using it stop working.`)
    ) {
        router.delete(destroy.url(token.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="API access" />

    <h1 class="sr-only">API access</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="API access"
            description="Read this workspace's properties, tours, leads and media from your own tools or an AI assistant."
        />

        <div v-if="!allowed" class="rounded-lg border bg-muted/30 p-4 text-sm">
            API access is not part of this workspace's plan.
            <Link
                :href="billingIndex()"
                class="font-medium underline underline-offset-4"
                >See plans</Link
            >
        </div>

        <template v-else>
            <div
                v-if="newToken"
                class="space-y-2 rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-4"
            >
                <p class="text-sm font-medium">
                    Copy your new token now. It is shown only once.
                </p>
                <div class="flex items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 text-xs select-all"
                        >{{ newToken }}</code
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="copy(newToken, 'new')"
                    >
                        <Check v-if="copied === 'new'" /><Copy v-else />
                        Copy
                    </Button>
                </div>
            </div>

            <Form
                v-if="canManage"
                v-bind="store.form()"
                class="flex flex-wrap items-end gap-3"
                :reset-on-success="['name']"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="token-name">New token</Label>
                    <Input
                        id="token-name"
                        name="name"
                        maxlength="60"
                        placeholder="e.g. Website sync"
                        class="w-64"
                    />
                </div>
                <Button type="submit" :disabled="processing">
                    <KeyRound /> Create token
                </Button>
                <InputError :message="errors.name" />
            </Form>
            <p v-else class="text-sm text-muted-foreground">
                Only workspace owners and admins can create tokens.
            </p>

            <ul v-if="tokens.length" class="divide-y rounded-lg border">
                <li
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex items-center justify-between gap-3 px-3 py-2.5 text-sm"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ token.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            Created {{ timeAgo(token.created_at) }} ·
                            {{
                                token.last_used_at
                                    ? `last used ${timeAgo(token.last_used_at)}`
                                    : 'never used'
                            }}
                        </p>
                    </div>
                    <Button
                        v-if="canManage"
                        size="icon"
                        variant="ghost"
                        :aria-label="`Delete ${token.name}`"
                        @click="remove(token)"
                    >
                        <Trash2 />
                    </Button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">No tokens yet.</p>

            <section class="space-y-3 text-sm">
                <h3 class="font-medium">How to use it</h3>
                <p class="text-muted-foreground">
                    Send the token as a Bearer header. Tokens are read-only and
                    limited to this workspace (60 requests a minute).
                </p>
                <div class="space-y-1">
                    <p class="text-xs font-medium">REST API</p>
                    <code
                        class="block overflow-x-auto rounded bg-muted px-2 py-1.5 text-xs whitespace-pre"
                        >curl -H "Authorization: Bearer YOUR_TOKEN"
                        {{ endpoints.rest }}/tours</code
                    >
                    <p class="text-xs text-muted-foreground">
                        Also: /workspace, /properties, /properties/{id},
                        /tours/{id}, /leads, /media. Lists take ?per_page=.
                    </p>
                </div>
                <div class="space-y-1">
                    <p class="text-xs font-medium">
                        MCP (Claude and other AI assistants)
                    </p>
                    <div class="flex items-center gap-2">
                        <code
                            class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 text-xs"
                            >{{ endpoints.mcp }}</code
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="copy(endpoints.mcp, 'mcp')"
                        >
                            <Check v-if="copied === 'mcp'" /><Copy v-else />
                            Copy
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Add it as a remote MCP server with the header
                        Authorization: Bearer YOUR_TOKEN.
                    </p>
                </div>
            </section>
        </template>
    </div>
</template>
