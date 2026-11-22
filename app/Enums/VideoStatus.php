<?php

namespace App\Enums;

enum VideoStatus: string
{
    /** Storyboard being drafted by AI. */
    case Planning = 'planning';

    /** Storyboard ready for review; no clips generated yet. */
    case Draft = 'draft';

    case GeneratingClips = 'generating';

    /** All clips (and music) ready; the video can be rendered. */
    case ReadyToRender = 'ready';

    case Rendering = 'rendering';

    case Completed = 'completed';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning storyboard',
            self::Draft => 'Storyboard ready',
            self::GeneratingClips => 'Generating clips',
            self::ReadyToRender => 'Ready to render',
            self::Rendering => 'Rendering',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
