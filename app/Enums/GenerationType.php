<?php

namespace App\Enums;

enum GenerationType: string
{
    case ScenePlan = 'scene_plan';
    case PhotoAnalysis = 'photo_analysis';
    case TextToPanorama = 'text_to_panorama';
    case PhotoToPanorama = 'photo_to_panorama';
    case VideoStoryboard = 'video_storyboard';
    case VideoClip = 'video_clip';
    case MusicTrack = 'music_track';
    case ListingExtraction = 'listing_extraction';
    case PhotoEnhance = 'photo_enhance';

    public function label(): string
    {
        return (string) config("ai.operations.{$this->value}.label", $this->value);
    }

    /**
     * Customer credits for one operation of this type. Per-second operations
     * (video clips) are priced from the requested duration in the input.
     *
     * @param  array<string, mixed>|null  $input
     */
    public function credits(?array $input = null): int
    {
        $perSecond = config("ai.operations.{$this->value}.credits_per_second");

        if ($perSecond !== null) {
            return (int) ceil((float) $perSecond * max(1, (int) ($input['duration'] ?? 0)));
        }

        return (int) config("ai.operations.{$this->value}.credits", 0);
    }

    public function producesPanorama(): bool
    {
        return in_array($this, [self::TextToPanorama, self::PhotoToPanorama], true);
    }
}
