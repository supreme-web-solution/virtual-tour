<?php

use App\Exceptions\ProviderException;
use App\Services\AI\PropertyAnalysisService;
use App\Services\AI\ScenePlanningService;
use Tests\Fakes\FakeMediaStorage;
use Tests\Fakes\FakeStructuredOutputProvider;

function planScene(string $key, string $title, int $order, array $connections = []): array
{
    return [
        'key' => $key, 'title' => $title, 'room_type' => 'room', 'description' => 'A room',
        'panorama_prompt' => "A bright {$title} with large windows", 'negative_prompt' => 'people',
        'suggested_order' => $order, 'connections' => $connections,
    ];
}

test('scene plans are ordered, trimmed, de-duplicated and their connections remapped', function () {
    $service = new ScenePlanningService(new FakeStructuredOutputProvider);

    $plan = $service->normalise([
        'tour_title' => 'Villa',
        'tour_description' => 'A villa',
        'scenes' => [
            planScene('Kitchen!', 'Kitchen', 2, ['Living Room', 'ghost']),
            planScene('Living Room', 'Living Room', 1, ['Kitchen!', 'Living Room']),
            planScene('kitchen', 'Second kitchen', 3),
            planScene('extra', 'Extra', 4),
        ],
    ], sceneCount: 3, userNegative: 'clutter');

    expect(array_column($plan['scenes'], 'key'))->toBe(['living_room', 'kitchen', 'kitchen_2'])
        ->and($plan['scenes'][0]['connections'])->toBe(['kitchen'])
        ->and($plan['scenes'][1]['connections'])->toBe(['living_room'])
        ->and($plan['scenes'][0]['negative_prompt'])->toBe('people, clutter')
        ->and($plan['scenes'][2]['order'])->toBe(3);
});

test('a malformed scene plan is rejected', function () {
    $service = new ScenePlanningService(new FakeStructuredOutputProvider);

    expect(fn () => $service->normalise(['tour_title' => 'x', 'scenes' => [['title' => 'No prompt']]], 3))
        ->toThrow(ProviderException::class);
});

test('photo analysis maps image numbers to assets and drops invalid references', function () {
    $service = new PropertyAnalysisService(new FakeStructuredOutputProvider, new FakeMediaStorage);
    $image = fn (int $number, string $title, ?int $duplicate = null) => [
        'image_number' => $number, 'room_type' => 'room', 'indoor' => true, 'title' => $title, 'description' => '',
        'features' => [], 'quality' => 'good', 'quality_notes' => '', 'duplicate_of' => $duplicate,
        'observed_facts' => ['Marble floor'], 'inferred_suggestions' => ['Likely the main lounge'],
    ];

    $analysis = $service->normalise([
        'property_type' => 'apartment',
        'summary' => 'Bright apartment',
        'images' => [$image(1, 'Kitchen'), $image(2, 'Lounge'), $image(3, 'Lounge again', 2)],
        'suggested_order' => [2, 9, 1],
        'suggested_connections' => [['from' => 1, 'to' => 2], ['from' => 2, 'to' => 7], ['from' => 3, 'to' => 3]],
    ], ['asset-a', 'asset-b', 'asset-c']);

    expect(array_column($analysis['images'], 'asset_id'))->toBe(['asset-b', 'asset-a', 'asset-c'])
        ->and($analysis['images'][2]['duplicate_of'])->toBe('asset-b')
        ->and($analysis['connections'])->toBe([['from' => 'asset-a', 'to' => 'asset-b']])
        ->and($analysis['images'][0]['observed_facts'])->toBe(['Marble floor']);
});

test('photo analysis rejects image numbers outside the batch', function () {
    $service = new PropertyAnalysisService(new FakeStructuredOutputProvider, new FakeMediaStorage);

    expect(fn () => $service->normalise([
        'property_type' => '', 'summary' => '', 'suggested_order' => [], 'suggested_connections' => [],
        'images' => [['image_number' => 5, 'title' => 'x', 'quality' => 'good']],
    ], ['asset-a']))->toThrow(ProviderException::class);
});
