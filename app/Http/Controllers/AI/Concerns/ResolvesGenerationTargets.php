<?php

namespace App\Http\Controllers\AI\Concerns;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Enums\Entitlement;
use App\Enums\TourVisibility;
use App\Exceptions\ProviderException;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;
use App\Services\Panorama\PanoramaStyle;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ResolvesGenerationTargets
{
    /**
     * Confirm the style exists on the provider account (and supports
     * photo-to-panorama when required).
     *
     * @throws ValidationException
     */
    protected function resolveStyle(PanoramaGenerationProvider $provider, int $styleId, bool $imageToPanorama): PanoramaStyle
    {
        if (! $provider->isConfigured()) {
            throw ValidationException::withMessages(['style_id' => __('Panorama generation is not configured. An administrator must add the Skybox API key.')]);
        }

        try {
            $styles = $provider->styles($imageToPanorama);
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages(['style_id' => $exception->getMessage()]);
        }

        foreach ($styles as $style) {
            if ($style->id === $styleId) {
                return $style;
            }
        }

        throw ValidationException::withMessages(['style_id' => $imageToPanorama
            ? __('Choose a style that supports photo-to-360 (Model 4).')
            : __('Choose one of the available styles.')]);
    }

    /**
     * The tour new scenes go into: an existing one, a new one, or none.
     *
     * Plan limits are checked for the tour and the scenes it will receive.
     *
     * @param  array{tour_mode?: string|null, tour_id?: string|null, tour_title?: string|null}  $data
     */
    protected function resolveTour(array $data, User $user, ?int $propertyId, int $scenes = 1): ?VirtualTour
    {
        $entitlements = app(EntitlementService::class);
        $workspaceId = (int) $user->current_workspace_id;

        if (($data['tour_mode'] ?? 'none') === 'new') {
            $entitlements->ensureCanAdd($workspaceId, Entitlement::Tours);
            $entitlements->ensureCanAdd($workspaceId, Entitlement::ScenesPerTour, $scenes);
        }

        $tour = match ($data['tour_mode'] ?? 'none') {
            'existing' => VirtualTour::where('workspace_id', $user->current_workspace_id)
                ->where('ulid', $data['tour_id'] ?? '')
                ->firstOrFail(),
            'new' => tap(new VirtualTour([
                'title' => ($data['tour_title'] ?? null) ?: 'AI generated tour',
                'visibility' => TourVisibility::Unlisted,
            ]), function (VirtualTour $tour) use ($user, $propertyId): void {
                $tour->workspace_id = (int) $user->current_workspace_id;
                $tour->property_id = $propertyId;
                $tour->created_by = $user->id;
                $tour->save();
            }),
            default => null,
        };

        if (($data['tour_mode'] ?? 'none') === 'existing' && $tour !== null) {
            $entitlements->ensureCanAdd($workspaceId, Entitlement::ScenesPerTour, $scenes, $tour);
        }

        return $tour;
    }

    /**
     * Validation rules for choosing the destination tour.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function tourTargetRules(User $user, bool $allowNone = true): array
    {
        return [
            'tour_mode' => ['required', 'in:'.($allowNone ? 'none,' : '').'new,existing'],
            'tour_id' => ['required_if:tour_mode,existing', 'nullable', 'string', Rule::exists('virtual_tours', 'ulid')->where('workspace_id', $user->current_workspace_id)->whereNull('deleted_at')],
            'tour_title' => ['required_if:tour_mode,new', 'nullable', 'string', 'max:160'],
        ];
    }
}
