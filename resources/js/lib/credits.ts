/**
 * The server reports this many available credits when a plan includes
 * unlimited AI credits (see CreditWallet::UNLIMITED).
 */
const UNLIMITED_FROM = 1_000_000;

export function isUnlimitedCredits(credits: number): boolean {
    return credits >= UNLIMITED_FROM;
}

export function formatCredits(credits: number): string {
    return isUnlimitedCredits(credits) ? 'Unlimited' : credits.toLocaleString();
}
