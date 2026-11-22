<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $status = GenerationStatus::tryFrom($request->string('status')->toString());
        $type = GenerationType::tryFrom($request->string('type')->toString());
        $provider = $request->string('provider')->toString();

        $generations = AiGeneration::query()
            ->with(['workspace:id,ulid,name', 'user:id,name'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($type, fn ($query) => $query->where('generation_type', $type))
            ->when($provider !== '', fn ($query) => $query->where('provider', $provider))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AiGeneration $generation): array => [
                'id' => $generation->ulid,
                'type' => $generation->generation_type->label(),
                'provider' => $generation->provider,
                'model' => $generation->model,
                'status' => $generation->status->value,
                'status_label' => $generation->status->label(),
                'workspace' => $generation->workspace ? ['id' => $generation->workspace->ulid, 'name' => $generation->workspace->name] : null,
                'user' => $generation->user?->name,
                'credits_reserved' => $generation->credits_reserved,
                'credits_charged' => $generation->credits_charged,
                'cost' => $generation->actual_cost !== null ? (float) $generation->actual_cost : null,
                'error' => $generation->error_message ? Str::limit($generation->error_message, 300) : null,
                'created_at' => $generation->created_at?->toIso8601String(),
                'completed_at' => $generation->completed_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/Generations', [
            'generations' => $generations,
            'statuses' => array_map(fn (GenerationStatus $case): array => ['value' => $case->value, 'label' => $case->label()], GenerationStatus::cases()),
            'types' => array_map(fn (GenerationType $case): array => ['value' => $case->value, 'label' => $case->label()], GenerationType::cases()),
            'providers' => AiGeneration::query()->distinct()->orderBy('provider')->pluck('provider')->values(),
            'filters' => ['status' => $status->value ?? '', 'type' => $type->value ?? '', 'provider' => $provider],
        ]);
    }
}
