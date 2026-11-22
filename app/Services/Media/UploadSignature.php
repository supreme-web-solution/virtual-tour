<?php

namespace App\Services\Media;

/**
 * Everything the browser needs to upload a file directly to storage. Contains
 * the public API key and a one-off signature, never the API secret.
 */
final readonly class UploadSignature
{
    /**
     * @param  array<string, string|int>  $fields  Form fields to send with the file.
     */
    public function __construct(
        public string $uploadUrl,
        public array $fields,
        public int $chunkSize,
    ) {}

    /**
     * @return array{upload_url: string, fields: array<string, string|int>, chunk_size: int}
     */
    public function toArray(): array
    {
        return [
            'upload_url' => $this->uploadUrl,
            'fields' => $this->fields,
            'chunk_size' => $this->chunkSize,
        ];
    }
}
