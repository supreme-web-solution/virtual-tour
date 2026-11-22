<?php

namespace App\Enums;

enum PropertyType: string
{
    case Residential = 'residential';
    case Apartment = 'apartment';
    case Luxury = 'luxury';
    case Villa = 'villa';
    case Hotel = 'hotel';
    case Resort = 'resort';
    case CommercialBuilding = 'commercial_building';
    case Office = 'office';
    case Restaurant = 'restaurant';
    case Retail = 'retail';
    case School = 'school';
    case University = 'university';
    case Museum = 'museum';
    case EventVenue = 'event_venue';
    case ConstructionProject = 'construction_project';
    case ArchitecturalProject = 'architectural_project';
    case TourismDestination = 'tourism_destination';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Luxury => 'Luxury property',
            self::CommercialBuilding => 'Commercial building',
            self::Retail => 'Retail space',
            self::EventVenue => 'Event venue',
            self::ConstructionProject => 'Construction project',
            self::ArchitecturalProject => 'Architectural project',
            self::TourismDestination => 'Tourism destination',
            default => ucfirst($this->value),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
