<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiPresenter;
use App\Services\Api\WorkspaceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TourApiController extends Controller
{
    public function __construct(protected WorkspaceData $data, protected ApiPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->data
            ->tours((int) $request->user()->current_workspace_id)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn ($tour) => $this->presenter->tour($tour)));
    }

    public function show(Request $request, string $tour): JsonResponse
    {
        $found = $this->data->tour((int) $request->user()->current_workspace_id, $tour);

        abort_if($found === null, 404);

        return response()->json(['data' => $this->presenter->tour($found, withScenes: true)]);
    }
}
