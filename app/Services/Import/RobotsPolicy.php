<?php

namespace App\Services\Import;

use App\Exceptions\ImportException;
use App\Support\TemporaryFiles;
use Illuminate\Support\Str;

/**
 * robots.txt rules for the importer: the group for our product token, or
 * the "*" group; the longest matching Allow/Disallow rule wins ("*" and "$"
 * wildcards supported). A missing robots.txt allows everything; one that
 * cannot be read because of a server error is treated as "disallow all".
 */
class RobotsPolicy
{
    /** @var array<string, list<array{allow: bool, pattern: string}>|null> */
    protected array $rules = [];

    public function __construct(protected SafeHttpClient $client) {}

    public function allows(string $url): bool
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $origin = strtolower($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        if (! array_key_exists($origin, $this->rules)) {
            $this->rules[$origin] = $this->load($origin);
        }

        $rules = $this->rules[$origin];

        if ($rules === null) {
            return false;
        }

        $best = null;

        foreach ($rules as $rule) {
            if ($rule['pattern'] === '' || ! $this->matches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            // Longest match wins; on a tie Allow wins.
            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best['allow'] ?? true;
    }

    /**
     * @return list<array{allow: bool, pattern: string}>|null null = disallow everything
     */
    protected function load(string $origin): ?array
    {
        try {
            $resource = $this->client->fetch($origin.'/robots.txt', ['text/*', 'application/octet-stream'], (int) config('import.max_robots_bytes'), 'text/plain');
        } catch (ImportException $exception) {
            // 4xx (no robots.txt) allows everything; anything else is treated as a refusal.
            $status = $exception->status;

            return $status !== null && $status >= 400 && $status < 500 && $status !== 429 ? [] : null;
        }

        try {
            return $this->parse($resource->body());
        } finally {
            TemporaryFiles::delete($resource->path);
        }
    }

    /**
     * @return list<array{allow: bool, pattern: string}>
     */
    public function parse(string $robots): array
    {
        $token = strtolower((string) config('import.robots_token', 'PropertyImporter'));
        $groups = [];
        $agents = [];
        $collectingAgents = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(Str::before($line, '#'));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $collectingAgents) {
                    $agents = [];
                }

                $agents[] = strtolower($value);
                $collectingAgents = true;

                continue;
            }

            if ($field === 'allow' || $field === 'disallow') {
                $collectingAgents = false;

                foreach ($agents as $agent) {
                    $groups[$agent][] = ['allow' => $field === 'allow', 'pattern' => $value];
                }
            }
        }

        foreach ($groups as $agent => $rules) {
            if ($agent !== '*' && str_contains($token, $agent)) {
                return $rules;
            }
        }

        return $groups['*'] ?? [];
    }

    protected function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '#'));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
