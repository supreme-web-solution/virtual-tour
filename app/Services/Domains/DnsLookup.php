<?php

namespace App\Services\Domains;

/**
 * DNS record lookups for domain verification. Replaceable in tests.
 */
class DnsLookup
{
    /**
     * @return list<string>
     */
    public function txt(string $name): array
    {
        return array_values(array_filter(array_map(
            fn (array $record): string => (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? []))),
            @dns_get_record($name, DNS_TXT) ?: [],
        )));
    }

    /**
     * CNAME targets, lowercased and without the trailing dot.
     *
     * @return list<string>
     */
    public function cname(string $name): array
    {
        return array_values(array_filter(array_map(
            fn (array $record): string => rtrim(strtolower((string) ($record['target'] ?? '')), '.'),
            @dns_get_record($name, DNS_CNAME) ?: [],
        )));
    }

    /**
     * @return list<string>
     */
    public function a(string $name): array
    {
        return array_values(array_filter(array_map(
            fn (array $record): string => (string) ($record['ip'] ?? ''),
            @dns_get_record($name, DNS_A) ?: [],
        )));
    }
}
