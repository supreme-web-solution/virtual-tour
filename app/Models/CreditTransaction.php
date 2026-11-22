<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $transaction_type grant|reserve|charge|release|adjustment
 * @property int $amount Signed change to the wallet balance (reserve/release record the held amount).
 * @property int $balance_before
 * @property int $balance_after
 * @property int $reserved_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $description
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $created_at
 * @property-read Workspace $workspace
 * @property-read User|null $user
 */
#[Fillable([
    'workspace_id', 'user_id', 'transaction_type', 'amount', 'balance_before', 'balance_after',
    'reserved_after', 'reference_type', 'reference_id', 'description', 'metadata',
])]
class CreditTransaction extends Model
{
    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
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
}
