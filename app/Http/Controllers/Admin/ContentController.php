<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TourStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\VirtualTour;
use App\Services\Domains\PublicUrl;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view of tours and properties across every workspace.
 */
class ContentController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->string('tab')->toString() === 'properties' ? 'properties' : 'tours';
        $search = trim($request->string('q')->toString());

        $items = $tab === 'tours'
            ? VirtualTour::query()
                ->with(['workspace:id,ulid,name', 'property:id,name'])
                ->withCount('scenes')
                ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
                ->latest('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (VirtualTour $tour): array => [
                    'id' => $tour->ulid,
                    'title' => $tour->title,
                    'workspace' => ['id' => $tour->workspace->ulid, 'name' => $tour->workspace->name],
                    'property' => $tour->property?->name,
                    'scenes_count' => (int) $tour->getAttribute('scenes_count'),
                    'status' => $tour->status->value,
                    'visibility' => $tour->visibility->value,
                    'public_url' => $tour->status === TourStatus::Published
                        ? app(PublicUrl::class)->tour($tour)
                        : null,
                    'created_at' => $tour->created_at?->toIso8601String(),
                ])
            : Property::query()
                ->with('workspace:id,ulid,name')
                ->withCount('tours')
                ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                ->latest('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Property $property): array => [
                    'id' => $property->ulid,
                    'title' => $property->name,
                    'workspace' => ['id' => $property->workspace->ulid, 'name' => $property->workspace->name],
                    'location' => $property->locationLabel(),
                    'tours_count' => (int) $property->getAttribute('tours_count'),
                    'status' => $property->status->value,
                    'created_at' => $property->created_at?->toIso8601String(),
                ]);

        return Inertia::render('admin/Content', [
            'tab' => $tab,
            'items' => $items,
            'filters' => ['q' => $search],
        ]);
    }
}
