<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Database\Factories\AiGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single request to an AI provider, tracked from submission to stored output.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int|null $user_id
 * @property int|null $property_id
 * @property int|null $virtual_tour_id
 * @property int|null $video_project_id
 * @property int|null $parent_id
 * @property GenerationType $generation_type
 * @property string $provider
 * @property string|null $model
 * @property string|null $provider_request_id
 * @property GenerationStatus $status
 * @property array<string, mixed>|null $input_payload
 * @property array<string, mixed>|null $output_metadata
 * @property string|float|null $estimated_cost
 * @property string|float|null $actual_cost
 * @property int $credits_reserved
 * @property int $credits_charged
 * @property string|null $error_message
 * @property int $poll_attempts
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id', 'property_id', 'virtual_tour_id', 'video_project_id', 'parent_id', 'generation_type', 'provider', 'model',
    'provider_request_id', 'status', 'input_payload', 'output_metadata', 'estimated_cost', 'actual_cost',
    'error_message', 'started_at', 'completed_at',
])]
class AiGeneration extends Model
{
    /** @use HasFactory<AiGenerationFactory> */
    use HasFactory, HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generation_type' => GenerationType::class,
            'status' => GenerationStatus::class,
            'input_payload' => 'array',
            'output_metadata' => 'array',
            'estimated_cost' => 'decimal:4',
            'actual_cost' => 'decimal:4',
            'credits_reserved' => 'integer',
            'credits_charged' => 'integer',
            'poll_attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @return BelongsTo<VideoProject, $this>
     */
    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    /**
     * The plan or analysis this generation was created from.
     *
     * @return BelongsTo<AiGeneration, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'parent_id');
    }

    /**
     * @return HasMany<AiGeneration, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(AiGeneration::class, 'parent_id');
    }

    /**
     * @return HasMany<AiGenerationOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(AiGenerationOutput::class);
    }

    /**
     * Read a value from the input payload.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->input_payload, $key, $default);
    }

    /**
     * Merge values into output_metadata without losing existing keys.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeOutput(array $values): void
    {
        $this->output_metadata = array_replace_recursive($this->output_metadata ?? [], $values);
    }
}
