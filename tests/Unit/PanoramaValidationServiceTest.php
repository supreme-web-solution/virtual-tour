<?php

use App\Services\Panorama\PanoramaValidationService;

test('a 2:1 image of good resolution is a clean equirectangular panorama', function () {
    $result = (new PanoramaValidationService)->inspect(8000, 4000);

    expect($result)->toBe(['is_equirectangular' => true, 'ratio' => 2.0, 'warnings' => []]);
});

test('ratios within the tolerance are accepted', function (int $width, int $height) {
    expect((new PanoramaValidationService)->inspect($width, $height)['is_equirectangular'])->toBeTrue();
})->with([
    'slightly wide' => [8100, 4000],
    'slightly tall' => [7900, 4000],
]);

test('other ratios are flagged with a warning', function () {
    $result = (new PanoramaValidationService)->inspect(6000, 4000);

    expect($result['is_equirectangular'])->toBeFalse()
        ->and($result['ratio'])->toBe(1.5)
        ->and($result['warnings'][0])->toContain('2:1');
});

test('low and very high resolutions produce warnings', function () {
    $service = new PanoramaValidationService;

    expect($service->inspect(2000, 1000)['warnings'])->toHaveCount(1)
        ->and($service->inspect(20000, 10000)['warnings'])->toHaveCount(1);
});

test('missing dimensions are reported', function () {
    expect((new PanoramaValidationService)->inspect(null, 1000))
        ->toMatchArray(['is_equirectangular' => false, 'ratio' => null]);
});
