<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionService;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;

class CreatePersonalWorkspace
{
    public function __construct(
        protected CreditService $credits,
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * Create the user's personal workspace, make them its owner and select it.
     */
    public function handle(User $user): Workspace
    {
        return DB::transaction(function () use ($user): Workspace {
            $firstName = trim(strtok($user->name, ' ') ?: $user->name);

            $workspace = Workspace::create([
                'name' => "{$firstName}'s Workspace",
                'owner_id' => $user->id,
                'is_personal' => true,
            ]);

            $workspace->addMember($user, WorkspaceRole::Owner);
            $user->flushWorkspaceRoles();
            $user->switchWorkspace($workspace);

            $starter = (int) config('ai.credits.starter_balance', 0);

            if ($starter > 0) {
                $this->credits->grant($workspace->id, $starter, 'Starter credits');
            }

            $this->subscriptions->startDefault($workspace);

            return $workspace;
        });
    }
}
