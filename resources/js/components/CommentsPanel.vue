<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { MessageSquare, Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { timeAgo } from '@/lib/format';
import {
    destroy as destroyComment,
    store as storeComment,
    update as updateComment,
} from '@/routes/comments';
import type { TeamComments } from '@/types';

const props = defineProps<{
    comments: TeamComments;
}>();

const reload = { preserveScroll: true, only: ['comments'] };
const form = useForm({ body: '' });
const editingId = ref<string | null>(null);
const editBody = ref('');

function add(): void {
    form.transform((data) => ({
        ...data,
        subject_type: props.comments.subject.type,
        subject_id: props.comments.subject.id,
    })).submit(storeComment(), {
        ...reload,
        onSuccess: () => form.reset(),
    });
}

function startEdit(id: string, body: string): void {
    editingId.value = id;
    editBody.value = body;
}

function saveEdit(id: string): void {
    router.patch(
        updateComment(id).url,
        { body: editBody.value },
        {
            ...reload,
            onSuccess: () => (editingId.value = null),
        },
    );
}

function remove(id: string): void {
    if (confirm('Delete this note?')) {
        router.delete(destroyComment(id).url, reload);
    }
}
</script>

<template>
    <section class="space-y-3 rounded-lg border p-4">
        <h2 class="flex items-center gap-2 text-sm font-medium">
            <MessageSquare class="size-4" /> Team notes
            <span
                v-if="comments.items.length"
                class="text-xs font-normal text-muted-foreground"
                >{{ comments.items.length }}</span
            >
        </h2>
        <p class="text-xs text-muted-foreground">
            Only people in this workspace can see these.
        </p>

        <ul v-if="comments.items.length" class="space-y-3">
            <li
                v-for="comment in comments.items"
                :key="comment.id"
                class="group space-y-1 text-sm"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="font-medium">{{ comment.author }}</span>
                    <span class="text-xs text-muted-foreground"
                        >{{ timeAgo(comment.created_at)
                        }}<template v-if="comment.edited">
                            · edited</template
                        ></span
                    >
                </div>
                <template v-if="editingId === comment.id">
                    <Textarea v-model="editBody" rows="3" maxlength="5000" />
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            :disabled="!editBody.trim()"
                            @click="saveEdit(comment.id)"
                            >Save</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="editingId = null"
                            >Cancel</Button
                        >
                    </div>
                </template>
                <template v-else>
                    <p class="break-words whitespace-pre-line">
                        {{ comment.body }}
                    </p>
                    <div
                        v-if="comment.is_mine || comment.can_delete"
                        class="flex gap-1 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
                    >
                        <button
                            v-if="comment.is_mine"
                            type="button"
                            class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            @click="startEdit(comment.id, comment.body)"
                        >
                            <Pencil class="size-3" /> Edit
                        </button>
                        <button
                            v-if="comment.can_delete"
                            type="button"
                            class="flex items-center gap-1 text-xs text-muted-foreground hover:text-destructive"
                            @click="remove(comment.id)"
                        >
                            <Trash2 class="size-3" /> Delete
                        </button>
                    </div>
                </template>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">No notes yet.</p>

        <form
            v-if="comments.can_comment"
            class="space-y-2 border-t pt-3"
            @submit.prevent="add"
        >
            <Textarea
                v-model="form.body"
                rows="2"
                maxlength="5000"
                placeholder="Add a note for your team"
                aria-label="New note"
            />
            <InputError :message="form.errors.body" />
            <Button
                type="submit"
                size="sm"
                :disabled="form.processing || !form.body.trim()"
                >Add note</Button
            >
        </form>
    </section>
</template>
