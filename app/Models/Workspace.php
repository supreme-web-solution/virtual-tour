<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property int $owner_id
 * @property bool $is_personal
 * @property array<string, string|null>|null $branding
 * @property string|null $channel_slug Address of the public channel page, /c/{slug}
 * @property bool $channel_enabled
 * @property-read WorkspaceDomain|null $domain
 * @property-read Subscription|null $currentSubscription
 * @property-read User $owner
 * @property-read WorkspaceMember $membership Pivot, when loaded through a user
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'owner_id', 'is_personal'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'branding' => 'array',
            'channel_enabled' => 'boolean',
        ];
    }

    /**
     * Workspace brand defaults for public pages.
     *
     * @var array{display_name: string|null, logo_asset_id: string|null, primary_color: string|null, website: string|null}
     */
    public const DEFAULT_BRANDING = [
        'display_name' => null,
        'logo_asset_id' => null,
        'primary_color' => null,
        'website' => null,
        'ga4_id' => null,
        'gtm_id' => null,
    ];

    /**
     * @return array{display_name: string|null, logo_asset_id: string|null, primary_color: string|null, website: string|null, ga4_id: string|null, gtm_id: string|null}
     */
    public function brand(): array
    {
        /** @var array{display_name: string|null, logo_asset_id: string|null, primary_color: string|null, website: string|null, ga4_id: string|null, gtm_id: string|null} */
        return array_replace(self::DEFAULT_BRANDING, array_intersect_key($this->branding ?? [], self::DEFAULT_BRANDING));
    }

    /**
     * The name visitors see: the brand name, else the workspace name.
     */
    public function brandName(): string
    {
        return $this->brand()['display_name'] ?: $this->name;
    }

    /**
     * @return HasOne<WorkspaceDomain, $this>
     */
    public function domain(): HasOne
    {
        return $this->hasOne(WorkspaceDomain::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceMember, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->using(WorkspaceMember::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The newest subscription that still grants its plan.
     *
     * @return HasOne<Subscription, $this>
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany(['id' => 'max'], fn ($query) => $query->current());
    }

    /**
     * @return HasMany<VirtualTour, $this>
     */
    public function tours(): HasMany
    {
        return $this->hasMany(VirtualTour::class);
    }

    /**
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * Add a user to the workspace, or update their role if already a member.
     */
    public function addMember(User $user, WorkspaceRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    /**
     * The storage folder that holds this workspace's media.
     */
    public function storageFolder(): string
    {
        return trim((string) config('services.cloudinary.folder', 'virtualtour'), '/').'/'.strtolower($this->ulid);
    }
}
