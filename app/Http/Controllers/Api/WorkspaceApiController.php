<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Api\WorkspaceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceApiController extends Controller
{
    public function __construct(protected WorkspaceData $data) {}

    /**
     * The workspace the token belongs to, with its available AI credits.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->user()->currentWorkspace;

        return response()->json(['data' => $this->data->workspace($workspace)]);
    }
}
