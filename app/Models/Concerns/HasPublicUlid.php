<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Keeps an auto-increment primary key for internal relations while exposing a
 * separate, non-sequential `ulid` column in URLs and API payloads.
 */
trait HasPublicUlid
{
    use HasUlids;

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
