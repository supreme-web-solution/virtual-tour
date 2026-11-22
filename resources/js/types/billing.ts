export type EntitlementSummary = {
    plan: {
        id: string | null;
        name: string;
        price_monthly: number | null;
        currency: string;
    };
    limits: {
        key: string;
        label: string;
        limit: number | null;
        used: number | null;
        unit: string | null;
    }[];
    features: { key: string; label: string; included: boolean }[];
};

export type EntitlementDefinition = {
    key: string;
    label: string;
    feature: boolean;
};

export type AdminPlan = {
    id: string;
    slug: string;
    name: string;
    description: string | null;
    price_monthly: number | null;
    currency: string;
    entitlements: Record<string, number | boolean | null>;
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
    workspaces?: number;
};

export type CreditTransactionItem = {
    id: number;
    type: string;
    amount: number;
    balance_after?: number;
    description: string;
    user?: string | null;
    workspace?: { id: string; name: string } | null;
    created_at: string | null;
};

export type SubscriptionItem = {
    id: string;
    plan: string;
    status: string;
    status_label: string;
    provider: string;
    current_period_start: string | null;
    current_period_end: string | null;
    auto_renew: boolean;
    cancelled_at: string | null;
    assigned_by: string | null;
    notes: string | null;
    created_at: string | null;
};
