<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TourStatus;
use App\Http\Controllers\Controller;
use App\Models\TourReport;
use App\Services\Domains\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visitor reports about public tours, for platform administrators to review.
 */
class TourReportController extends Controller
{
    public function index(Request $request): Response
    {
        $show = $request->string('show')->toString() === 'resolved' ? 'resolved' : 'open';

        $reports = TourReport::query()
            ->with('tour.workspace:id,ulid,name')
            ->when($show === 'open', fn ($query) => $query->whereNull('resolved_at'), fn ($query) => $query->whereNotNull('resolved_at'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (TourReport $report): array => [
                'id' => $report->ulid,
                'reason' => TourReport::REASONS[$report->reason] ?? $report->reason,
                'details' => $report->details,
                'tour' => $report->tour->title,
                'workspace' => ['id' => $report->tour->workspace->ulid, 'name' => $report->tour->workspace->name],
                'public_url' => $report->tour->status === TourStatus::Published ? app(PublicUrl::class)->tour($report->tour) : null,
                'created_at' => $report->created_at?->toIso8601String(),
                'resolved_at' => $report->resolved_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/Reports', [
            'reports' => $reports,
            'filters' => ['show' => $show],
            'openCount' => TourReport::whereNull('resolved_at')->count(),
        ]);
    }

    public function update(Request $request, TourReport $report): RedirectResponse
    {
        $report->forceFill(['resolved_at' => $request->boolean('resolved') ? now() : null])->save();

        return back();
    }
}
