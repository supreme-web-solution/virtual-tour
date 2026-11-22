<?php

namespace App\Http\Controllers;

use App\Http\Requests\Imports\CompleteImportRequest;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\PropertyImport;
use App\Models\User;
use App\Services\Import\PropertyImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Listing imports, used by the "Link" tab of the Create tour dialog.
 * Everything answers JSON; there are no separate import pages.
 */
class PropertyImportController extends Controller
{
    public function __construct(protected PropertyImportService $imports) {}

    /**
     * Old links to the import page open the Create tour dialog instead.
     */
    public function index(): RedirectResponse
    {
        return to_route('dashboard', ['create' => 'link']);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Property::class);

        $data = $request->validate(['url' => ['required', 'string', 'max:2048', 'url:http,https']]);
        $import = $this->imports->start($this->user($request), $data['url']);

        return response()->json(['import' => $this->payload($import->refresh())], 201);
    }

    public function show(Request $request, PropertyImport $import): JsonResponse
    {
        $this->authorizeImport($this->user($request), $import);

        return response()->json(['import' => $this->payload($import)]);
    }

    /**
     * Save the property and copy the photos, without building a tour.
     */
    public function complete(CompleteImportRequest $request, PropertyImport $import): JsonResponse
    {
        $user = $this->user($request);
        $this->authorizeImport($user, $import);

        $existing = $request->validated('target') === 'existing'
            ? Property::where('workspace_id', $import->workspace_id)->where('ulid', $request->validated('property_id'))->firstOrFail()
            : null;

        $property = $this->imports->complete($import, $user, $request->details(), array_values((array) $request->validated('image_urls', [])), $existing);

        return response()->json([
            'import' => $this->payload($import->refresh()),
            'property_url' => route('properties.show', $property),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(PropertyImport $import): array
    {
        $import->loadMissing('property:id,ulid,name');

        return [
            'id' => $import->ulid,
            'url' => $import->url,
            'source_host' => $import->source_host,
            'status' => $import->status,
            'page_title' => $import->page_title,
            'error' => $import->error,
            'details' => $import->details,
            'images' => $import->images ?? [],
            'imported_count' => $import->imported_count,
            'failed_count' => $import->failed_count,
            'selected_count' => count($import->selected_images ?? []),
            'property' => $import->property === null ? null : ['id' => $import->property->ulid, 'name' => $import->property->name],
            'is_active' => $import->isActive(),
            'used_ai' => $import->ai_generation_id !== null,
            'imported_assets' => $import->property_id === null ? [] : MediaAsset::where('workspace_id', $import->workspace_id)
                ->where('metadata->import->import_id', $import->ulid)
                ->latest('id')
                ->limit(30)
                ->get(['ulid', 'thumbnail_url', 'original_filename'])
                ->map(fn (MediaAsset $asset): array => ['id' => $asset->ulid, 'thumbnail_url' => $asset->thumbnail_url, 'name' => $asset->original_filename])
                ->values(),
        ];
    }

    protected function authorizeImport(User $user, PropertyImport $import): void
    {
        abort_unless($import->workspace_id === $user->current_workspace_id && $user->roleIn($import->workspace_id) !== null, 404);
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
