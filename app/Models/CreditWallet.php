<?php

namespace App\Models;

use App\Services\Billing\EntitlementService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $balance
 * @property int $reserved
 */
#[Fillable(['workspace_id'])]
class CreditWallet extends Model
{
    /**
     * The model's default values for attributes.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'balance' => 0,
        'reserved' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'reserved' => 'integer',
        ];
    }

    /**
     * What "available" reports when the plan includes unlimited credits.
     */
    public const UNLIMITED = 1_000_000_000;

    public function hasUnlimitedCredits(): bool
    {
        return app(EntitlementService::class)->hasUnlimitedCredits($this->workspace_id);
    }

    /**
     * Credits that can be spent right now.
     */
    public function available(): int
    {
        if ($this->hasUnlimitedCredits()) {
            return self::UNLIMITED;
        }

        return max(0, $this->balance - $this->reserved);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
