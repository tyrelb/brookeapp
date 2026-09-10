<?php

namespace Database\Seeders;

use App\Actions\BookSessionSeries;
use App\Actions\CompleteTrainingSession;
use App\Actions\PostMonthlyFee;
use App\Actions\RecordPayment;
use App\Enums\GymBillingModel;
use App\Enums\PaymentMethod;
use App\Enums\PlanType;
use App\Enums\SessionStatus;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Plan;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Local demo data: one trainer (Brooke) with services, plans, clients and a couple of
 * months of sessions and payments so the dashboard and reports have something to show.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $brooke = User::factory()->create([
            'name' => 'Brooke',
            'email' => 'brooke@example.com',
            'password' => 'password',
            'business_name' => 'Brooke Fitness',
            'phone' => '604-555-0199',
            'gst_number' => '123456789 RT0001',
            'gst_registered' => true,
            'gst_rate' => 5.00,
            'payment_methods' => ['cash', 'cheque', 'etransfer'],
            'etransfer_email' => 'brooke@example.com',
            'booking_instructions' => 'To book or change a session, text Brooke at 604-555-0199 or reply to this email.',
        ]);

        $pt = Service::create(['user_id' => $brooke->id, 'name' => 'Personal Training (60 min)', 'duration_minutes' => 60]);
        $group = Service::create(['user_id' => $brooke->id, 'name' => 'Small Group Strength (45 min)', 'duration_minutes' => 45]);

        $standard = Plan::create(['user_id' => $brooke->id, 'name' => 'Standard pay-as-you-go', 'type' => PlanType::Wallet, 'description' => 'Deposit into your Fitness Wallet; sessions are deducted per person.']);
        $this->rates($standard, $pt, [1 => 60, 2 => 30, 3 => 25, 4 => 20]);
        $this->rates($standard, $group, [1 => 45, 2 => 25, 3 => 20, 4 => 15]);

        $legacy = Plan::create(['user_id' => $brooke->id, 'name' => 'Legacy 2023 rate', 'type' => PlanType::Wallet, 'description' => 'Long-time clients only.']);
        $this->rates($legacy, $pt, [1 => 50, 2 => 28, 3 => 22, 4 => 18]);
        $this->rates($legacy, $group, [1 => 40, 2 => 22]);

        $family = Plan::create([
            'user_id' => $brooke->id,
            'name' => 'Family pay-as-you-go',
            'type' => PlanType::Family,
            'description' => 'One wallet for the whole household; each member who trains is charged.',
        ]);
        $this->rates($family, $pt, [1 => 60, 2 => 45, 3 => 25, 4 => 22]);
        $this->rates($family, $group, [1 => 45, 2 => 30, 3 => 22, 4 => 18]);

        $monthly = Plan::create(['user_id' => $brooke->id, 'name' => 'Monthly Unlimited', 'type' => PlanType::Monthly, 'monthly_fee' => 300, 'billing_day' => 1, 'description' => 'Flat fee, train as often as you like.']);

        $clients = collect([
            ['Ava', 'Nguyen', $standard, 400, PaymentMethod::ETransfer],
            ['Ben', 'Okafor', $standard, 200, PaymentMethod::Cash],
            ['Chloe', 'Martin', $standard, 300, PaymentMethod::ETransfer],
            ['Dev', 'Patel', $legacy, 250, PaymentMethod::Cheque],
            ['Elena', 'Rossi', $legacy, 100, PaymentMethod::Cash],
            ['Finn', 'Gallagher', $monthly, null, null],
            ['Grace', 'Kim', $monthly, null, null],
            ['Hana', 'Sato', $standard, 60, PaymentMethod::ETransfer],
        ])->map(function (array $row) use ($brooke) {
            [$first, $last, $plan, $deposit, $method] = $row;

            $client = Client::create([
                'user_id' => $brooke->id,
                'plan_id' => $plan->id,
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower("{$first}.{$last}@example.com"),
                'phone' => '604-555-01'.str_pad((string) random_int(10, 99), 2, '0'),
                'started_at' => now()->subMonths(3)->startOfMonth()->toDateString(),
            ]);

            if ($deposit) {
                app(RecordPayment::class)->handle($client, $deposit, $method, now()->subMonths(2)->startOfMonth()->addDays(2));
            }

            return $client;
        })->keyBy('first_name');

        // A household on one wallet: the trainer ticks who turned up for each session.
        $barnes = Client::create([
            'user_id' => $brooke->id,
            'plan_id' => $family->id,
            'first_name' => 'Barnes',
            'last_name' => 'Family',
            'email' => 'barnes.family@example.com',
            'phone' => '604-555-0177',
            'started_at' => now()->subMonths(3)->startOfMonth()->toDateString(),
        ]);

        foreach (['Mom (Sarah)', 'Dad (Tom)', 'Ellie', 'Sam', 'Alex'] as $order => $memberName) {
            $barnes->members()->create([
                'user_id' => $brooke->id,
                'name' => $memberName,
                'sort_order' => $order,
            ]);
        }

        app(RecordPayment::class)->handle($barnes, 900, PaymentMethod::ETransfer, now()->subMonths(2)->startOfMonth()->addDays(2));

        // Monthly fees for the last two months and this month, paid for the earlier ones.
        foreach ([2, 1, 0] as $monthsAgo) {
            $period = now()->subMonths($monthsAgo)->startOfMonth();

            foreach (['Finn', 'Grace'] as $name) {
                $fee = app(PostMonthlyFee::class)->handle($clients[$name], $period);

                if ($monthsAgo > 0 || $name === 'Finn') {
                    app(RecordPayment::class)->handle($clients[$name], abs((float) $fee->amount), PaymentMethod::ETransfer, $period->copy()->addDays(3));
                }
            }
        }

        // Six weeks of completed sessions: a rotating mix of singles, partners and triples.
        $schedule = [
            [['Ava'], $pt], [['Ben', 'Chloe'], $pt], [['Finn'], $pt], [['Dev', 'Elena', 'Grace'], $group],
            [['Hana'], $pt], [['Ava', 'Ben'], $pt], [['Chloe', 'Finn', 'Grace', 'Dev'], $group], [['Elena'], $pt],
        ];

        $day = now()->subWeeks(6)->startOfWeek();
        $i = 0;

        while ($day->lt(now()->startOfDay())) {
            if ($day->isWeekday()) {
                [$names, $service] = $schedule[$i % count($schedule)];
                $hour = 7 + ($i % 4) * 2;

                $session = TrainingSession::create([
                    'user_id' => $brooke->id,
                    'service_id' => $service->id,
                    'starts_at' => $day->copy()->setTime($hour, 0),
                    'duration_minutes' => $service->duration_minutes,
                    'status' => SessionStatus::Scheduled,
                ]);

                foreach ($names as $name) {
                    $session->attendees()->create(['client_id' => $clients[$name]->id, 'attended' => true]);
                }

                app(CompleteTrainingSession::class)->handle($session, $session->starts_at->copy()->addHour());
                $i++;
            }

            $day->addDay();
        }

        // Two family sessions: everyone one week, two of them the next.
        foreach ([[10, ['Mom (Sarah)', 'Dad (Tom)', 'Ellie', 'Sam']], [3, ['Mom (Sarah)', 'Ellie']]] as [$daysAgo, $attending]) {
            $session = TrainingSession::create([
                'user_id' => $brooke->id,
                'service_id' => $group->id,
                'starts_at' => now()->subDays($daysAgo)->setTime(17, 0),
                'duration_minutes' => $group->duration_minutes,
                'status' => SessionStatus::Scheduled,
            ]);

            $attendee = $session->attendees()->create(['client_id' => $barnes->id, 'attended' => true]);
            $attendee->setRelation('client', $barnes);
            $attendee->syncMembers($barnes->members->mapWithKeys(fn ($member) => [
                $member->id => ['attended' => in_array($member->name, $attending, true), 'price_override' => null],
            ])->all());

            app(CompleteTrainingSession::class)->handle($session->fresh(), $session->starts_at->copy()->addHour());
        }

        // Top-ups so most wallets stay positive.
        app(RecordPayment::class)->handle($clients['Ava'], 300, PaymentMethod::ETransfer, now()->subWeeks(2));
        app(RecordPayment::class)->handle($clients['Chloe'], 200, PaymentMethod::Cash, now()->subWeeks(1));
        app(RecordPayment::class)->handle($clients['Dev'], 150, PaymentMethod::Cheque, now()->subDays(10), '1042');

        // A couple of upcoming scheduled sessions (Phase 2 will put these on a calendar).
        foreach ([[1, ['Ava'], $pt], [2, ['Ben', 'Hana'], $pt]] as [$daysAhead, $names, $service]) {
            $session = TrainingSession::create([
                'user_id' => $brooke->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDays($daysAhead)->setTime(9, 0),
                'duration_minutes' => $service->duration_minutes,
                'status' => SessionStatus::Scheduled,
            ]);

            foreach ($names as $name) {
                $session->attendees()->create(['client_id' => $clients[$name]->id]);
            }
        }

        // The gym Brooke rents space at: monthly rate plus per-session usage, with GST.
        $gym = Gym::create([
            'user_id' => $brooke->id,
            'name' => 'Westside Athletic Club',
            'billing_model' => GymBillingModel::MonthlyPlusUsage,
            'monthly_fee' => 150,
            'usage_rates' => Gym::DEFAULT_RATES,
            'charges_gst' => true,
            'gst_rate' => 5,
            'is_default' => true,
        ]);
        TrainingSession::query()->forTrainer($brooke)->update(['gym_id' => $gym->id]);
        Client::query()->forTrainer($brooke)->update(['gym_id' => $gym->id]);

        // A standing Tue/Thu booking for Ava for the next six weeks (a repeat with an end date).
        $firstTuesday = now()->next(Carbon::TUESDAY);
        app(BookSessionSeries::class)->handle([
            'user_id' => $brooke->id, 'service_id' => $pt->id, 'gym_id' => $gym->id,
            'starts_on' => $firstTuesday->toDateString(), 'ends_on' => $firstTuesday->copy()->addWeeks(6)->toDateString(),
            'time' => '07:00', 'duration_minutes' => 60, 'interval_weeks' => 1, 'weekdays' => [2, 4], 'notes' => null,
        ], [$clients['Ava']->id => []]);

        // Platform administrator (support + statistics; never sees trainers' billing).
        User::factory()->create([
            'name' => 'Tyrel',
            'email' => 'admin@example.com',
            'password' => 'password',
            'is_admin' => true,
        ]);

        // A second trainer proves tenancy: log in as them and Brooke's data is invisible.
        User::factory()->create([
            'name' => 'Other Trainer',
            'email' => 'other@example.com',
            'password' => 'password',
        ]);
    }

    /**
     * @param  array<int, float|int>  $tiers  headcount => per-person price
     */
    private function rates(Plan $plan, Service $service, array $tiers): void
    {
        foreach ($tiers as $headcount => $price) {
            $plan->rates()->create(['service_id' => $service->id, 'headcount' => $headcount, 'unit_price' => $price]);
        }
    }
}
