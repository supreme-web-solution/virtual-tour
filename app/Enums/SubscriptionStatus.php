<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    /**
     * Whether a subscription in this status still grants its plan.
     */
    public function isCurrent(): bool
    {
        return $this !== self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Trialing => 'Trial',
            self::PastDue => 'Past due',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return list<string>
     */
    public static function currentValues(): array
    {
        return [self::Active->value, self::Trialing->value, self::PastDue->value];
    }
}
