<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiPresenter;
use App\Services\Api\WorkspaceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyApiController extends Controller
{
    public function __construct(protected WorkspaceData $data, protected ApiPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->data
            ->properties((int) $request->user()->current_workspace_id, $filters['search'] ?? null)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn ($property) => $this->presenter->property($property)));
    }

    public function show(Request $request, string $property): JsonResponse
    {
        $found = $this->data->property((int) $request->user()->current_workspace_id, $property);

        abort_if($found === null, 404);

        return response()->json(['data' => $this->presenter->property($found)]);
    }
}
