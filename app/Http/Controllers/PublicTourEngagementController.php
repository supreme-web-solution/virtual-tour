<?php

namespace App\Http\Controllers;

use App\Enums\TourVisibility;
use App\Http\Middleware\HandleCustomDomain;
use App\Models\TourLead;
use App\Models\TourReport;
use App\Models\TourVisit;
use App\Models\VirtualTour;
use App\Notifications\NewTourLeadNotification;
use App\Services\Tours\TourAnalyticsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Unauthenticated endpoints used by the public viewer: anonymous visit
 * tracking and the contact form. These work inside third-party iframes,
 * where session cookies may be blocked, so they rely on validation, rate
 * limits and spam traps rather than CSRF tokens.
 */
class PublicTourEngagementController extends Controller
{
    /**
     * Minimum time a real visitor takes to fill in the form.
     */
    protected const MIN_FORM_SECONDS = 2;

    /**
     * A form page left open longer than this is treated as stale.
     */
    protected const MAX_FORM_SECONDS = 21600;

    public function __construct(protected TourAnalyticsService $analytics) {}

    public function startVisit(Request $request, string $publicId): JsonResponse|Response
    {
        $tour = $this->accessibleTour($request, $publicId);

        if (! $tour->analyticsEnabled()) {
            return response()->noContent();
        }

        $input = $request->validate([
            'visitor_id' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'embed' => ['boolean'],
        ]);

        $visit = $this->analytics->startVisit(
            $tour,
            $input,
            $request->userAgent(),
            $request->header('CF-IPCountry'),
            $request->getHost(),
        );

        return response()->json(['visit_id' => $visit->ulid], 201);
    }

    /**
     * Accepts batches sent with navigator.sendBeacon (text/plain JSON body).
     */
    public function events(Request $request, string $publicId): Response
    {
        $tour = $this->accessibleTour($request, $publicId);
        $payload = json_decode($request->getContent(), true);

        $data = Validator::make(is_array($payload) ? $payload : [], [
            'visit_id' => ['required', 'string', 'size:26'],
            'events' => ['present', 'array', 'max:50'],
            'events.*.type' => ['required', 'string', 'max:30'],
            'events.*.scene_id' => ['nullable', 'string', 'max:26'],
            'events.*.hotspot_id' => ['nullable', 'string', 'max:26'],
        ])->validate();

        $visit = TourVisit::where('ulid', $data['visit_id'])
            ->where('virtual_tour_id', $tour->id)
            ->where('started_at', '>', now()->subHours(12))
            ->first();

        if ($visit && $tour->analyticsEnabled()) {
            $this->analytics->record($visit, array_values($data['events']));
        }

        return response()->noContent();
    }

    public function lead(Request $request, string $publicId): JsonResponse
    {
        $tour = $this->accessibleTour($request, $publicId);
        $settings = $tour->leadSettings();

        abort_unless($settings['enabled'], 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => [$settings['require_phone'] ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'message' => ['nullable', 'string', 'max:2000'],
            'visit_id' => ['nullable', 'string', 'size:26'],
            // Spam traps: a hidden field bots fill in, and a minimum fill time.
            'hp_note' => ['nullable', 'max:0'],
            'human_events' => ['required', 'integer', 'min:1'],
            'form_token' => ['required', 'string', 'max:500'],
        ], ['hp_note.max' => __('Your message could not be sent.')]);

        if (! $this->formTokenIsValid($data['form_token'])) {
            throw ValidationException::withMessages(['message' => __('Your message could not be sent. Please try again.')]);
        }

        $visit = filled($data['visit_id'] ?? null)
            ? TourVisit::where('ulid', $data['visit_id'])->where('virtual_tour_id', $tour->id)->first()
            : null;

        $lead = new TourLead([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'] ?? null,
        ]);
        $lead->workspace_id = $tour->workspace_id;
        $lead->virtual_tour_id = $tour->id;
        $lead->property_id = $tour->property_id;
        $lead->tour_visit_id = $visit?->id;
        $lead->save();

        if ($visit) {
            $this->analytics->record($visit, [['type' => 'lead_submitted']]);
        }

        if ($settings['notify'] && $recipient = $tour->leadRecipient()) {
            $recipient->notify(new NewTourLeadNotification($lead));
        }

        return response()->json(['message' => $settings['thank_you']], 201);
    }

    /**
     * "Report this tour": a visitor flags content for the platform to review.
     */
    public function report(Request $request, string $publicId): JsonResponse
    {
        $tour = $this->accessibleTour($request, $publicId);

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(TourReport::REASONS))],
            'details' => ['nullable', 'string', 'max:1000'],
            // Spam trap: a hidden field that bots fill in.
            'website' => ['nullable', 'max:0'],
        ]);

        // Only a keyed hash of the address is kept, to ignore repeat reports.
        $reporter = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        $alreadyReported = TourReport::where('virtual_tour_id', $tour->id)
            ->where('reporter_hash', $reporter)
            ->where('created_at', '>', now()->subDay())
            ->exists();

        if (! $alreadyReported) {
            $report = new TourReport(['reason' => $data['reason'], 'details' => $data['details'] ?? null, 'reporter_hash' => $reporter]);
            $report->virtual_tour_id = $tour->id;
            $report->save();
        }

        return response()->json(['message' => __('Thank you. We will review this tour.')], 201);
    }

    /**
     * The same visibility rules as the public viewer itself.
     */
    protected function accessibleTour(Request $request, string $publicId): VirtualTour
    {
        $tour = VirtualTour::where('public_id', $publicId)->firstOrFail();

        // On a custom domain, only that workspace's content exists.
        abort_if(($domain = HandleCustomDomain::current($request)) !== null && $domain->workspace_id !== $tour->workspace_id, 404);

        abort_unless($tour->isPublished(), 404);

        if ($tour->visibility === TourVisibility::Private) {
            abort_unless($request->user()?->can('view', $tour), 404);
        }

        return $tour;
    }

    /**
     * Notify the tour's creator while they are still a member, else the workspace owner.
     */
    /**
     * The token is issued with the page and proves how long the visitor has had
     * it open, which a bot cannot fake the way it can fake a client-side timer.
     */
    protected function formTokenIsValid(string $token): bool
    {
        try {
            $issuedAt = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return false;
        }

        $age = now()->getTimestamp() - $issuedAt;

        return $age >= self::MIN_FORM_SECONDS && $age <= self::MAX_FORM_SECONDS;
    }
}
