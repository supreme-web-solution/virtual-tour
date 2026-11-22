<?php

namespace App\Enums;

enum SoundtrackMode: string
{
    case None = 'none';
    case AiMusic = 'ai_music';
    case Ambient = 'ambient';
    case OwnAudio = 'own_audio';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No sound',
            self::AiMusic => 'AI music',
            self::Ambient => 'Ambient sound',
            self::OwnAudio => 'My own audio',
        };
    }

    public const MOODS = ['luxury', 'cinematic', 'calm', 'elegant', 'modern', 'corporate', 'warm', 'romantic', 'upbeat', 'inspirational', 'minimal', 'dramatic'];

    public const AMBIENCES = ['nature', 'wind', 'birds', 'ocean', 'city', 'soft_room', 'rain', 'water', 'fireplace', 'outdoor'];
}
