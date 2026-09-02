<?php

namespace App\Console\Commands;

use App\Actions\PostMonthlyFee;
use App\Enums\PlanType;
use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class PostMonthlyFees extends Command
{
    protected $signature = 'billing:post-monthly-fees {--date= : Run as if today were this date (YYYY-MM-DD)}';

    protected $description = 'Post this month\'s membership fee to every active monthly client whose billing day has arrived';

    public function handle(PostMonthlyFee $postMonthlyFee): int
    {
        $today = CarbonImmutable::parse($this->option('date') ?: today());
        $posted = 0;

        User::query()->each(function (User $trainer) use ($postMonthlyFee, $today, &$posted) {
            $clients = Client::query()
                ->forTrainer($trainer)
                ->active()
                ->whereHas('plan', fn ($q) => $q->where('type', PlanType::Monthly->value)->where('active', true))
                ->with(['plan', 'trainer'])
                ->get();

            foreach ($clients as $client) {
                $billingDay = min($client->plan->billing_day ?: 1, $today->daysInMonth);
                $billingDate = $today->day($billingDay);

                if ($today->lt($billingDate)) {
                    continue; // billing day not reached yet this month
                }

                if ($client->started_at && $client->started_at->gt($billingDate)) {
                    continue; // joined after this month's billing date; first fee next month
                }

                try {
                    $transaction = $postMonthlyFee->handle($client, $today);

                    if ($transaction->wasRecentlyCreated) {
                        $posted++;
                        $this->line("Posted {$client->full_name}: {$transaction->description} ({$transaction->amount})");
                    }
                } catch (Throwable $e) {
                    $this->error("Failed for {$client->full_name}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Posted {$posted} monthly fee(s) for {$today->format('F Y')}.");

        return self::SUCCESS;
    }
}
