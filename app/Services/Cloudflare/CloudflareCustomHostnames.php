<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloudflare for SaaS "custom hostnames": the certificate and routing for a
 * customer's domain. Customers CNAME to the SaaS target, Cloudflare terminates
 * TLS and forwards the original Host to the fallback origin.
 *
 * @phpstan-type Hostname array{id: string, hostname: string, status: string, ssl_status: string|null, ownership_txt_name: string|null, ownership_txt_value: string|null, errors: list<string>}
 */
class CloudflareCustomHostnames
{
    public function configured(): bool
    {
        return filled(config('services.cloudflare.api_token')) && filled(config('services.cloudflare.zone_id'));
    }

    /**
     * Create the custom hostname, or reuse the existing one on a 409.
     *
     * @return Hostname
     */
    public function create(string $hostname): array
    {
        $payload = [
            'hostname' => strtolower($hostname),
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
                'settings' => [
                    'http2' => 'on',
                    'tls_1_3' => 'on',
                    'min_tls_version' => '1.2',
                ],
            ],
        ];

        // Paid SSL for SaaS only. Sending these on the Free plan fails with error 1456.
        $origin = (string) config('services.cloudflare.origin_hostname');

        if (config('services.cloudflare.custom_origin') && $origin !== '') {
            $payload['custom_origin_server'] = $origin;
            $payload['custom_origin_sni'] = $origin;
        }

        $response = $this->client()->post($this->path(), $payload);

        if ($response->status() === 409) {
            $existing = $this->findByHostname($hostname);

            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->normalise($this->result($response->throw()->json()));
    }

    /**
     * @return Hostname
     */
    public function get(string $id): array
    {
        return $this->normalise($this->result($this->client()->get($this->path($id))->throw()->json()));
    }

    /**
     * @return Hostname|null
     */
    public function findByHostname(string $hostname): ?array
    {
        $rows = $this->client()->get($this->path(), ['hostname' => strtolower($hostname)])->throw()->json('result', []);

        return $rows === [] ? null : $this->normalise($rows[0]);
    }

    /**
     * @return list<Hostname>
     */
    public function list(): array
    {
        $rows = $this->client()->get($this->path(), ['per_page' => 50, 'direction' => 'desc', 'order' => 'ssl'])->throw()->json('result', []);

        return array_values(array_map(fn (array $row): array => $this->normalise($row), $rows));
    }

    /**
     * Delete a custom hostname. A hostname that is already gone counts as deleted.
     */
    public function delete(string $id): void
    {
        $response = $this->client()->delete($this->path($id));

        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    protected function client(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Cloudflare is not configured.');
        }

        return Http::withToken((string) config('services.cloudflare.api_token'))
            ->acceptJson()
            ->timeout(15);
    }

    protected function path(?string $id = null): string
    {
        return 'https://api.cloudflare.com/client/v4/zones/'.config('services.cloudflare.zone_id').'/custom_hostnames'.($id ? '/'.$id : '');
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    protected function result(?array $json): array
    {
        return (array) ($json['result'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return Hostname
     */
    protected function normalise(array $row): array
    {
        $verification = (array) ($row['ownership_verification'] ?? []);
        $errors = array_values(array_filter(array_map('strval', (array) ($row['verification_errors'] ?? []))));

        foreach ((array) data_get($row, 'ssl.validation_errors', []) as $error) {
            if (is_array($error) && filled($error['message'] ?? null)) {
                $errors[] = (string) $error['message'];
            }
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'hostname' => (string) ($row['hostname'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'ssl_status' => isset($row['ssl']['status']) ? (string) $row['ssl']['status'] : null,
            'ownership_txt_name' => isset($verification['name']) ? (string) $verification['name'] : null,
            'ownership_txt_value' => isset($verification['value']) ? (string) $verification['value'] : null,
            'errors' => array_values(array_unique($errors)),
        ];
    }
}
