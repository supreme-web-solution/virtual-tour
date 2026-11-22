<?php

namespace App\Services\Panorama;

/**
 * Provider-neutral state of a panorama generation request.
 */
final readonly class PanoramaStatus
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * @param  array<string, mixed>  $raw  Provider response, kept for diagnostics.
     */
    public function __construct(
        public string $requestId,
        public string $state,
        public ?string $fileUrl = null,
        public ?string $errorMessage = null,
        public ?int $providerCredits = null,
        public ?string $model = null,
        public array $raw = [],
    ) {}

    public function isFinished(): bool
    {
        return in_array($this->state, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }
}
