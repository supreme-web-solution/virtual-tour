<?php

use App\Jobs\Media\WarmPanoramaVersions;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Queue;

test('a new panorama queues a warm-up of its resized versions when storage is configured', function () {
    Queue::fake();
    config(['services.cloudinary.cloud_name' => 'demo']);

    $panorama = MediaAsset::factory()->create(['width' => 8000, 'height' => 4000, 'cloudinary_resource_type' => 'image']);
    MediaAsset::factory()->create(['width' => 4000, 'height' => 3000, 'cloudinary_resource_type' => 'image']);

    Queue::assertPushed(WarmPanoramaVersions::class, 1);
    Queue::assertPushed(fn (WarmPanoramaVersions $job) => $job->mediaAssetId === $panorama->id);
});
