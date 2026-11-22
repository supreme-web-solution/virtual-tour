<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssetType;
use App\Http\Controllers\Controller;
use App\Services\Api\ApiPresenter;
use App\Services\Api\WorkspaceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MediaApiController extends Controller
{
    public function __construct(protected WorkspaceData $data, protected ApiPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(AssetType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->data
            ->media((int) $request->user()->current_workspace_id, $filters['type'] ?? null)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(fn ($asset) => $this->presenter->media($asset)));
    }
}
