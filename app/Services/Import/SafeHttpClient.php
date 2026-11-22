<?php

namespace App\Services\Import;

use App\Exceptions\ImportException;
use App\Support\TemporaryFiles;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Fetches user-supplied URLs without letting them reach internal systems
 * (server-side request forgery):
 *
 * - http/https on ports 80 and 443 only, no credentials in the URL;
 * - every address the host resolves to must be public, and the connection
 *   is pinned to the checked address so DNS cannot change in between;
 * - redirects are followed by hand and each hop is checked again;
 * - connect/total timeouts, a byte limit while downloading, and an
 *   allow-list of content types.
 */
class SafeHttpClient
{
    /**
     * Ranges that are not public internet addresses, beyond what PHP's
     * private/reserved filters already reject.
     *
     * @var list<string>
     */
    public const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(protected HostResolver $resolver) {}

    /**
     * Check a URL and return where it may connect to.
     *
     * @return array{url: string, host: string, port: int, ip: string}
     *
     * @throws ImportException
     */
    public function inspect(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ImportException(__('Enter a full web address starting with https://'));
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ImportException(__('Only http and https addresses can be imported.'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImportException(__('Addresses containing a username or password cannot be imported.'));
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, [80, 443], true)) {
            throw new ImportException(__('Only standard web ports (80 and 443) are allowed.'));
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === 'localhost' || Str::endsWith($host, ['.localhost', '.local', '.internal', '.lan', '.home.arpa'])) {
            throw new ImportException(__('That address points to a private network and cannot be imported.'));
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new ImportException(__('That website could not be found. Check the address.'));
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                throw new ImportException(__('That address points to a private network and cannot be imported.'));
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return ! IpUtils::checkIp($ip, self::BLOCKED_RANGES);
    }

    /**
     * Download a URL to a temporary file.
     *
     * @param  list<string>  $allowedTypes  Content types (or "type/*" prefixes) that are accepted.
     *
     * @throws ImportException
     */
    public function fetch(string $url, array $allowedTypes, int $maxBytes, string $accept = '*/*'): FetchedResource
    {
        $maxRedirects = (int) config('import.max_redirects', 5);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $target = $this->inspect($url);
            $path = TemporaryFiles::path('download');

            try {
                $response = $this->request($target, $path, $maxBytes, $accept);
            } catch (ImportException $exception) {
                TemporaryFiles::delete($path);

                throw $exception;
            }

            if ($response->redirect()) {
                TemporaryFiles::delete($path);
                $location = $response->header('Location');

                if ($location === '') {
                    throw new ImportException(__('The website sent an invalid redirect.'));
                }

                $url = self::absoluteUrl($location, $url) ?? throw new ImportException(__('The website sent an invalid redirect.'));

                continue;
            }

            return $this->accept($response, $url, $path, $allowedTypes, $maxBytes);
        }

        throw new ImportException(__('The website redirected too many times.'));
    }

    /**
     * @param  array{url: string, host: string, port: int, ip: string}  $target
     */
    protected function request(array $target, string $path, int $maxBytes, string $accept): Response
    {
        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];
        $tooLarge = fn (): ImportException => new ImportException(__('The file is larger than the :size MB limit.', ['size' => round($maxBytes / 1024 / 1024)]));

        try {
            return Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => (int) config('import.connect_timeout', 5),
                'timeout' => (int) config('import.timeout', 20),
                'sink' => $path,
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes, $tooLarge): void {
                    $length = $response->getHeaderLine('Content-Length');

                    if ($length !== '' && (int) $length > $maxBytes) {
                        throw $tooLarge();
                    }
                },
                'progress' => function (mixed $total, mixed $downloaded) use ($maxBytes, $tooLarge): void {
                    if ((int) $downloaded > $maxBytes) {
                        throw $tooLarge();
                    }
                },
            ])->withHeaders([
                'User-Agent' => (string) config('import.user_agent'),
                'Accept' => $accept,
            ])->get($target['url']);
        } catch (Throwable $exception) {
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof ImportException) {
                    throw $cause;
                }
            }

            throw new ImportException(__('The website could not be reached or took too long to respond.'));
        }
    }

    /**
     * @param  list<string>  $allowedTypes
     */
    protected function accept(Response $response, string $url, string $path, array $allowedTypes, int $maxBytes): FetchedResource
    {
        $status = $response->status();

        if ($status !== 200) {
            TemporaryFiles::delete($path);

            throw new ImportException(match (true) {
                in_array($status, [401, 403, 429, 451], true) => __('This website does not allow automated access (HTTP :status). Please upload the photos yourself instead.', ['status' => $status]),
                $status === 404 || $status === 410 => __('That page was not found (HTTP :status).', ['status' => $status]),
                default => __('The website returned an error (HTTP :status).', ['status' => $status]),
            }, $status);
        }

        $bytes = is_file($path) ? (int) filesize($path) : 0;

        if ($bytes > $maxBytes) {
            TemporaryFiles::delete($path);

            throw new ImportException(__('The file is larger than the :size MB limit.', ['size' => round($maxBytes / 1024 / 1024)]));
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $allowed = collect($allowedTypes)->contains(fn (string $type): bool => Str::endsWith($type, '/*')
            ? Str::startsWith($contentType, Str::beforeLast($type, '*'))
            : $contentType === $type);

        if (! $allowed) {
            TemporaryFiles::delete($path);

            throw new ImportException(__('Unexpected content type ":type".', ['type' => $contentType ?: 'unknown']));
        }

        return new FetchedResource($url, $contentType, $path, $bytes);
    }

    /**
     * Resolve a possibly relative URL against a base URL. Returns null for
     * anything that is not an http(s) URL.
     */
    public static function absoluteUrl(string $reference, string $base): ?string
    {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5));

        if ($reference === '' || Str::startsWith(strtolower($reference), ['data:', 'javascript:', 'mailto:', 'tel:', 'blob:'])) {
            return null;
        }

        if (preg_match('#^https?://#i', $reference)) {
            return $reference;
        }

        $baseParts = parse_url($base);

        if (! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (Str::startsWith($reference, '//')) {
            return $baseParts['scheme'].':'.$reference;
        }

        if (Str::startsWith($reference, '/')) {
            return $origin.$reference;
        }

        $directory = Str::beforeLast($baseParts['path'] ?? '/', '/');

        if (Str::startsWith($reference, ['?', '#'])) {
            return $origin.($baseParts['path'] ?? '/').$reference;
        }

        // Normalise "./" and "../" segments.
        $segments = [];

        foreach (explode('/', trim($directory.'/'.$reference, '/')) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return $origin.'/'.implode('/', $segments);
    }
}
