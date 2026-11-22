<?php

namespace App\Services\Import;

/**
 * DNS lookups for the import client. A separate class so tests can replace it.
 */
class HostResolver
{
    /**
     * Every IPv4 and IPv6 address the host resolves to.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
