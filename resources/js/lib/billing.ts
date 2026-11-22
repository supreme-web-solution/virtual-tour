/**
 * Plan prices are stored in minor units (cents); null means "contact us".
 */
export function formatPlanPrice(
    minor: number | null,
    currency: string,
): string {
    if (minor === null) {
        return 'Contact us';
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            maximumFractionDigits: minor % 100 === 0 ? 0 : 2,
        }).format(minor / 100);
    } catch {
        return `${currency} ${(minor / 100).toFixed(2)}`;
    }
}

/**
 * A limit or feature value for display: "Unlimited", "5 GB", "Included"…
 */
export function formatEntitlement(
    key: string,
    value: number | boolean | null,
): string {
    if (typeof value === 'boolean') {
        return value ? 'Included' : '—';
    }

    if (value === null) {
        return 'Unlimited';
    }

    if (key === 'storage_mb') {
        return value >= 1000
            ? `${Number((value / 1000).toFixed(1))} GB`
            : `${value} MB`;
    }

    return value.toLocaleString();
}

/**
 * Share of a limit in use (0–100), or null when unlimited.
 */
export function usagePercent(
    used: number | null,
    limit: number | null,
): number | null {
    if (used === null || limit === null) {
        return null;
    }

    if (limit === 0) {
        return 100;
    }

    return Math.min(100, Math.round((used / limit) * 100));
}
