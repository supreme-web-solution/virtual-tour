<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dashboard, login, logout, register } from '@/routes';
import invitations from '@/routes/invitations';

const props = defineProps<{
    token: string;
    invitation: {
        workspace: string;
        role: string;
        inviter: string | null;
        email: string;
    } | null;
    signedInAs: string | null;
    emailMatches: boolean;
}>();

defineOptions({
    layout: {
        title: 'Workspace invitation',
        description: '',
    },
});

const form = useForm({});

function accept(): void {
    form.submit(invitations.accept(props.token));
}
</script>

<template>
    <Head title="Workspace invitation" />

    <div class="space-y-6 text-center">
        <template v-if="!invitation">
            <p class="text-sm text-muted-foreground">
                This invitation is invalid, has expired or has already been
                used. Ask the person who invited you to send a new one.
            </p>
            <Button as-child variant="outline">
                <Link :href="signedInAs ? dashboard() : login()">
                    {{ signedInAs ? 'Go to dashboard' : 'Log in' }}
                </Link>
            </Button>
        </template>

        <template v-else>
            <p class="text-sm">
                <template v-if="invitation.inviter"
                    >{{ invitation.inviter }} invited you</template
                >
                <template v-else>You've been invited</template>
                to join <strong>{{ invitation.workspace }}</strong> as
                <strong>{{ invitation.role }}</strong
                >.
            </p>

            <template v-if="!signedInAs">
                <p class="text-sm text-muted-foreground">
                    Log in or create an account with
                    <strong>{{ invitation.email }}</strong
                    >, then open this link again.
                </p>
                <div class="flex justify-center gap-2">
                    <Button as-child>
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child variant="outline">
                        <Link :href="register()">Create account</Link>
                    </Button>
                </div>
            </template>

            <template v-else-if="emailMatches">
                <Button
                    :disabled="form.processing"
                    class="w-full"
                    @click="accept"
                >
                    Join {{ invitation.workspace }}
                </Button>
            </template>

            <template v-else>
                <p class="text-sm text-muted-foreground">
                    You're signed in as <strong>{{ signedInAs }}</strong
                    >, but this invitation is for
                    <strong>{{ invitation.email }}</strong
                    >. Sign out and log in with that email to accept it.
                </p>
                <Button as-child variant="outline">
                    <Link :href="logout()" method="post" as="button"
                        >Sign out</Link
                    >
                </Button>
            </template>
        </template>
    </div>
</template>
