<?php

namespace App\Models;

use App\Enums\SoundtrackMode;
use App\Enums\TourVisibility;
use App\Enums\VideoStatus;
use App\Enums\VideoStyle;
use App\Models\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Database\Factories\VideoProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $property_id
 * @property int|null $virtual_tour_id
 * @property int|null $created_by
 * @property string $title
 * @property string $slug
 * @property VideoStyle $style
 * @property string $aspect_ratio 16:9|9:16|1:1
 * @property int $requested_duration Seconds
 * @property string $resolution
 * @property string $provider
 * @property string|null $model
 * @property array<string, mixed>|null $storyboard
 * @property SoundtrackMode $soundtrack_mode
 * @property int|null $soundtrack_asset_id
 * @property int|null $voiceover_asset_id
 * @property VideoStatus $status
 * @property int $progress
 * @property int|null $final_asset_id
 * @property int|null $poster_asset_id
 * @property array<string, mixed>|null $settings
 * @property TourVisibility $visibility
 * @property CarbonInterface|null $published_at
 * @property int $views_count
 * @property int $plays_count
 * @property int $completions_count
 * @property int $downloads_count
 * @property int $embed_views_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'property_id', 'virtual_tour_id', 'title', 'style', 'aspect_ratio', 'requested_duration', 'resolution',
    'provider', 'model', 'storyboard', 'soundtrack_mode', 'soundtrack_asset_id', 'voiceover_asset_id',
    'status', 'progress', 'final_asset_id', 'poster_asset_id', 'settings', 'visibility', 'published_at',
])]
class VideoProject extends Model
{
    /** @use HasFactory<VideoProjectFactory> */
    use HasFactory, HasPublicUlid, SoftDeletes;

    public const ASPECT_RATIOS = ['16:9', '9:16', '1:1'];

    public const DURATIONS = [5, 10, 15, 20, 30];

    /**
     * Output frame size per aspect ratio (720p class).
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const FRAME_SIZES = [
        '16:9' => [1280, 720],
        '9:16' => [720, 1280],
        '1:1' => [1080, 1080],
    ];

    /**
     * @var array<string, mixed>
     */
    public const DEFAULT_SETTINGS = [
        'music_mood' => 'cinematic',
        'ambience' => 'soft_room',
        'music_volume' => 70,
        'voiceover_volume' => 100,
        'audio_trim_start' => 0,
        'audio_fade_in' => 1,
        'audio_fade_out' => 2,
        'branded_outro' => true,
        'brief' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'style' => VideoStyle::class,
            'status' => VideoStatus::class,
            'soundtrack_mode' => SoundtrackMode::class,
            'visibility' => TourVisibility::class,
            'requested_duration' => 'integer',
            'progress' => 'integer',
            'storyboard' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (VideoProject $project): void {
            $project->public_id ??= static::newPublicId();
            $project->slug = Str::slug($project->title) ?: 'video';
        });

        static::updating(function (VideoProject $project): void {
            if ($project->isDirty('title')) {
                $project->slug = Str::slug($project->title) ?: 'video';
            }
        });
    }

    public static function newPublicId(): string
    {
        do {
            $id = Str::lower(Str::random(12));
        } while (static::withTrashed()->where('public_id', $id)->exists());

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function frameSize(): array
    {
        return self::FRAME_SIZES[$this->aspect_ratio] ?? self::FRAME_SIZES['16:9'];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->final_asset_id !== null;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<VirtualTour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(VirtualTour::class, 'virtual_tour_id');
    }

    /**
     * @return HasMany<VideoShot, $this>
     */
    public function shots(): HasMany
    {
        return $this->hasMany(VideoShot::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<VideoExport, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(VideoExport::class);
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function soundtrack(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'soundtrack_asset_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function voiceover(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'voiceover_asset_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function finalAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'final_asset_id');
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function posterAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'poster_asset_id');
    }

    /**
     * @return HasMany<AiGeneration, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }
}
