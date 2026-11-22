<?php

namespace App\Enums;

enum TourVisibility: string
{
    /** Anyone can view; search engines may index it. */
    case Public = 'public';

    /** Anyone with the link can view; not indexed. */
    case Unlisted = 'unlisted';

    /** Only members of the workspace can view. */
    case Private = 'private';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $visibility): array => ['value' => $visibility->value, 'label' => $visibility->label()],
            self::cases(),
        );
    }
}
