<?php

namespace App\Enums;

enum AssetType: string
{
    case Photo = 'photo';
    case Panorama = 'panorama';
    case Video = 'video';
    case Audio = 'audio';
    case Logo = 'logo';
    case FloorPlan = 'floor_plan';

    public function label(): string
    {
        return match ($this) {
            self::Panorama => '360° panorama',
            self::FloorPlan => 'Floor plan',
            default => ucfirst($this->value),
        };
    }

    /**
     * File formats (as detected by the storage provider) accepted for this type.
     *
     * @return list<string>
     */
    public function allowedFormats(): array
    {
        return match ($this) {
            self::Photo, self::Logo, self::FloorPlan => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],
            self::Panorama => ['jpg', 'jpeg', 'png', 'webp'],
            self::Video => ['mp4', 'mov', 'webm'],
            self::Audio => ['mp3', 'wav', 'm4a'],
        };
    }

    /**
     * Maximum accepted file size in bytes.
     */
    public function maxBytes(): int
    {
        return match ($this) {
            self::Photo, self::Logo, self::FloorPlan => 40 * 1024 * 1024,
            self::Panorama => 100 * 1024 * 1024,
            self::Video => 500 * 1024 * 1024,
            self::Audio => 50 * 1024 * 1024,
        };
    }

    /**
     * The storage provider's resource family for this asset type.
     */
    public function resourceType(): string
    {
        return match ($this) {
            self::Video, self::Audio => 'video',
            default => 'image',
        };
    }

    /**
     * @return list<array{value: string, label: string, formats: list<string>, max_bytes: int}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'formats' => $type->allowedFormats(),
            'max_bytes' => $type->maxBytes(),
        ], self::cases());
    }
}
