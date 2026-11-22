<?php

namespace App\Services\Import;

use App\Contracts\AI\StructuredOutputProvider;
use App\Enums\AreaUnit;
use App\Enums\PropertyType;
use App\Services\AI\StructuredRequest;
use App\Services\AI\StructuredResult;
use Illuminate\Support\Str;

/**
 * Turns what a listing page exposes into property fields. Schema.org data
 * from the page wins where it exists; the AI fills gaps from the visible
 * text and is told never to guess.
 */
class ListingExtractionService
{
    public function __construct(protected StructuredOutputProvider $ai) {}

    /**
     * @param  array{title: string|null, description: string|null, structured: array<string, mixed>, text: string}  $page
     * @return array{details: array<string, mixed>, result: StructuredResult}
     */
    public function extract(array $page, string $url): array
    {
        $result = $this->ai->generate(new StructuredRequest(
            model: (string) config('ai.providers.openai.text_model'),
            instructions: $this->instructions(),
            prompt: $this->prompt($page, $url),
            schemaName: 'listing_details',
            schema: $this->schema(),
            maxOutputTokens: 1500,
        ));

        return ['details' => $this->merge($page, $result->data), 'result' => $result];
    }

    /**
     * Combine page metadata (authoritative) with AI-extracted values.
     *
     * @param  array{title: string|null, description: string|null, structured: array<string, mixed>}  $page
     * @param  array<string, mixed>  $ai
     * @return array<string, mixed>
     */
    public function merge(array $page, array $ai = []): array
    {
        $structured = $page['structured'];
        $pick = fn (string $key) => $structured[$key] ?? (($ai[$key] ?? null) === '' ? null : ($ai[$key] ?? null));

        $type = PropertyType::tryFrom((string) ($ai['property_type'] ?? '')) ?? PropertyType::Residential;
        $unit = AreaUnit::tryFrom((string) ($ai['area_unit'] ?? '')) ?? AreaUnit::SquareMetres;
        $currency = strtoupper((string) $pick('currency'));
        $price = $this->number($pick('price'));

        return [
            'name' => Str::limit((string) ($pick('name') ?? $page['title'] ?? 'Imported property'), 160, ''),
            'property_type' => $type->value,
            'description' => $this->text($pick('description') ?? $page['description'], 10000),
            'address' => $this->text($pick('address'), 255),
            'city' => $this->text($pick('city'), 120),
            'region' => $this->text($pick('region'), 120),
            'country' => $this->text($pick('country'), 120),
            'latitude' => $this->coordinate($structured['latitude'] ?? null, 90),
            'longitude' => $this->coordinate($structured['longitude'] ?? null, 180),
            'bedrooms' => ($bedrooms = $this->number($pick('bedrooms'))) === null ? null : (int) min(500, $bedrooms),
            'bathrooms' => ($bathrooms = $this->number($pick('bathrooms'))) === null ? null : min(500, $bathrooms),
            'area' => $this->number($pick('area')),
            'area_unit' => $unit->value,
            'price' => $price,
            'currency' => $price !== null && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
        ];
    }

    protected function instructions(): string
    {
        return <<<'TXT'
        You extract facts about one real-estate property from the text of its public listing page.

        Rules:
        - Only report what the page states. Never guess or invent values; use null when a fact is not stated.
        - property_type must be one of the allowed values; use "residential" for homes that fit nothing more specific, "other" otherwise.
        - price is a plain number without separators or currency symbols; currency is the ISO 4217 code (for example NGN, USD, GBP, EUR).
        - area is the internal floor area as a number, with area_unit "sqm" or "sqft".
        - description is a neutral summary of the listing in at most 800 characters, in the page's language, without contact details.
        - Ignore navigation, adverts, cookie notices and other listings shown on the page.
        TXT;
    }

    /**
     * @param  array{title: string|null, description: string|null, structured: array<string, mixed>, text: string}  $page
     */
    protected function prompt(array $page, string $url): string
    {
        return implode("\n\n", array_filter([
            'Listing URL: '.$url,
            $page['title'] ? 'Page title: '.$page['title'] : null,
            $page['description'] ? 'Meta description: '.$page['description'] : null,
            $page['structured'] !== [] ? 'Structured data on the page: '.json_encode($page['structured'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            "Page text:\n".$page['text'],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $nullable = fn (string $type): array => ['type' => [$type, 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'property_type', 'description', 'address', 'city', 'region', 'country', 'bedrooms', 'bathrooms', 'area', 'area_unit', 'price', 'currency'],
            'properties' => [
                'name' => ['type' => 'string'],
                'property_type' => ['type' => 'string', 'enum' => array_map(fn (PropertyType $type): string => $type->value, PropertyType::cases())],
                'description' => $nullable('string'),
                'address' => $nullable('string'),
                'city' => $nullable('string'),
                'region' => $nullable('string'),
                'country' => $nullable('string'),
                'bedrooms' => $nullable('integer'),
                'bathrooms' => $nullable('number'),
                'area' => $nullable('number'),
                'area_unit' => ['type' => ['string', 'null'], 'enum' => ['sqm', 'sqft', null]],
                'price' => $nullable('number'),
                'currency' => $nullable('string'),
            ],
        ];
    }

    protected function number(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = preg_replace('/[^\d.]/', '', $value);
        }

        return is_numeric($value) && (float) $value >= 0 && (float) $value < 1e13 ? (float) $value : null;
    }

    protected function coordinate(mixed $value, int $limit): ?float
    {
        return is_numeric($value) && abs((float) $value) <= $limit ? (float) $value : null;
    }

    protected function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : Str::limit($value, $max, '');
    }
}
