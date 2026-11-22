<?php

namespace App\Enums;

enum VideoStyle: string
{
    case Walkthrough = 'property_walkthrough';
    case DroneReveal = 'drone_reveal';
    case SocialTeaser = 'social_teaser';
    case BeforeAfter = 'before_after_staging';
    case LuxuryShowcase = 'luxury_showcase';
    case YoutubeAd = 'youtube_advert';
    case DevelopmentLaunch = 'development_launch';
    case CommercialPromo = 'commercial_promo';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Walkthrough => 'Property walkthrough',
            self::DroneReveal => 'Drone-style reveal',
            self::SocialTeaser => 'Social media teaser',
            self::BeforeAfter => 'Before & after staging',
            self::LuxuryShowcase => 'Luxury showcase',
            self::YoutubeAd => 'YouTube property advert',
            self::DevelopmentLaunch => 'New development launch',
            self::CommercialPromo => 'Commercial property promo',
            self::Custom => 'Custom style',
        };
    }

    /**
     * Creative direction given to the storyboard planner.
     */
    public function direction(): string
    {
        return match ($this) {
            self::Walkthrough => 'A smooth, steady walkthrough that flows from room to room with gentle forward movement, as if a visitor is touring the property.',
            self::DroneReveal => 'Aerial-inspired establishing shots: slow rises, wide orbits and reveals. This is simulated camera movement over still photos, not real drone footage.',
            self::SocialTeaser => 'A fast, punchy teaser for Reels, TikTok and Shorts: short shots, dynamic movement, strongest visuals first.',
            self::BeforeAfter => 'Contrast an empty or unstaged room with its staged version. Only use the before and after images supplied; never invent the original condition.',
            self::LuxuryShowcase => 'Slow, elegant cinematic movement, premium lighting, restrained pacing and long, smooth transitions.',
            self::YoutubeAd => 'An advert structure: an opening hero reveal, room highlights, standout features and a closing hero shot.',
            self::DevelopmentLaunch => 'A launch film for a new development or architectural renders: confident reveals of architecture, amenities and lifestyle.',
            self::CommercialPromo => 'A dynamic promo for a hotel, office, restaurant or retail space highlighting atmosphere and key spaces.',
            self::Custom => 'Follow the user\'s brief closely while keeping the property accurate.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $style): array => [
            'value' => $style->value,
            'label' => $style->label(),
            'description' => $style->direction(),
        ], self::cases());
    }
}
