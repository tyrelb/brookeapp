<?php

namespace App\Http\Controllers\Portal;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Scopes\TrainerScope;
use App\Models\SessionAttendee;
use App\Services\GstCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client's read-only Fitness Wallet page, opened from a magic link.
 * No login: the token in the URL is the credential, so the page only ever shows
 * that one client's own data and the trainer's contact details.
 *
 * ?view=list (default) pages through upcoming sessions, activity and history;
 * ?view=calendar&month=YYYY-MM shows a month grid.
 */
class WalletController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $client = Client::withoutGlobalScope(TrainerScope::class)
            ->with(['plan.rates', 'trainer', 'activeMembers'])
            ->where('portal_token', $token)
            ->firstOrFail();

        abort_if($client->trainer->isSuspended(), 404);

        $trainer = $client->trainer;
        $view = $request->query('view') === 'calendar' ? 'calendar' : 'list';

        $singleRate = null;
        if ($client->isOnWalletPlan()) {
            // A family's session costs one fare per member, so price the whole household —
            // otherwise the estimate overstates their runway several times over.
            $people = max(1, $client->isOnFamilyPlan() ? $client->activeMembers()->count() : 1);
            $price = $client->plan->rates->where('headcount', '<=', $people)->sortByDesc('headcount')->first()?->unit_price
                ?? $client->plan->rates->where('headcount', 1)->min('unit_price');
            $singleRate = $price !== null ? GstCalculator::totalWithGst((float) $price * $people, $trainer->effectiveGstRate()) : null;
        }

        $base = [
            'client' => $client,
            'trainer' => $trainer,
            'balance' => $client->balance(),
            'singleRate' => $singleRate,
            'view' => $view,
            'token' => $token,
        ];

        return view('portal.wallet', $base + ($view === 'calendar' ? $this->calendarData($client, $request) : $this->listData($client, $request)));
    }

    /**
     * @return array<string, mixed>
     */
    private function listData(Client $client, Request $request): array
    {
        $attendances = SessionAttendee::query()
            ->where('client_id', $client->id)
            ->whereHas('trainingSession')
            ->with(['trainingSession.service', 'trainingSession.attendees.client', 'trainingSession.series'])
            ->join('training_sessions', 'training_sessions.id', '=', 'session_attendees.training_session_id')
            ->select('session_attendees.*');

        $upcoming = (clone $attendances)
            ->where('training_sessions.status', SessionStatus::Scheduled->value)
            ->where('training_sessions.starts_at', '>=', now())
            ->orderBy('training_sessions.starts_at')
            ->simplePaginate(10, pageName: 'upcoming')
            ->withQueryString();

        $upcomingTotal = (clone $attendances)
            ->where('training_sessions.status', SessionStatus::Scheduled->value)
            ->where('training_sessions.starts_at', '>=', now())
            ->count();

        $lastUpcoming = (clone $attendances)
            ->where('training_sessions.status', SessionStatus::Scheduled->value)
            ->where('training_sessions.starts_at', '>=', now())
            ->orderByDesc('training_sessions.starts_at')
            ->first()?->trainingSession;

        $history = (clone $attendances)
            ->where(fn ($q) => $q->where('training_sessions.status', '!=', SessionStatus::Scheduled->value)->orWhere('training_sessions.starts_at', '<', now()))
            ->orderByDesc('training_sessions.starts_at')
            ->simplePaginate(20, pageName: 'history')
            ->withQueryString();

        $transactions = $client->transactions()
            ->withoutGlobalScope(TrainerScope::class)
            ->active()
            ->with('trainingSession.service')
            ->simplePaginate(25, pageName: 'activity')
            ->withQueryString();

        return [
            'upcoming' => $upcoming,
            'upcomingTotal' => $upcomingTotal,
            'lastUpcoming' => $lastUpcoming,
            'history' => $history,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calendarData(Client $client, Request $request): array
    {
        $month = $request->query('month');
        $anchor = preg_match('/^\d{4}-\d{2}$/', (string) $month) ? CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth() : CarbonImmutable::now()->startOfMonth();

        $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);
        $end = $anchor->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $sessions = SessionAttendee::query()
            ->where('client_id', $client->id)
            ->with(['trainingSession.service', 'trainingSession.attendees.client'])
            ->get()
            ->map(fn (SessionAttendee $a) => $a->trainingSession?->setRelation('myAttendance', $a))
            ->filter()
            ->filter(fn ($s) => $s->starts_at->between($start->startOfDay(), $end->endOfDay()))
            ->sortBy('starts_at')
            ->groupBy(fn ($s) => $s->starts_at->toDateString());

        $days = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $days[] = $d;
        }

        return [
            'anchor' => $anchor,
            'weeks' => array_chunk($days, 7),
            'sessionsByDay' => $sessions,
            'monthCount' => $sessions->flatten()->filter(fn ($s) => $s->starts_at->month === $anchor->month)->count(),
        ];
    }
}
