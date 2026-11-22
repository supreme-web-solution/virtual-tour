<?php

namespace App\Services\Import;

/**
 * A downloaded response body, stored in a temporary file.
 */
final readonly class FetchedResource
{
    public function __construct(
        public string $url,
        public string $contentType,
        public string $path,
        public int $bytes,
    ) {}

    public function body(): string
    {
        return (string) file_get_contents($this->path);
    }
}
