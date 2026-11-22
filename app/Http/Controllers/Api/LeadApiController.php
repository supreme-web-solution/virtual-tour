<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\ApiPresenter;
use App\Services\Api\WorkspaceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadApiController extends Controller
{
    public function __construct(protected WorkspaceData $data, protected ApiPresenter $presenter) {}

    /**
     * Enquiries from the tours' contact forms, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->data
            ->leads((int) $request->user()->current_workspace_id)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn ($lead) => $this->presenter->lead($lead)));
    }
}
