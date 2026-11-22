/**
 * A small, calm palette for icon chips and avatars. Each entry is a solid
 * tint with a readable icon colour, in light and dark.
 */
export const accents = {
    sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
    violet: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
    emerald:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    indigo: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
} as const;

export type Accent = keyof typeof accents;

const order = Object.keys(accents) as Accent[];

/**
 * A stable accent for a string (a name or email), so the same person
 * always gets the same colour.
 */
export function accentFor(seed: string): Accent {
    let hash = 0;

    for (const char of seed) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return order[hash % order.length];
}
