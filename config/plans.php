<?php

/*
|--------------------------------------------------------------------------
| Plans and entitlements
|--------------------------------------------------------------------------
|
| Plans live in the database and are edited in the admin console. These are
| the starting plans `PlanSeeder` creates. When no plan exists at all (a
| fresh install or the test suite), nothing is limited.
|
| Limits: a number, or null for unlimited. Features: true/false.
| Prices are in minor units (cents); null means "contact us".
|
*/

return [

    'currency' => env('PLANS_CURRENCY', 'USD'),

    // Length of a manually assigned billing period, in months.
    'period_months' => 1,

    'unlimited' => [
        'properties' => null,
        'tours' => null,
        'scenes_per_tour' => null,
        'storage_mb' => null,
        'team_members' => null,
        'videos_per_month' => null,
        'monthly_credits' => 0,
        'video_studio' => true,
        'remove_branding' => true,
        'advanced_analytics' => true,
        'custom_domain' => true,
        'api_access' => true,
        'unlimited_credits' => false,
        'tracking_scripts' => true,
        'street_view' => true,
    ],

    'defaults' => [
        [
            'slug' => 'free',
            'name' => 'Free',
            'description' => 'Try the platform with one property.',
            'price_monthly' => 0,
            'is_default' => true,
            'entitlements' => [
                'properties' => 1, 'tours' => 1, 'scenes_per_tour' => 10, 'storage_mb' => 500,
                'team_members' => 1, 'videos_per_month' => 1, 'monthly_credits' => 10,
                'video_studio' => true, 'remove_branding' => false, 'advanced_analytics' => false,
                'custom_domain' => false, 'api_access' => false,
            ],
        ],
        [
            'slug' => 'starter',
            'name' => 'Starter',
            'description' => 'For individual agents.',
            'price_monthly' => 2900,
            'entitlements' => [
                'properties' => 10, 'tours' => 10, 'scenes_per_tour' => 30, 'storage_mb' => 5_000,
                'team_members' => 3, 'videos_per_month' => 10, 'monthly_credits' => 100,
                'video_studio' => true, 'remove_branding' => false, 'advanced_analytics' => false,
                'custom_domain' => false, 'api_access' => false,
            ],
        ],
        [
            'slug' => 'pro',
            'name' => 'Pro',
            'description' => 'For busy agencies.',
            'price_monthly' => 9900,
            'entitlements' => [
                'properties' => 100, 'tours' => 100, 'scenes_per_tour' => 80, 'storage_mb' => 50_000,
                'team_members' => 10, 'videos_per_month' => 50, 'monthly_credits' => 500,
                'video_studio' => true, 'remove_branding' => true, 'advanced_analytics' => true,
                'custom_domain' => true, 'api_access' => false, 'street_view' => true,
            ],
        ],
        [
            'slug' => 'business',
            'name' => 'Business',
            'description' => 'For developers and large teams. Custom terms.',
            'price_monthly' => null,
            'entitlements' => [
                'properties' => null, 'tours' => null, 'scenes_per_tour' => null, 'storage_mb' => null,
                'team_members' => null, 'videos_per_month' => null, 'monthly_credits' => 2000,
                'video_studio' => true, 'remove_branding' => true, 'advanced_analytics' => true,
                'custom_domain' => true, 'api_access' => true, 'tracking_scripts' => true, 'street_view' => true,
            ],
        ],
    ],

];
