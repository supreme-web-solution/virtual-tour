<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A team note on a property, tour or video. Only workspace members see it.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Property|VirtualTour|VideoProject|null $commentable
 */
#[Fillable(['body', 'edited_at'])]
class Comment extends Model
{
    use HasPublicUlid;

    /**
     * What can be commented on, by the short name used in requests.
     *
     * @var array<string, class-string<Model>>
     */
    public const SUBJECTS = [
        'property' => Property::class,
        'tour' => VirtualTour::class,
        'video' => VideoProject::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
