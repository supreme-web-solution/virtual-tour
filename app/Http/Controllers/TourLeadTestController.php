<?php

namespace App\Http\Controllers;

use App\Models\TourLead;
use App\Models\VirtualTour;
use App\Notifications\NewTourLeadNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Send a test email": the enquiry email goes to whoever receives real
 * enquiries for this tour, with sample details, so mail problems show up
 * before a visitor's message is lost.
 */
class TourLeadTestController extends Controller
{
    public function __invoke(Request $request, VirtualTour $tour): JsonResponse
    {
        Gate::authorize('update', $tour);

        $recipient = $tour->leadRecipient();

        if ($recipient === null) {
            throw ValidationException::withMessages(['notify' => __('No one in this workspace can receive the email.')]);
        }

        $lead = new TourLead([
            'name' => 'Sample Visitor',
            'email' => 'visitor@example.com',
            'phone' => '+1 555 0100',
            'message' => 'This is a test enquiry. Real messages from your tour arrive in this format.',
        ]);
        $lead->setRelation('tour', $tour);

        try {
            $recipient->notifyNow(new NewTourLeadNotification($lead));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['notify' => __('The email could not be sent: :reason', ['reason' => str($exception->getMessage())->limit(160)->toString()])]);
        }

        return response()->json([
            'to' => $recipient->email,
            'delivered' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }
}
