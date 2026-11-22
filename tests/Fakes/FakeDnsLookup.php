<?php

namespace Tests\Fakes;

use App\Services\Domains\DnsLookup;

/**
 * DNS records for tests, keyed by record name. Nothing is looked up for real.
 */
class FakeDnsLookup extends DnsLookup
{
    /** @var array<string, list<string>> */
    public array $txtRecords = [];

    /** @var array<string, list<string>> */
    public array $cnameRecords = [];

    /** @var array<string, list<string>> */
    public array $aRecords = [];

    public function txt(string $name): array
    {
        return $this->txtRecords[$name] ?? [];
    }

    public function cname(string $name): array
    {
        return $this->cnameRecords[$name] ?? [];
    }

    public function a(string $name): array
    {
        return $this->aRecords[$name] ?? [];
    }
}
