<?php

namespace App\Services\Tours;

use App\Contracts\Media\MediaStorage;
use App\Enums\AssetType;
use App\Enums\Entitlement;
use App\Enums\HotspotType;
use App\Models\MediaAsset;
use App\Models\TourFloorplan;
use App\Models\TourFloorplanMarker;
use App\Models\TourHotspot;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\Domains\BrandPresenter;
use App\Services\Domains\PublicUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * Builds the tour payloads consumed by the editor and the public viewer, so
 * both render from exactly the same structure and coordinate units.
 *
 * Coordinates are degrees (yaw -180..180, pitch -90..90); the frontend
 * converts to radians only at the viewer boundary.
 */
class TourPresenter
{
    /**
     * Desktop panorama width. Most desktop GPUs handle 8192px textures.
     */
    public const DESKTOP_WIDTH = 8192;

    /** Cloudinary rejects transformed images above 25 megapixels; stay just under. */
    public const MAX_PIXELS = 24_000_000;

    /**
     * Mobile panorama width, safe for virtually all phone GPUs.
     */
    public const MOBILE_WIDTH = 4096;

    /**
     * Small first-look panorama, shown while the full-size one downloads.
     */
    public const PREVIEW_WIDTH = 2048;

    public function __construct(
        protected MediaStorage $storage,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * A tour card (tours list and home page). Load property, coverAsset and
     * the first scene with its media, and count scenes, beforehand.
     *
     * @return array<string, mixed>
     */
    public function listItem(VirtualTour $tour, ?User $viewer = null): array
    {
        $stage = (string) ($tour->build_state['stage'] ?? 'ready');

        return [
            'id' => $tour->ulid,
            'title' => $tour->title,
            'status' => $tour->status->value,
            'visibility' => $tour->visibility->value,
            'scenes_count' => (int) $tour->getAttribute('scenes_count'),
            'property' => $tour->property === null ? null : ['id' => $tour->property->ulid, 'name' => $tour->property->name],
            'cover_url' => $tour->coverAsset->thumbnail_url ?? $tour->scenes->first()?->mediaAsset->thumbnail_url,
            'public_url' => app(PublicUrl::class)->tour($tour),
            // One-click creation still running (or failed) for this tour.
            'build_stage' => in_array($stage, ['ready'], true) ? null : $stage,
            'updated_at' => $tour->updated_at?->toIso8601String(),
            'can' => ['delete' => (bool) $viewer?->can('delete', $tour)],
        ];
    }

    /**
     * Full editor payload: every scene and hotspot, including hidden ones.
     *
     * @return array<string, mixed>
     */
    public function forEditor(VirtualTour $tour): array
    {
        $tour->loadMissing(['scenes.mediaAsset', 'scenes.hotspots.mediaAsset', 'property', 'coverAsset', 'floorplans.mediaAsset', 'floorplans.markers']);
        $this->assets = $this->assetsFor($tour);

        $branding = $tour->branding();
        $audio = $tour->audio();

        return [
            ...$this->tourAttributes($tour),
            'branding' => [...$branding, 'logo_url' => $this->assetUrl($branding['logo_asset_id'], thumbnail: true)],
            'audio' => [...$audio, 'background_name' => $this->assets[$audio['background_asset_id'] ?? '']->original_filename ?? null],
            'lead_settings' => $tour->leadSettings(),
            'map_settings' => [
                ...$tour->mapSettings(),
                'has_coordinates' => $this->hasMapLocation($tour),
                // Where the map opens in the editor: the property, if it has coordinates.
                'property_location' => $tour->property?->latitude === null || $tour->property->longitude === null
                    ? null
                    : ['latitude' => (float) $tour->property->latitude, 'longitude' => (float) $tour->property->longitude],
            ],
            'analytics_enabled' => $tour->analyticsEnabled(),
            'floorplans' => $this->floorplans($tour, $tour->scenes),
            'scenes' => $this->scenes($tour->scenes),
        ];
    }

    /**
     * Public payload: visible scenes only, and navigation hotspots that point
     * at visible scenes.
     *
     * @return array<string, mixed>
     */
    public function forPublic(VirtualTour $tour): array
    {
        $tour->loadMissing(['scenes.mediaAsset', 'scenes.hotspots.mediaAsset', 'property', 'coverAsset', 'floorplans.mediaAsset', 'floorplans.markers']);
        $this->assets = $this->assetsFor($tour);

        $visible = $tour->scenes->where('is_visible', true)->values();
        $visibleUlids = $visible->pluck('ulid')->all();

        $scenes = array_map(function (array $scene) use ($visibleUlids): array {
            $scene['hotspots'] = array_values(array_filter(
                $scene['hotspots'],
                fn (array $hotspot): bool => $hotspot['type'] !== HotspotType::Navigation->value
                    || in_array($hotspot['target_scene_id'], $visibleUlids, true),
            ));

            return $scene;
        }, $this->scenes($visible));

        $attributes = $this->tourAttributes($tour);

        return [
            'id' => $attributes['public_id'],
            'title' => $attributes['title'],
            'description' => $attributes['description'],
            'public_url' => $attributes['public_url'],
            'viewer_settings' => $attributes['viewer_settings'],
            'property' => $tour->property === null ? null : [
                'name' => $tour->property->name,
                'location' => $tour->property->locationLabel(),
            ],
            'cover_url' => $attributes['cover_url'],
            'scenes' => $scenes,
            'branding' => $this->publicBranding($tour),
            'audio' => $this->publicAudio($tour->audio()),
            'lead' => $this->publicLeadForm($tour),
            'map' => $this->publicMap($tour, $visible),
            'floorplans' => $this->floorplans($tour, $visible),
            'analytics_enabled' => $tour->analyticsEnabled(),
        ];
    }

    /**
     * Media referenced by settings (logo, audio), keyed by ULID.
     *
     * @var array<string, MediaAsset>
     */
    protected array $assets = [];

    /**
     * @return array<string, MediaAsset>
     */
    protected function assetsFor(VirtualTour $tour): array
    {
        $ids = array_filter([
            $tour->branding()['logo_asset_id'] ?? null,
            $tour->audio()['background_asset_id'] ?? null,
            ...$tour->scenes->map(fn (TourScene $scene) => $scene->audio_settings['asset_id'] ?? null)->all(),
        ]);

        if ($ids === []) {
            return [];
        }

        return MediaAsset::where('workspace_id', $tour->workspace_id)
            ->whereIn('ulid', array_unique($ids))
            ->get()
            ->keyBy('ulid')
            ->all();
    }

    protected function assetUrl(?string $ulid, bool $thumbnail = false): ?string
    {
        $asset = $ulid ? ($this->assets[$ulid] ?? null) : null;

        if (! $asset) {
            return null;
        }

        return $thumbnail && $asset->isImage()
            ? $this->storage->scaledImageUrl($asset->cloudinary_public_id, 400)
            : $asset->secure_url;
    }

    /**
     * @return array<string, mixed>
     */
    protected function publicBranding(VirtualTour $tour): array
    {
        $branding = $tour->branding();

        return [
            // The tour's own logo, else the workspace brand logo.
            'logo_url' => $this->assetUrl($branding['logo_asset_id'], thumbnail: true)
                ?? app(BrandPresenter::class)->forWorkspace($tour->workspace)['logo_url'],
            'primary_color' => $branding['primary_color'],
            'accent_color' => $branding['accent_color'],
            // A downgraded plan brings the badge back even if it was switched off.
            'show_platform_branding' => (bool) $branding['show_platform_branding']
                || ! $this->entitlements->allows($tour->workspace_id, Entitlement::RemoveBranding),
            'intro' => $branding['intro'],
            'contact' => $this->visitorContact($branding['contact']),
        ];
    }

    /**
     * @param  array<string, mixed>  $audio
     * @return array{url: string, volume: int, loop: bool}|null
     */
    protected function publicAudio(array $audio): ?array
    {
        $url = $this->assetUrl($audio['background_asset_id'] ?? null);

        return $url === null ? null : [
            'url' => $url,
            'volume' => (int) $audio['volume'],
            'loop' => (bool) $audio['loop'],
        ];
    }

    /**
     * Lead form settings safe for visitors (no notification details).
     *
     * @return array<string, mixed>|null
     */
    protected function publicLeadForm(VirtualTour $tour): ?array
    {
        $settings = $tour->leadSettings();

        if (! $settings['enabled']) {
            return null;
        }

        return [
            'title' => $settings['title'],
            'button_label' => $settings['button_label'],
            'require_phone' => (bool) $settings['require_phone'],
            'thank_you' => $settings['thank_you'],
            'submit_url' => route('tours.public.leads', ['publicId' => $tour->public_id]),
            'form_token' => Crypt::encryptString((string) now()->getTimestamp()),
        ];
    }

    /**
     * A tour can show a map when the property has coordinates or at least
     * one scene has been pinned.
     */
    protected function hasMapLocation(VirtualTour $tour): bool
    {
        $property = $tour->property;

        return ($property !== null && $property->latitude !== null && $property->longitude !== null)
            || $tour->scenes->contains(fn (TourScene $scene): bool => $scene->latitude !== null && $scene->longitude !== null);
    }

    /**
     * @param  Collection<int, TourScene>  $visible  Scenes visitors can reach.
     * @return array{latitude: float, longitude: float, label: string|null, property: array{latitude: float, longitude: float}|null, pins: list<array{scene_id: string, title: string, latitude: float, longitude: float, thumbnail_url: string|null}>}|null
     */
    protected function publicMap(VirtualTour $tour, Collection $visible): ?array
    {
        if (! $tour->mapSettings()['enabled'] || ! $this->hasMapLocation($tour)) {
            return null;
        }

        $property = $tour->property;
        $propertyLocation = $property !== null && $property->latitude !== null && $property->longitude !== null
            ? ['latitude' => (float) $property->latitude, 'longitude' => (float) $property->longitude]
            : null;

        $pins = array_values($visible
            ->filter(fn (TourScene $scene): bool => $scene->latitude !== null && $scene->longitude !== null)
            ->map(fn (TourScene $scene): array => [
                'scene_id' => $scene->ulid,
                'title' => $scene->title,
                'latitude' => (float) $scene->latitude,
                'longitude' => (float) $scene->longitude,
                'thumbnail_url' => $scene->mediaAsset->thumbnail_url,
            ])
            ->all());

        // Centre on the property, else on the middle of the pins.
        $centre = $propertyLocation ?? [
            'latitude' => array_sum(array_column($pins, 'latitude')) / max(1, count($pins)),
            'longitude' => array_sum(array_column($pins, 'longitude')) / max(1, count($pins)),
        ];

        return [
            ...$centre,
            'label' => $property?->locationLabel() ?? $property?->name,
            'property' => $propertyLocation,
            'pins' => $pins,
        ];
    }

    /**
     * @param  Collection<int, TourScene>  $scenes  Scenes allowed to appear as markers.
     * @return list<array<string, mixed>>
     */
    protected function floorplans(VirtualTour $tour, Collection $scenes): array
    {
        $ulidsById = $scenes->pluck('ulid', 'id');

        return array_values($tour->floorplans->map(fn (TourFloorplan $floorplan): array => [
            'id' => $floorplan->ulid,
            'title' => $floorplan->title,
            'media_id' => $floorplan->mediaAsset->ulid,
            'image_url' => $this->storage->scaledImageUrl($floorplan->mediaAsset->cloudinary_public_id, 1600),
            'markers' => array_values($floorplan->markers
                ->filter(fn (TourFloorplanMarker $marker): bool => $ulidsById->has($marker->tour_scene_id))
                ->map(fn (TourFloorplanMarker $marker): array => [
                    'scene_id' => $ulidsById[$marker->tour_scene_id],
                    'x' => (float) $marker->x,
                    'y' => (float) $marker->y,
                ])
                ->all()),
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function tourAttributes(VirtualTour $tour): array
    {
        $firstScene = $tour->scenes->first();

        return [
            'id' => $tour->ulid,
            'public_id' => $tour->public_id,
            'title' => $tour->title,
            'slug' => $tour->slug,
            'description' => $tour->description,
            'status' => $tour->status->value,
            'visibility' => $tour->visibility->value,
            'published_at' => $tour->published_at?->toIso8601String(),
            'updated_at' => $tour->updated_at?->toIso8601String(),
            'property' => $tour->property === null ? null : [
                'id' => $tour->property->ulid,
                'name' => $tour->property->name,
            ],
            'cover_url' => $tour->coverAsset->thumbnail_url ?? $firstScene?->mediaAsset->thumbnail_url,
            'viewer_settings' => $tour->viewerSettings(),
            'allowed_embed_domains' => $tour->allowedEmbedDomains(),
            'public_url' => app(PublicUrl::class)->tour($tour),
        ];
    }

    /**
     * @param  Collection<int, TourScene>  $scenes
     * @return list<array<string, mixed>>
     */
    protected function scenes(Collection $scenes): array
    {
        $ulidsById = $scenes->pluck('ulid', 'id');

        return array_values($scenes->map(fn (TourScene $scene): array => [
            'id' => $scene->ulid,
            'title' => $scene->title,
            'description' => $scene->description,
            'sort_order' => $scene->sort_order,
            'is_visible' => $scene->is_visible,
            'location' => $scene->latitude === null || $scene->longitude === null
                ? null
                : ['latitude' => (float) $scene->latitude, 'longitude' => (float) $scene->longitude],
            'initial_view' => [
                'yaw' => (float) $scene->initial_yaw,
                'pitch' => (float) $scene->initial_pitch,
                'zoom' => $scene->initial_zoom,
            ],
            'media' => $this->media($scene->mediaAsset),
            'audio' => $this->sceneAudio($scene),
            'hotspots' => $scene->hotspots
                ->map(fn (TourHotspot $hotspot): array => $this->hotspot($hotspot, $ulidsById))
                ->values()
                ->all(),
        ])->all());
    }

    /**
     * @return array{asset_id: string|null, name: string|null, url: string|null, volume: int, loop: bool, pause_background: bool}
     */
    protected function sceneAudio(TourScene $scene): array
    {
        $settings = $scene->audio_settings ?? [];
        $assetId = $settings['asset_id'] ?? null;

        return [
            'asset_id' => $assetId,
            'name' => $assetId ? ($this->assets[$assetId]->original_filename ?? null) : null,
            'url' => $this->assetUrl($assetId),
            'volume' => (int) ($settings['volume'] ?? 80),
            'loop' => (bool) ($settings['loop'] ?? true),
            'pause_background' => (bool) ($settings['pause_background'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function media(MediaAsset $asset): array
    {
        return [
            'id' => $asset->ulid,
            'filename' => $asset->original_filename,
            'thumbnail_url' => $asset->thumbnail_url,
            'width' => $asset->width,
            'height' => $asset->height,
            'is_equirectangular' => $asset->isEquirectangular(),
            'panorama_url' => $this->storage->scaledImageUrl($asset->cloudinary_public_id, $this->desktopWidth($asset)),
            'panorama_mobile_url' => $this->storage->scaledImageUrl($asset->cloudinary_public_id, self::MOBILE_WIDTH),
            'panorama_preview_url' => $this->storage->scaledImageUrl($asset->cloudinary_public_id, self::PREVIEW_WIDTH),
            'tiles' => $this->tiles($asset),
        ];
    }

    /**
     * Contact details for visitors: only when switched on and at least one field is filled.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, string|null>|null
     */
    protected function visitorContact(array $contact): ?array
    {
        $details = array_diff_key($contact, ['enabled' => true]);

        if (! ($contact['enabled'] ?? false) || array_filter($details) === []) {
            return null;
        }

        return $details;
    }

    /**
     * The widest full-size version to serve. Cloudinary refuses to resize into
     * more than about 25 megapixels, so a very large image is capped by its
     * pixel count rather than by width alone.
     */
    public function desktopWidth(MediaAsset $asset): int
    {
        if (! $asset->width || ! $asset->height) {
            return self::DESKTOP_WIDTH;
        }

        $byPixels = (int) floor(sqrt(self::MAX_PIXELS * $asset->width / $asset->height));

        return max(1, min(self::DESKTOP_WIDTH, $byPixels));
    }

    /**
     * Tile layout for panoramas wider than the largest single image the
     * viewer loads, so they keep their full sharpness. Null for everything else.
     *
     * @return array{width: int, height: int, cols: int, rows: int, url_template: string}|null
     */
    protected function tiles(MediaAsset $asset): ?array
    {
        $width = (int) $asset->width;
        $height = (int) $asset->height;

        if (! $asset->isEquirectangular() || $width <= self::DESKTOP_WIDTH || $height < 1) {
            return null;
        }

        // About 512 px tiles, in the power-of-two grid the viewer requires (at most 64 across).
        $cols = min(64, 2 ** (int) floor(log($width / 512, 2)));

        return [
            'width' => $width,
            'height' => $height,
            'cols' => $cols,
            'rows' => intdiv($cols, 2),
            'url_template' => $this->storage->tileUrlTemplate($asset->cloudinary_public_id),
        ];
    }

    /**
     * The image or video a media hotspot shows.
     *
     * @return array{id: string, kind: string, url: string, thumbnail_url: string|null, name: string|null}|null
     */
    protected function hotspotMedia(TourHotspot $hotspot): ?array
    {
        $asset = $hotspot->mediaAsset;

        if ($asset === null) {
            return null;
        }

        $isVideo = $asset->asset_type === AssetType::Video;

        return [
            'id' => $asset->ulid,
            'kind' => $isVideo ? 'video' : 'image',
            'url' => $isVideo ? $asset->secure_url : $this->storage->scaledImageUrl($asset->cloudinary_public_id, 1600),
            'thumbnail_url' => $asset->thumbnail_url,
            'name' => $asset->original_filename,
        ];
    }

    /**
     * @param  Collection<int, string>  $ulidsById
     * @return array<string, mixed>
     */
    protected function hotspot(TourHotspot $hotspot, Collection $ulidsById): array
    {
        return [
            'id' => $hotspot->ulid,
            'type' => $hotspot->type->value,
            'yaw' => (float) $hotspot->yaw,
            'pitch' => (float) $hotspot->pitch,
            'title' => $hotspot->title,
            'description' => $hotspot->description,
            'external_url' => $hotspot->external_url,
            'target_scene_id' => $hotspot->target_scene_id === null ? null : $ulidsById->get($hotspot->target_scene_id),
            'media' => $this->hotspotMedia($hotspot),
            'settings' => [
                'open_in_new_tab' => (bool) ($hotspot->settings['open_in_new_tab'] ?? true),
                'placement' => $hotspot->settings['placement'] ?? 'sphere',
                'size' => (int) ($hotspot->settings['size'] ?? 36),
                // null means the colour that goes with the pin's type.
                'color' => $hotspot->settings['color'] ?? null,
                'opacity' => (float) ($hotspot->settings['opacity'] ?? 1),
                'rotation' => (int) ($hotspot->settings['rotation'] ?? 0),
                'label_mode' => $hotspot->settings['label_mode'] ?? 'always',
                'icon' => $hotspot->settings['icon'] ?? null,
            ],
        ];
    }
}
