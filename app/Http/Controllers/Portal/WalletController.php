<?php

namespace App\Http\Controllers\Portal;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Scopes\TrainerScope;
use App\Services\GstCalculator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client's read-only Fitness Wallet page, opened from a magic link.
 * No login: the token in the URL is the credential, so the page only ever shows
 * that one client's own data and the trainer's contact details.
 */
class WalletController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $client = Client::withoutGlobalScope(TrainerScope::class)
            ->with(['plan.rates', 'trainer'])
            ->where('portal_token', $token)
            ->firstOrFail();

        abort_if($client->trainer->isSuspended(), 404);

        $trainer = $client->trainer;

        $transactions = $client->transactions()
            ->withoutGlobalScope(TrainerScope::class)
            ->active()
            ->with('trainingSession.service')
            ->limit(100)
            ->get();

        $attendances = $client->attendances()
            ->with(['trainingSession.service', 'trainingSession.attendees'])
            ->get()
            ->filter(fn ($a) => $a->trainingSession !== null)
            ->sortByDesc(fn ($a) => $a->trainingSession->starts_at)
            ->values();

        $upcoming = $attendances
            ->filter(fn ($a) => $a->trainingSession->status === SessionStatus::Scheduled && $a->trainingSession->starts_at->isFuture())
            ->sortBy(fn ($a) => $a->trainingSession->starts_at)
            ->values();

        $history = $attendances->filter(fn ($a) => $a->trainingSession->status !== SessionStatus::Scheduled || $a->trainingSession->starts_at->isPast());

        $singleRate = null;
        if ($client->isOnWalletPlan()) {
            $price = $client->plan->rates->where('headcount', 1)->min('unit_price');
            $singleRate = $price !== null ? GstCalculator::totalWithGst((float) $price, $trainer->effectiveGstRate()) : null;
        }

        return view('portal.wallet', [
            'client' => $client,
            'trainer' => $trainer,
            'balance' => $client->balance(),
            'transactions' => $transactions,
            'upcoming' => $upcoming,
            'history' => $history,
            'singleRate' => $singleRate,
        ]);
    }
}
