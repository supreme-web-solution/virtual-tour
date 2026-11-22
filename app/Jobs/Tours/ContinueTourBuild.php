<?php

namespace App\Jobs\Tours;

use App\Models\AiGeneration;
use App\Services\Tours\TourBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Move a one-click tour build to its next step after its plan or photo
 * analysis finished (successfully or not).
 */
class ContinueTourBuild implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public AiGeneration $generation)
    {
        $this->onQueue('ai-analysis');
    }

    public function handle(TourBuilder $builder): void
    {
        $builder->generationFinished($this->generation->refresh());
    }
}
