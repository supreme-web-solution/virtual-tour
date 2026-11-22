export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
}

export function formatDate(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(iso));
}

const relativeFormatter = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
});

export function timeAgo(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return relativeFormatter.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

export function formatPrice(
    amount: string | null,
    currency: string | null,
): string | null {
    if (amount === null) {
        return null;
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: currency ? 'currency' : 'decimal',
            currency: currency ?? undefined,
            maximumFractionDigits: 0,
        }).format(Number(amount));
    } catch {
        return `${currency ?? ''} ${amount}`.trim();
    }
}
