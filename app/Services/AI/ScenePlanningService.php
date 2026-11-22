<?php

namespace App\Services\AI;

use App\Contracts\AI\StructuredOutputProvider;
use App\Exceptions\ProviderException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turns a property description into a reviewable list of 360° scenes, each
 * with a panorama prompt. Model output is validated and normalised before
 * it is stored; it never drives provider calls directly without review.
 */
class ScenePlanningService
{
    public function __construct(protected StructuredOutputProvider $ai) {}

    /**
     * @param  array<string, mixed>  $brief  Validated user input (description, scene_count, styles…).
     * @return array{plan: array<string, mixed>, result: StructuredResult}
     */
    public function plan(array $brief): array
    {
        $sceneCount = (int) $brief['scene_count'];

        $result = $this->ai->generate(new StructuredRequest(
            model: (string) config('ai.providers.openai.text_model'),
            instructions: $this->instructions(),
            prompt: $this->prompt($brief),
            schemaName: 'scene_plan',
            schema: $this->schema(),
            maxOutputTokens: 6000,
        ));

        return [
            'plan' => $this->normalise($result->data, $sceneCount, (string) ($brief['negative_prompt'] ?? '')),
            'result' => $result,
        ];
    }

    protected function instructions(): string
    {
        return <<<'TXT'
        You plan 360° virtual tours for property marketing. For the described property, propose the scenes a visitor would tour and write one panorama prompt per scene for an AI that generates equirectangular 360° images.

        Rules for every panorama_prompt:
        - Describe a single location seen from standing eye height in the middle of the space, as a full 360° surround view.
        - Keep architecture, materials, furnishing style, colour palette and lighting consistent across all scenes of the property.
        - Use realistic room proportions and plausible layouts. Describe only what the brief states or what is a reasonable, clearly typical choice for it.
        - No people, animals, text, logos, watermarks or brand names.
        - At most 550 characters, plain descriptive prose.

        negative_prompt lists things to avoid (comma separated, at most 200 characters).
        Give each scene a short unique lowercase key (letters, digits, underscores). "connections" lists the keys of scenes a visitor could walk to directly from this one (doorways, open plan). suggested_order starts at 1 at the natural entry point.
        TXT;
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    protected function prompt(array $brief): string
    {
        $lines = [
            'Property description: '.trim((string) $brief['description']),
            'Property type: '.($brief['property_type'] ?? 'residential'),
            'Number of scenes: exactly '.$brief['scene_count'],
        ];

        foreach ([
            'interior_style' => 'Interior style',
            'architectural_style' => 'Architectural style',
            'lighting' => 'Lighting',
            'time_of_day' => 'Time of day',
            'color_palette' => 'Colour palette',
            'atmosphere' => 'Atmosphere',
            'additional_instructions' => 'Additional instructions',
            'negative_prompt' => 'Things to avoid',
        ] as $key => $label) {
            if (filled($brief[$key] ?? null)) {
                $lines[] = "{$label}: ".trim((string) $brief[$key]);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Strict-mode JSON schema: every property required, no extra keys.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['tour_title', 'tour_description', 'scenes'],
            'properties' => [
                'tour_title' => ['type' => 'string'],
                'tour_description' => ['type' => 'string'],
                'scenes' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => (int) config('ai.limits.max_scenes_per_plan', 12),
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'title', 'room_type', 'description', 'panorama_prompt', 'negative_prompt', 'suggested_order', 'connections'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'room_type' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'panorama_prompt' => ['type' => 'string'],
                            'negative_prompt' => ['type' => 'string'],
                            'suggested_order' => ['type' => 'integer'],
                            'connections' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Validate the model's JSON and coerce it into the shape the app relies on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalise(array $data, int $sceneCount, string $userNegative = ''): array
    {
        $validator = Validator::make($data, [
            'tour_title' => ['required', 'string'],
            'tour_description' => ['present', 'string'],
            'scenes' => ['required', 'array', 'min:1'],
            'scenes.*.title' => ['required', 'string'],
            'scenes.*.room_type' => ['present', 'string'],
            'scenes.*.description' => ['present', 'string'],
            'scenes.*.panorama_prompt' => ['required', 'string', 'min:10'],
            'scenes.*.negative_prompt' => ['present', 'string'],
            'scenes.*.suggested_order' => ['required', 'integer'],
            'scenes.*.connections' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ProviderException('The AI scene plan was not in the expected format.', retryable: true);
        }

        $scenes = collect(array_filter((array) $data['scenes'], 'is_array'))
            ->sortBy('suggested_order')
            ->take(max(1, $sceneCount))
            ->values();

        // Make keys safe and unique, remembering how the model referred to them.
        $keyMap = [];
        $used = [];

        foreach ($scenes as $index => $scene) {
            $base = Str::slug((string) ($scene['key'] ?: $scene['title']), '_') ?: 'scene';
            $key = $base;
            $suffix = 2;

            while (in_array($key, $used, true)) {
                $key = "{$base}_{$suffix}";
                $suffix++;
            }

            $used[] = $key;
            $keyMap[(string) $scene['key']] = $key;
        }

        $negative = trim($userNegative);

        return [
            'tour_title' => Str::limit(trim($data['tour_title']), 160, ''),
            'tour_description' => Str::limit(trim($data['tour_description']), 2000, ''),
            'scenes' => $scenes->map(function (array $scene, int $index) use ($keyMap, $used, $negative): array {
                $key = $used[$index];
                $connections = collect(array_filter((array) $scene['connections'], 'is_scalar'))
                    ->map(fn ($connection): ?string => $keyMap[(string) $connection] ?? null)
                    ->filter(fn (?string $connection): bool => $connection !== null && $connection !== $key)
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'title' => Str::limit(trim($scene['title']), 120, ''),
                    'room_type' => Str::limit(trim($scene['room_type']), 60, ''),
                    'description' => Str::limit(trim($scene['description']), 1000, ''),
                    'panorama_prompt' => Str::limit(trim($scene['panorama_prompt']), 1800, ''),
                    'negative_prompt' => Str::limit(trim(implode(', ', array_filter([trim($scene['negative_prompt']), $negative]))), 600, ''),
                    'order' => $index + 1,
                    'connections' => $connections,
                ];
            })->all(),
        ];
    }
}
