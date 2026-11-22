<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case Downloading = 'downloading';
    case Uploading = 'uploading';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Final states: nothing further will happen to the generation.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled, self::Refunded], true);
    }

    public function isActive(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isActive()),
        ));
    }
}
