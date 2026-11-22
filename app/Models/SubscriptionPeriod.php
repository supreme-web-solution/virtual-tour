<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One billing period of a subscription. Its monthly credits are granted once.
 *
 * @property int $id
 * @property int $subscription_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $credits_granted
 * @property Carbon|null $created_at
 */
#[Fillable(['starts_at', 'ends_at', 'credits_granted'])]
class SubscriptionPeriod extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'credits_granted' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
