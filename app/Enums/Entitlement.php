<?php

namespace App\Enums;

/**
 * Everything a plan can limit or unlock. Limits are counts (null means
 * unlimited); features are on/off switches.
 */
enum Entitlement: string
{
    case Properties = 'properties';
    case Tours = 'tours';
    case ScenesPerTour = 'scenes_per_tour';
    case StorageMb = 'storage_mb';
    case TeamMembers = 'team_members';
    case VideosPerMonth = 'videos_per_month';
    case MonthlyCredits = 'monthly_credits';
    case VideoStudio = 'video_studio';
    case RemoveBranding = 'remove_branding';
    case AdvancedAnalytics = 'advanced_analytics';
    case CustomDomain = 'custom_domain';
    case ApiAccess = 'api_access';
    case UnlimitedCredits = 'unlimited_credits';
    case TrackingScripts = 'tracking_scripts';
    case StreetView = 'street_view';

    public function isFeature(): bool
    {
        return in_array($this, [self::VideoStudio, self::RemoveBranding, self::AdvancedAnalytics, self::CustomDomain, self::ApiAccess, self::UnlimitedCredits, self::TrackingScripts, self::StreetView], true);
    }

    /**
     * Limits that are measured against current usage (monthly credits are a grant, not a cap).
     */
    public function isMetered(): bool
    {
        return ! $this->isFeature() && $this !== self::MonthlyCredits;
    }

    public function label(): string
    {
        return match ($this) {
            self::Properties => 'Properties',
            self::Tours => 'Virtual tours',
            self::ScenesPerTour => 'Scenes per tour',
            self::StorageMb => 'Media storage',
            self::TeamMembers => 'Team members',
            self::VideosPerMonth => 'Videos per month',
            self::MonthlyCredits => 'AI credits per month',
            self::VideoStudio => 'AI Video Studio',
            self::RemoveBranding => 'White-label (no platform branding)',
            self::AdvancedAnalytics => 'Advanced analytics',
            self::CustomDomain => 'Custom domain',
            self::ApiAccess => 'API access',
            self::UnlimitedCredits => 'Unlimited AI credits',
            self::TrackingScripts => 'Google Analytics & Tag Manager',
            self::StreetView => 'Google Street View publishing',
        };
    }

    /**
     * @return list<self>
     */
    public static function limits(): array
    {
        return array_values(array_filter(self::cases(), fn (self $entitlement): bool => ! $entitlement->isFeature()));
    }

    /**
     * @return list<self>
     */
    public static function features(): array
    {
        return array_values(array_filter(self::cases(), fn (self $entitlement): bool => $entitlement->isFeature()));
    }
}
