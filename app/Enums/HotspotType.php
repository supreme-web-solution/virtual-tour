<?php

namespace App\Enums;

enum HotspotType: string
{
    /** Moves the visitor to another scene. */
    case Navigation = 'navigation';

    /** Opens an information panel. */
    case Info = 'info';

    /** Opens an external website or booking link. */
    case Link = 'link';

    /** Opens an image or video from the media library. */
    case Media = 'media';

    public function label(): string
    {
        return match ($this) {
            self::Navigation => 'Go to scene',
            self::Info => 'Information',
            self::Link => 'External link',
            self::Media => 'Image or video',
        };
    }
}
