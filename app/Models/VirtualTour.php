<?php

namespace App\Models;

use App\Enums\TourStatus;
use App\Enums\TourVisibility;
use App\Models\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Database\Factories\VirtualTourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $property_id
 * @property int|null $created_by
 * @property int|null $cover_asset_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property TourStatus $status
 * @property TourVisibility $visibility
 * @property CarbonInterface|null $published_at
 * @property array<string, mixed>|null $branding_settings
 * @property array<string, mixed>|null $viewer_settings
 * @property array<string, mixed>|null $map_settings
 * @property array<string, mixed>|null $privacy_settings
 * @property array<string, mixed>|null $audio_settings
 * @property array<string, mixed>|null $lead_settings
 * @property array<string, mixed>|null $build_state One-click creation progress (TourBuilder)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'property_id', 'cover_asset_id', 'title', 'description', 'visibility',
    'branding_settings', 'viewer_settings', 'map_settings', 'privacy_settings',
    'audio_settings', 'lead_settings',
])]
class VirtualTour extends Model
{
    /** @use HasFactory<VirtualTourFactory> */
    use HasFactory, HasPublicUlid, SoftDeletes;

    /**
     * Default viewer behaviour, merged under the stored viewer_settings.
     *
     * @var array<string, mixed>
     */
    public const DEFAULT_VIEWER_SETTINGS = [
        'start_scene_id' => null,
        'show_scene_gallery' => true,
        'show_prev_next' => true,
        'auto_rotate' => false,
        'auto_rotate_speed' => 'normal',
        'transition' => 'fade',
        'layout' => 'carousel',
    ];

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_BRANDING = [
        'logo_asset_id' => null,
        'primary_color' => '#2563eb',
        'accent_color' => '#10b981',
        'show_platform_branding' => true,
        'intro' => ['enabled' => false, 'title' => null, 'text' => null, 'button_label' => 'Start tour'],
        'contact' => ['name' => null, 'email' => null, 'phone' => null, 'website' => null],
    ];

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_AUDIO = [
        'background_asset_id' => null,
        'volume' => 60,
        'loop' => true,
    ];

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_LEAD_SETTINGS = [
        'enabled' => false,
        'title' => 'Interested? Get in touch',
        'button_label' => 'Contact us',
        'require_phone' => false,
        'thank_you' => 'Thanks! We will be in touch shortly.',
        'notify' => true,
    ];

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_MAP = ['enabled' => false];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TourStatus::class,
            'visibility' => TourVisibility::class,
            'published_at' => 'datetime',
            'branding_settings' => 'array',
            'viewer_settings' => 'array',
            'map_settings' => 'array',
            'privacy_settings' => 'array',
            'audio_settings' => 'array',
            'lead_settings' => 'array',
            'build_state' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function branding(): array
    {
        return array_replace_recursive(self::DEFAULT_BRANDING, $this->branding_settings ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function audio(): array
    {
        return array_merge(self::DEFAULT_AUDIO, $this->audio_settings ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function leadSettings(): array
    {
        return array_merge(self::DEFAULT_LEAD_SETTINGS, $this->lead_settings ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSettings(): array
    {
        return array_merge(self::DEFAULT_MAP, $this->map_settings ?? []);
    }

    /**
     * Whether visitor analytics are collected for this tour.
     */
    public function analyticsEnabled(): bool
    {
        return (bool) ($this->privacy_settings['analytics_enabled'] ?? true);
    }

    /**
     * @return HasMany<TourFloorplan, $this>
     */
    public function floorplans(): HasMany
    {
        return $this->hasMany(TourFloorplan::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<TourLead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(TourLead::class);
    }

    /**
     * @return HasMany<TourVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(TourVisit::class);
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (VirtualTour $tour): void {
            $tour->public_id ??= static::newPublicId();
            $tour->slug = Str::slug($tour->title) ?: 'tour';
        });

        static::updating(function (VirtualTour $tour): void {
            if ($tour->isDirty('title')) {
                $tour->slug = Str::slug($tour->title) ?: 'tour';
            }
        });
    }

    /**
     * A random, non-sequential identifier used in public share links.
     */
    public static function newPublicId(): string
    {
        do {
            $id = Str::lower(Str::random(12));
        } while (static::withTrashed()->where('public_id', $id)->exists());

        return $id;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Who is emailed about enquiries: the tour's creator while they are still
     * in the workspace, otherwise the workspace owner.
     */
    public function leadRecipient(): ?User
    {
        $creator = $this->created_by ? User::find($this->created_by) : null;

        if ($creator && $creator->roleIn($this->workspace_id) !== null) {
            return $creator;
        }

        return $this->workspace->owner;
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_asset_id');
    }

    /**
     * @return HasMany<TourScene, $this>
     */
    public function scenes(): HasMany
    {
        return $this->hasMany(TourScene::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasManyThrough<TourHotspot, TourScene, $this>
     */
    public function hotspots(): HasManyThrough
    {
        return $this->hasManyThrough(TourHotspot::class, TourScene::class);
    }

    public function isPublished(): bool
    {
        return $this->status === TourStatus::Published;
    }

    /**
     * Viewer settings with defaults applied.
     *
     * @return array<string, mixed>
     */
    public function viewerSettings(): array
    {
        return array_merge(self::DEFAULT_VIEWER_SETTINGS, $this->viewer_settings ?? []);
    }

    /**
     * Domains allowed to embed the tour in an iframe (empty means any).
     *
     * @return list<string>
     */
    public function allowedEmbedDomains(): array
    {
        return array_values(array_filter((array) ($this->privacy_settings['allowed_embed_domains'] ?? [])));
    }
}
