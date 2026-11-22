<?php

namespace App\Models;

use App\Enums\Entitlement;
use App\Models\Concerns\HasPublicUlid;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int|null $price_monthly
 * @property string $currency
 * @property array<string, int|bool|null> $entitlements
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name', 'description', 'price_monthly', 'currency', 'entitlements', 'is_default', 'is_active', 'sort_order'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'price_monthly' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * A numeric limit (null = unlimited), or whether a feature is included.
     */
    public function entitlement(Entitlement $entitlement): int|bool|null
    {
        $value = $this->entitlements[$entitlement->value] ?? null;

        if ($entitlement->isFeature()) {
            return (bool) $value;
        }

        return $value === null ? ($entitlement === Entitlement::MonthlyCredits ? 0 : null) : (int) $value;
    }

    public function monthlyCredits(): int
    {
        return (int) $this->entitlement(Entitlement::MonthlyCredits);
    }
}
