<?php

namespace App\Services\AI;

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Media\MediaStorage;
use App\Exceptions\ProviderException;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Classifies property photographs with a vision model: room type, visible
 * features, quality, near-duplicates and a suggested tour order. Detected
 * facts and inferred suggestions are kept apart, and nothing that is not
 * visible may be stated as fact.
 */
class PropertyAnalysisService
{
    public const QUALITY = ['good', 'acceptable', 'poor'];

    public function __construct(
        protected StructuredOutputProvider $ai,
        protected MediaStorage $storage,
    ) {}

    /**
     * @param  list<MediaAsset>  $photos
     * @return array{analysis: array<string, mixed>, result: StructuredResult}
     */
    public function analyse(array $photos): array
    {
        $width = (int) config('ai.providers.openai.vision_image_width', 1024);

        $result = $this->ai->generate(new StructuredRequest(
            model: (string) config('ai.providers.openai.vision_model'),
            instructions: $this->instructions(),
            prompt: 'Analyse these '.count($photos).' property photographs. They are numbered 1 to '.count($photos).' in the order given.',
            schemaName: 'property_photo_analysis',
            schema: $this->schema(),
            imageUrls: array_map(fn (MediaAsset $photo): string => $this->storage->scaledImageUrl($photo->cloudinary_public_id, $width), $photos),
            maxOutputTokens: 6000,
        ));

        return [
            'analysis' => $this->normalise($result->data, array_map(fn (MediaAsset $photo): string => $photo->ulid, $photos)),
            'result' => $result,
        ];
    }

    protected function instructions(): string
    {
        return <<<'TXT'
        You analyse photographs of a property for a virtual tour platform.
        For each image, identify the room or area, whether it is indoors, a short scene title, a one-sentence description, and notable visible features (architecture, materials, furniture, lighting).
        observed_facts must contain only things clearly visible in that image. Put anything you infer (for example likely use of a room or how rooms connect) in inferred_suggestions instead. Never invent details that are not visible.
        Rate image quality as good, acceptable or poor and explain briefly (sharpness, exposure, framing).
        duplicate_of is the number of an earlier image showing essentially the same view, or null.
        suggested_order lists every image number once, in a natural walkthrough order starting from the entrance or exterior.
        suggested_connections lists pairs of image numbers for areas that appear directly connected.
        TXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['property_type', 'summary', 'images', 'suggested_order', 'suggested_connections'],
            'properties' => [
                'property_type' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'images' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['image_number', 'room_type', 'indoor', 'title', 'description', 'features', 'quality', 'quality_notes', 'duplicate_of', 'observed_facts', 'inferred_suggestions'],
                        'properties' => [
                            'image_number' => ['type' => 'integer'],
                            'room_type' => ['type' => 'string'],
                            'indoor' => ['type' => 'boolean'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'features' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'quality' => ['type' => 'string', 'enum' => self::QUALITY],
                            'quality_notes' => ['type' => 'string'],
                            'duplicate_of' => ['type' => ['integer', 'null']],
                            'observed_facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'inferred_suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'suggested_order' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'suggested_connections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['from', 'to'],
                        'properties' => [
                            'from' => ['type' => 'integer'],
                            'to' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Validate and map image numbers back to media asset IDs.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $assetIds  Asset ULIDs in the order the images were sent.
     * @return array<string, mixed>
     */
    public function normalise(array $data, array $assetIds): array
    {
        $count = count($assetIds);

        $validator = Validator::make($data, [
            'property_type' => ['present', 'string'],
            'summary' => ['present', 'string'],
            'images' => ['required', 'array'],
            'images.*.image_number' => ['required', 'integer', 'between:1,'.$count],
            'images.*.title' => ['required', 'string'],
            'images.*.quality' => ['required', 'in:'.implode(',', self::QUALITY)],
            'suggested_order' => ['present', 'array'],
            'suggested_connections' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ProviderException('The AI photo analysis was not in the expected format.', retryable: true);
        }

        $idFor = fn (?int $number): ?string => $number !== null && $number >= 1 && $number <= $count ? $assetIds[$number - 1] : null;
        $strings = fn ($values): array => array_values(array_map(fn ($value): string => Str::limit(trim((string) $value), 300, ''), array_filter((array) $values, 'is_string')));

        $images = collect(array_filter((array) $data['images'], 'is_array'))
            ->unique('image_number')
            ->map(fn (array $image): array => [
                'asset_id' => $idFor((int) $image['image_number']),
                'room_type' => Str::limit(trim((string) ($image['room_type'] ?? '')), 60, ''),
                'indoor' => (bool) ($image['indoor'] ?? true),
                'title' => Str::limit(trim((string) $image['title']), 120, ''),
                'description' => Str::limit(trim((string) ($image['description'] ?? '')), 500, ''),
                'features' => $strings($image['features'] ?? []),
                'quality' => $image['quality'],
                'quality_notes' => Str::limit(trim((string) ($image['quality_notes'] ?? '')), 300, ''),
                'duplicate_of' => $idFor(isset($image['duplicate_of']) ? (int) $image['duplicate_of'] : null),
                'observed_facts' => $strings($image['observed_facts'] ?? []),
                'inferred_suggestions' => $strings($image['inferred_suggestions'] ?? []),
            ])
            ->keyBy('asset_id');

        // Order: the model's suggestion first, then any images it left out.
        $order = collect(array_filter((array) $data['suggested_order'], 'is_int'))
            ->map(fn ($number): ?string => $idFor((int) $number))
            ->filter()
            ->unique()
            ->merge($assetIds)
            ->unique()
            ->filter(fn (string $id): bool => $images->has($id))
            ->values();

        return [
            'property_type' => Str::limit(trim((string) $data['property_type']), 60, ''),
            'summary' => Str::limit(trim((string) $data['summary']), 1000, ''),
            'images' => $order->map(fn (string $id): array => $images[$id])->all(),
            'connections' => collect(array_filter((array) $data['suggested_connections'], 'is_array'))
                ->map(fn (array $pair): array => ['from' => $idFor((int) $pair['from']), 'to' => $idFor((int) $pair['to'])])
                ->filter(fn (array $pair): bool => $pair['from'] !== null && $pair['to'] !== null && $pair['from'] !== $pair['to'])
                ->values()
                ->all(),
        ];
    }
}
