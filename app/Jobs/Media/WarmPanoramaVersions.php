<?php

namespace App\Jobs\Media;

use App\Contracts\Media\MediaStorage;
use App\Models\MediaAsset;
use App\Services\Tours\TourPresenter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Cloudinary builds each resized version the first time it is requested, which
 * takes a few seconds for a big panorama. Requesting them once up front means
 * the first visitor gets them from the cache instead of waiting (or failing).
 */
class WarmPanoramaVersions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $mediaAssetId)
    {
        $this->onQueue('media');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 180];
    }

    public function handle(MediaStorage $storage, TourPresenter $presenter): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        if ($asset === null) {
            return;
        }

        $widths = array_unique([
            TourPresenter::PREVIEW_WIDTH,
            TourPresenter::MOBILE_WIDTH,
            $presenter->desktopWidth($asset),
        ]);

        foreach ($widths as $width) {
            Http::timeout(90)
                ->get($storage->scaledImageUrl($asset->cloudinary_public_id, $width))
                ->throw();
        }
    }
}
