<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\Credits\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public const TYPES = ['grant', 'reserve', 'charge', 'release'];

    public function index(Request $request): Response
    {
        $type = $request->string('type')->toString();

        $transactions = CreditTransaction::query()
            ->with(['user:id,name', 'workspace:id,ulid,name'])
            ->when(in_array($type, self::TYPES, true), fn ($query) => $query->where('transaction_type', $type))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (CreditTransaction $transaction): array => self::transaction($transaction, withWorkspace: true));

        return Inertia::render('admin/Credits', [
            'transactions' => $transactions,
            'types' => self::TYPES,
            'filters' => ['type' => $type],
        ]);
    }

    /**
     * Add credits to a workspace (support, refunds, promotions).
     */
    public function store(Request $request, Workspace $workspace, CreditService $credits, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'between:1,100000'],
            'reason' => ['required', 'string', 'max:160'],
        ]);

        /** @var User $admin */
        $admin = $request->user();
        $credits->grant($workspace->id, $data['amount'], 'Admin: '.$data['reason'], $admin);
        $activity->log($workspace->id, $admin, 'credits.granted', "Admin granted {$data['amount']} credits", $workspace, ['reason' => $data['reason']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :amount credits.', ['amount' => $data['amount']])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function transaction(CreditTransaction $transaction, bool $withWorkspace = false): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->transaction_type,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'description' => $transaction->description,
            'user' => $transaction->user?->name,
            'workspace' => $withWorkspace
                ? ['id' => $transaction->workspace->ulid, 'name' => $transaction->workspace->name]
                : null,
            'created_at' => $transaction->created_at->toIso8601String(),
        ];
    }
}
