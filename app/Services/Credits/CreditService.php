<?php

namespace App\Services\Credits;

use App\Exceptions\InsufficientCreditsException;
use App\Models\AiGeneration;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Workspace credits.
 *
 * Every paid generation follows reserve → charge (on success) or
 * reserve → release (on failure/cancel). Each step is recorded once per
 * generation: the wallet row is locked for the duration of the change and a
 * step that already happened is skipped, so concurrent requests, job retries
 * and duplicate webhooks can never move credits twice.
 */
class CreditService
{
    public function wallet(int $workspaceId): CreditWallet
    {
        return CreditWallet::firstOrCreate(['workspace_id' => $workspaceId]);
    }

    public function available(int $workspaceId): int
    {
        return $this->wallet($workspaceId)->available();
    }

    /**
     * Add credits (purchases, plan allowances, starter balance, admin grants).
     * With a reference (for example a billing period) the grant happens once.
     */
    public function grant(int $workspaceId, int $amount, string $description, ?User $user = null, ?Model $reference = null): CreditTransaction
    {
        return DB::transaction(function () use ($workspaceId, $amount, $description, $user, $reference): CreditTransaction {
            $wallet = $this->lockedWallet($workspaceId);

            if ($reference !== null) {
                $existing = CreditTransaction::where('reference_type', $reference->getMorphClass())
                    ->where('reference_id', $reference->getKey())
                    ->where('transaction_type', 'grant')
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $before = $wallet->balance;
            $wallet->balance += $amount;
            $wallet->save();

            return $this->record($wallet, 'grant', $amount, $before, $reference, $description, $user);
        });
    }

    /**
     * Hold credits for several generations at once: either all are reserved
     * or none are (for example, every panorama in a scene plan).
     *
     * @param  list<AiGeneration>  $generations
     *
     * @throws InsufficientCreditsException
     */
    public function reserveFor(array $generations): void
    {
        if ($generations === []) {
            return;
        }

        DB::transaction(function () use ($generations): void {
            $wallet = $this->lockedWallet($generations[0]->workspace_id);

            $pending = array_values(array_filter(
                $generations,
                fn (AiGeneration $generation): bool => ! $this->hasStep($generation, 'reserve'),
            ));
            $unlimited = $wallet->hasUnlimitedCredits();
            $total = array_sum(array_map(fn (AiGeneration $generation): int => $generation->generation_type->credits($generation->input_payload), $pending));

            if ($total > $wallet->available()) {
                throw new InsufficientCreditsException($total, $wallet->available());
            }

            foreach ($pending as $generation) {
                // Unlimited plans are recorded but never hold or spend credits.
                $amount = $unlimited ? 0 : $generation->generation_type->credits($generation->input_payload);
                $wallet->reserved += $amount;
                $generation->forceFill(['credits_reserved' => $amount])->save();

                $this->record($wallet, 'reserve', $amount, $wallet->balance, $generation, "Reserved for {$generation->generation_type->label()}", $generation->user);
            }

            $wallet->save();
        });
    }

    /**
     * @throws InsufficientCreditsException
     */
    public function reserve(AiGeneration $generation): void
    {
        $this->reserveFor([$generation]);
    }

    /**
     * Convert a reservation into a charge once the output is safely stored.
     */
    public function charge(AiGeneration $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $wallet = $this->lockedWallet($generation->workspace_id);

            if (! $this->hasStep($generation, 'reserve') || $this->hasStep($generation, 'charge') || $this->hasStep($generation, 'release')) {
                return;
            }

            $amount = $generation->credits_reserved;
            $before = $wallet->balance;
            $wallet->balance = max(0, $wallet->balance - $amount);
            $wallet->reserved = max(0, $wallet->reserved - $amount);
            $wallet->save();

            $generation->forceFill(['credits_charged' => $amount])->save();

            $this->record($wallet, 'charge', -$amount, $before, $generation, "Charged for {$generation->generation_type->label()}", $generation->user);
        });
    }

    /**
     * Return held credits for a generation that failed or was cancelled.
     */
    public function release(AiGeneration $generation, string $reason): void
    {
        DB::transaction(function () use ($generation, $reason): void {
            $wallet = $this->lockedWallet($generation->workspace_id);

            if (! $this->hasStep($generation, 'reserve') || $this->hasStep($generation, 'charge') || $this->hasStep($generation, 'release')) {
                return;
            }

            $amount = $generation->credits_reserved;
            $wallet->reserved = max(0, $wallet->reserved - $amount);
            $wallet->save();

            $this->record($wallet, 'release', $amount, $wallet->balance, $generation, "Released: {$reason}", $generation->user);
        });
    }

    protected function lockedWallet(int $workspaceId): CreditWallet
    {
        CreditWallet::firstOrCreate(['workspace_id' => $workspaceId]);

        return CreditWallet::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
    }

    protected function hasStep(AiGeneration $generation, string $type): bool
    {
        return CreditTransaction::where('reference_type', $generation->getMorphClass())
            ->where('reference_id', $generation->id)
            ->where('transaction_type', $type)
            ->exists();
    }

    protected function record(
        CreditWallet $wallet,
        string $type,
        int $amount,
        int $balanceBefore,
        ?Model $reference,
        string $description,
        ?User $user,
    ): CreditTransaction {
        return CreditTransaction::create([
            'workspace_id' => $wallet->workspace_id,
            'user_id' => $user?->id,
            'transaction_type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $wallet->balance,
            'reserved_after' => $wallet->reserved,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $description,
        ]);
    }
}
