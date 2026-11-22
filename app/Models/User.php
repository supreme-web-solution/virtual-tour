<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $suspended_at Suspended accounts cannot sign in
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_workspace_id
 * @property bool $is_admin Platform administrator (admin console access)
 * @property-read Workspace|null $currentWorkspace
 * @property-read WorkspaceMember $membership Pivot, when loaded through a workspace
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Memoised workspace roles keyed by workspace id.
     *
     * @var array<int, WorkspaceRole>|null
     */
    protected ?array $workspaceRoles = null;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Platform administrator: flagged in the database, or listed in the
     * ADMIN_EMAILS setting (verified accounts only, so nobody can claim a
     * listed address by registering it first).
     *
     * @return Attribute<bool, never>
     */
    protected function isAdmin(): Attribute
    {
        return Attribute::get(fn (mixed $value): bool => (bool) $value || $this->isListedAdmin());
    }

    /**
     * Whether this account is an administrator through ADMIN_EMAILS.
     */
    public function isListedAdmin(): bool
    {
        $listed = array_filter(array_map(
            fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) config('app.admin_emails')),
        ));

        return in_array(strtolower($this->email), $listed, true);
    }

    /**
     * @return BelongsToMany<Workspace, $this, WorkspaceMember, 'membership'>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->using(WorkspaceMember::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * The user's role in the given workspace, or null when not a member.
     */
    public function roleIn(Workspace|int|null $workspace): ?WorkspaceRole
    {
        if ($workspace === null) {
            return null;
        }

        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        $this->workspaceRoles ??= $this->workspaces()
            ->pluck('workspace_members.role', 'workspaces.id')
            ->map(fn (string $role): WorkspaceRole => WorkspaceRole::from($role))
            ->all();

        return $this->workspaceRoles[$workspaceId] ?? null;
    }

    /**
     * Forget cached workspace roles (after membership changes).
     */
    public function flushWorkspaceRoles(): void
    {
        $this->workspaceRoles = null;
    }

    /**
     * Switch the active workspace. The user must already be a member.
     */
    public function switchWorkspace(Workspace $workspace): void
    {
        $this->forceFill(['current_workspace_id' => $workspace->id])->save();
        $this->setRelation('currentWorkspace', $workspace);
    }
}
