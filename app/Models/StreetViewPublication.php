<?php

namespace App\Models;

use Database\Factories\StreetViewPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tour scene published (or being published) to Google Street View.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $tour_scene_id
 * @property string $status pending|published|failed
 * @property string|null $photo_id
 * @property string|null $share_link
 * @property string|null $error
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TourScene $scene
 */
class StreetViewPublication extends Model
{
    /** @use HasFactory<StreetViewPublicationFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<TourScene, $this>
     */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(TourScene::class, 'tour_scene_id');
    }
}
