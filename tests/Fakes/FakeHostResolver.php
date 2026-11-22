<?php

namespace Tests\Fakes;

use App\Services\Import\HostResolver;

/**
 * DNS for tests: hosts resolve to public documentation-free addresses unless
 * told otherwise. No real lookups happen.
 */
class FakeHostResolver extends HostResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(public array $records = []) {}

    public function resolve(string $host): array
    {
        return $this->records[$host] ?? ['93.184.215.14'];
    }
}
