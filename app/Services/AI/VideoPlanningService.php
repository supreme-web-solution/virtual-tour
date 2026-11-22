<?php

namespace App\Services\AI;

use App\Contracts\AI\StructuredOutputProvider;
use App\Enums\VideoStyle;
use App\Exceptions\ProviderException;
use App\Models\VideoShot;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Plans a property video as a storyboard: which photo each shot uses, its
 * camera movement, duration, transition and generation prompt. Durations
 * are fitted to the video model's limits and the requested total.
 */
class VideoPlanningService
{
    public function __construct(protected StructuredOutputProvider $ai) {}

    /**
     * @param  list<array{id: string, label: string, image_url: string}>  $sources  In the user's chosen order.
     * @param  array{min_seconds: int, max_seconds: int}  $limits
     * @return array{storyboard: array<string, mixed>, result: StructuredResult}
     */
    public function plan(VideoStyle $style, string $aspectRatio, int $totalSeconds, array $sources, array $limits, ?string $brief = null): array
    {
        $listing = collect($sources)
            ->map(fn (array $source, int $index): string => 'Image '.($index + 1).': '.$source['label'])
            ->implode("\n");

        $result = $this->ai->generate(new StructuredRequest(
            model: (string) config('ai.providers.openai.vision_model'),
            instructions: $this->instructions($limits),
            prompt: implode("\n", array_filter([
                "Style: {$style->label()}. {$style->direction()}",
                filled($brief) ? 'Client brief: '.trim((string) $brief) : null,
                "Format: {$aspectRatio}. Total length: about {$totalSeconds} seconds.",
                'Shot length: '.$limits['min_seconds'].'–'.$limits['max_seconds'].' seconds each.',
                "Available images (use each at most once, in a sensible order):\n{$listing}",
            ])),
            schemaName: 'video_storyboard',
            schema: $this->schema(),
            imageUrls: array_column($sources, 'image_url'),
            maxOutputTokens: 5000,
        ));

        return [
            'storyboard' => $this->normalise($result->data, $sources, $totalSeconds, $limits),
            'result' => $result,
        ];
    }

    /**
     * @param  array{min_seconds: int, max_seconds: int}  $limits
     */
    protected function instructions(array $limits): string
    {
        $movements = implode(', ', VideoShot::CAMERA_MOVEMENTS);

        return <<<TXT
        You are a property video director. Plan a short promotional video made by animating the supplied photos one shot at a time with an image-to-video model.

        For each shot choose one image, a camera movement from this list: {$movements}; a duration; and a transition into the next shot (crossfade, fade_black or cut).

        Write each shot's prompt for the video model:
        - Describe only what is visible in that image. Keep the exact architecture, room layout, furniture, materials, windows, walls and doors.
        - Describe the camera movement and the lighting/mood.
        - No new objects, no people, no text, no morphing or structural changes.
        - At most 400 characters.

        Open with the strongest establishing image and close with a hero shot. Also write a short instrumental music brief that suits the style.
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
            'required' => ['video_title', 'creative_direction', 'music_prompt', 'shots'],
            'properties' => [
                'video_title' => ['type' => 'string'],
                'creative_direction' => ['type' => 'string'],
                'music_prompt' => ['type' => 'string'],
                'shots' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['image_number', 'scene_name', 'duration', 'camera_movement', 'visual_description', 'prompt', 'transition', 'audio_notes'],
                        'properties' => [
                            'image_number' => ['type' => 'integer'],
                            'scene_name' => ['type' => 'string'],
                            'duration' => ['type' => 'integer'],
                            'camera_movement' => ['type' => 'string', 'enum' => VideoShot::CAMERA_MOVEMENTS],
                            'visual_description' => ['type' => 'string'],
                            'prompt' => ['type' => 'string'],
                            'transition' => ['type' => 'string', 'enum' => VideoShot::TRANSITIONS],
                            'audio_notes' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Validate the plan and fit shot durations to the model limits and the total length.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{id: string, label: string, image_url: string}>  $sources
     * @param  array{min_seconds: int, max_seconds: int}  $limits
     * @return array<string, mixed>
     */
    public function normalise(array $data, array $sources, int $totalSeconds, array $limits): array
    {
        $count = count($sources);

        $validator = Validator::make($data, [
            'video_title' => ['required', 'string'],
            'shots' => ['required', 'array', 'min:1'],
            'shots.*.image_number' => ['required', 'integer', 'between:1,'.max(1, $count)],
            'shots.*.prompt' => ['required', 'string', 'min:10'],
            'shots.*.camera_movement' => ['required', 'in:'.implode(',', VideoShot::CAMERA_MOVEMENTS)],
            'shots.*.transition' => ['required', 'in:'.implode(',', VideoShot::TRANSITIONS)],
        ]);

        if ($validator->fails()) {
            throw new ProviderException('The AI storyboard was not in the expected format.', retryable: true);
        }

        // Use each image once, and only as many shots as the length allows.
        $maxShots = max(1, intdiv($totalSeconds, $limits['min_seconds']));
        $shots = collect(array_filter((array) $data['shots'], 'is_array'))
            ->unique('image_number')
            ->take($maxShots)
            ->values();

        $durations = $this->fitDurations(
            array_values($shots->map(fn (array $shot): int => (int) $shot['duration'])->all()),
            $totalSeconds,
            $limits['min_seconds'],
            $limits['max_seconds'],
        );

        return [
            'video_title' => Str::limit(trim((string) $data['video_title']), 160, ''),
            'creative_direction' => Str::limit(trim((string) ($data['creative_direction'] ?? '')), 1000, ''),
            'music_prompt' => Str::limit(trim((string) ($data['music_prompt'] ?? '')), 600, ''),
            'shots' => $shots->map(fn (array $shot, int $index): array => [
                'source_id' => $sources[(int) $shot['image_number'] - 1]['id'],
                'scene_name' => Str::limit(trim((string) ($shot['scene_name'] ?? '')), 120, ''),
                'duration' => $durations[$index],
                'camera_movement' => $shot['camera_movement'],
                'visual_description' => Str::limit(trim((string) ($shot['visual_description'] ?? '')), 500, ''),
                'prompt' => Str::limit(trim((string) $shot['prompt']), 1000, ''),
                'transition' => $shot['transition'],
                'audio_notes' => Str::limit(trim((string) ($shot['audio_notes'] ?? '')), 200, ''),
            ])->all(),
        ];
    }

    /**
     * Clamp each duration to [min, max] and nudge them so the total is as
     * close to the target as the limits allow.
     *
     * @param  list<int>  $durations
     * @return list<int>
     */
    public function fitDurations(array $durations, int $target, int $min, int $max): array
    {
        $durations = array_map(fn (int $seconds): int => max($min, min($max, $seconds ?: $min)), $durations);
        $difference = $target - array_sum($durations);
        $guard = 0;

        while ($difference !== 0 && $guard++ < 1000) {
            $changed = false;

            foreach ($durations as $index => $seconds) {
                if ($difference > 0 && $seconds < $max) {
                    $durations[$index]++;
                    $difference--;
                    $changed = true;
                } elseif ($difference < 0 && $seconds > $min) {
                    $durations[$index]--;
                    $difference++;
                    $changed = true;
                }

                if ($difference === 0) {
                    break;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $durations;
    }
}
