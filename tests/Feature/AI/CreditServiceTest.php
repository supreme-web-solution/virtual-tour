<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\AiGeneration;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\GenerationLifecycle;
use App\Services\Billing\EntitlementService;
use App\Services\Credits\CreditService;

beforeEach(function () {
    config(['ai.operations.text_to_panorama.credits' => 5]);
    $this->credits = app(CreditService::class);
    $this->workspace = Workspace::factory()->create();
    $this->credits->grant($this->workspace->id, 12, 'Test grant');
});

function panoramaGeneration(Workspace $workspace): AiGeneration
{
    return AiGeneration::factory()->create(['workspace_id' => $workspace->id, 'generation_type' => GenerationType::TextToPanorama]);
}

test('reserving holds credits without spending them', function () {
    $generation = panoramaGeneration($this->workspace);

    $this->credits->reserve($generation);

    $wallet = $this->credits->wallet($this->workspace->id);
    expect($wallet->balance)->toBe(12)
        ->and($wallet->reserved)->toBe(5)
        ->and($wallet->available())->toBe(7)
        ->and($generation->fresh()->credits_reserved)->toBe(5);
});

test('reserving more than is available fails and holds nothing', function () {
    $generations = [panoramaGeneration($this->workspace), panoramaGeneration($this->workspace), panoramaGeneration($this->workspace)];

    expect(fn () => $this->credits->reserveFor($generations))->toThrow(InsufficientCreditsException::class);

    expect($this->credits->wallet($this->workspace->id)->reserved)->toBe(0)
        ->and(CreditTransaction::where('transaction_type', 'reserve')->count())->toBe(0);
});

test('charging spends the held credits exactly once', function () {
    $generation = panoramaGeneration($this->workspace);
    $this->credits->reserve($generation);

    $this->credits->charge($generation);
    $this->credits->charge($generation);
    $this->credits->release($generation, 'late failure');

    $wallet = $this->credits->wallet($this->workspace->id);
    expect($wallet->balance)->toBe(7)
        ->and($wallet->reserved)->toBe(0)
        ->and($generation->fresh()->credits_charged)->toBe(5)
        ->and(CreditTransaction::where('transaction_type', 'charge')->count())->toBe(1)
        ->and(CreditTransaction::where('transaction_type', 'release')->count())->toBe(0);
});

test('releasing returns held credits exactly once and blocks a later charge', function () {
    $generation = panoramaGeneration($this->workspace);
    $this->credits->reserve($generation);

    $this->credits->release($generation, 'failed');
    $this->credits->release($generation, 'failed again');
    $this->credits->charge($generation);

    $wallet = $this->credits->wallet($this->workspace->id);
    expect($wallet->balance)->toBe(12)
        ->and($wallet->reserved)->toBe(0)
        ->and($generation->fresh()->credits_charged)->toBe(0);
});

test('reserving the same generation twice holds credits once', function () {
    $generation = panoramaGeneration($this->workspace);

    $this->credits->reserve($generation);
    $this->credits->reserve($generation);

    expect($this->credits->wallet($this->workspace->id)->reserved)->toBe(5);
});

test('a plan with unlimited credits never holds or spends them', function () {
    $plan = Plan::factory()->with(['unlimited_credits' => true])->create();
    Subscription::factory()->create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();

    $generation = panoramaGeneration($this->workspace);
    $this->credits->reserve($generation);
    $this->credits->charge($generation);

    $wallet = $this->credits->wallet($this->workspace->id);
    expect($wallet->available())->toBe(CreditWallet::UNLIMITED)
        ->and($wallet->balance)->toBe(12)
        ->and($wallet->reserved)->toBe(0)
        ->and($generation->fresh()->credits_charged)->toBe(0);
});

test('a workspace with no credits can still generate on an unlimited plan', function () {
    $empty = Workspace::factory()->create();
    $plan = Plan::factory()->with(['unlimited_credits' => true])->create();
    Subscription::factory()->create(['workspace_id' => $empty->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();

    $this->credits->reserve(panoramaGeneration($empty));

    expect($this->credits->wallet($empty->id)->balance)->toBe(0);
});

test('a queued generation that never started is cancelled and its credits return', function () {
    $stale = panoramaGeneration($this->workspace);
    $this->credits->reserve($stale);
    $fresh = panoramaGeneration($this->workspace);
    $this->credits->reserve($fresh);

    $stale->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

    expect(app(GenerationLifecycle::class)->releaseStuck(60))->toBe(1)
        ->and($stale->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->fresh()->status)->not->toBe(GenerationStatus::Failed)
        ->and($this->credits->wallet($this->workspace->id)->reserved)->toBe(5);
});
