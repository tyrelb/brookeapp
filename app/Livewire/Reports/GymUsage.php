<?php

namespace App\Livewire\Reports;

use App\Actions\FinalizeGymUsageReport;
use App\Actions\ReopenGymUsageReport;
use App\Enums\SessionStatus;
use App\Models\Gym;
use App\Models\GymUsageReport;
use App\Models\TrainingSession;
use App\Services\GymUsageReportBuilder;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Gym usage report')]
class GymUsage extends Component
{
    #[Url]
    public string $month = '';

    #[Url]
    public string $gym = '';

    public function mount(): void
    {
        if ($this->month === '' || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }

        if ($this->gym === '' || ! Gym::query()->whereKey($this->gym)->exists()) {
            $this->gym = (string) (auth()->user()->defaultGym()?->id ?? Gym::query()->active()->orderBy('name')->value('id') ?? '');
        }
    }

    public function previous(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->subMonth()->format('Y-m');
    }

    public function next(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->addMonth()->format('Y-m');
    }

    public function toggleRow(int $sessionId): void
    {
        $gym = $this->currentGym();

        if (! $gym || $this->finalized($gym)) {
            return;
        }

        $session = TrainingSession::query()->where('gym_id', $gym->id)->findOrFail($sessionId);
        $this->authorize('update', $session);
        $session->update(['gym_billable' => ! $session->gym_billable]);
    }

    public function finalize(FinalizeGymUsageReport $finalize): void
    {
        $gym = $this->currentGym();

        if (! $gym) {
            return;
        }

        $this->authorize('update', $gym);
        [$year, $monthNumber] = $this->yearMonth();
        $finalize->handle($gym, $year, $monthNumber);
        Flux::toast('Report finalized. Rows are locked; reopen it to make changes.', variant: 'success');
    }

    public function reopen(ReopenGymUsageReport $reopen): void
    {
        $gym = $this->currentGym();

        if (! $gym) {
            return;
        }

        $this->authorize('update', $gym);
        $reopen->handle($gym, $this->month);
        Flux::toast('Report reopened.', variant: 'success');
    }

    public function assignUnassigned(): void
    {
        $gym = $this->currentGym();

        if (! $gym || $this->finalized($gym)) {
            return;
        }

        $this->authorize('update', $gym);
        [$from, $to] = $this->range();

        $count = TrainingSession::query()
            ->whereNull('gym_id')
            ->where('status', SessionStatus::Completed->value)
            ->whereBetween('starts_at', [$from, $to])
            ->update(['gym_id' => $gym->id]);

        Flux::toast("Assigned {$count} ".str('session')->plural($count)." to {$gym->name}.", variant: 'success');
    }

    public function exportCsv(GymUsageReportBuilder $builder): ?StreamedResponse
    {
        $gym = $this->currentGym();

        if (! $gym) {
            return null;
        }

        $report = $this->report($gym, $builder);
        $summary = $report['summary'];
        $business = auth()->user()->displayName();

        return response()->streamDownload(function () use ($report, $summary, $gym, $business) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ["{$business} — gym usage at {$gym->name}, {$report['label']}"]);
            fputcsv($out, ['Status', $this->finalized($gym) ? 'Finalized' : 'Draft']);
            fputcsv($out, []);
            fputcsv($out, ['Date', 'Time', 'Service', 'Attendees', '# of people', 'Minutes', 'Rate per hour', '$ for the session', 'Included']);
            foreach ($report['rows'] as $row) {
                // Rows snapshotted before usage went hourly carry only the flat rate, which was the charge.
                $amount = $row['amount'] ?? $row['rate'];

                fputcsv($out, [
                    $row['date'],
                    Carbon::createFromFormat('H:i', $row['time'])->format('g:i a'),
                    $row['service'],
                    implode(', ', $row['attendees']),
                    $row['people'],
                    $row['minutes'] ?? '',
                    $row['rate'] === null ? '' : number_format($row['rate'], 2, '.', ''),
                    $amount === null ? '' : number_format($amount, 2, '.', ''),
                    $row['billable'] ? 'yes' : 'no',
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Sessions included', $summary['sessions']]);
            fputcsv($out, ['Sessions excluded', $summary['sessions_excluded']]);
            fputcsv($out, ['People', $summary['people']]);
            if (isset($summary['minutes'])) {
                fputcsv($out, ['Hours', number_format($summary['minutes'] / 60, 2)]);
            }
            fputcsv($out, ['Usage charges', number_format($summary['usage_subtotal'], 2, '.', '')]);
            if ($summary['monthly_fee'] !== null) {
                fputcsv($out, ['Monthly rate', number_format($summary['monthly_fee'], 2, '.', '')]);
            }
            fputcsv($out, ['Subtotal', number_format($summary['subtotal'], 2, '.', '')]);
            fputcsv($out, ['GST', number_format($summary['gst'], 2, '.', '')]);
            fputcsv($out, ['Total', number_format($summary['total'], 2, '.', '')]);

            // Absent from months finalized before cover sessions existed.
            $coverRows = $report['cover_rows'] ?? [];

            if ($coverRows !== []) {
                fputcsv($out, []);
                fputcsv($out, ["Covering {$gym->name}'s own clients — credit to you"]);
                fputcsv($out, ['Date', 'Time', 'Service', 'Who you trained', '# of people', '$ you charge', 'Included']);
                foreach ($coverRows as $row) {
                    fputcsv($out, [
                        $row['date'],
                        Carbon::createFromFormat('H:i', $row['time'])->format('g:i a'),
                        $row['service'],
                        implode(', ', $row['names']),
                        $row['people'],
                        number_format($row['subtotal'], 2, '.', ''),
                        $row['billable'] ? 'yes' : 'no',
                    ]);
                }
                fputcsv($out, []);
                fputcsv($out, ['Cover sessions included', $summary['cover_sessions'] ?? 0]);
                fputcsv($out, ['Cover sessions excluded', $summary['cover_sessions_excluded'] ?? 0]);
                fputcsv($out, ['Cover fees', number_format($summary['cover_subtotal'] ?? 0, 2, '.', '')]);
                fputcsv($out, ['GST you charge', number_format($summary['cover_gst'] ?? 0, 2, '.', '')]);
                fputcsv($out, ['Cover credit', number_format($summary['cover_total'] ?? 0, 2, '.', '')]);
                fputcsv($out, ['Net owed to gym', number_format($summary['net_total'] ?? $summary['total'], 2, '.', '')]);
            }

            fclose($out);
        }, 'gym-usage-'.str($gym->name)->slug().'-'.$report['period'].'.csv', ['Content-Type' => 'text/csv']);
    }

    private function currentGym(): ?Gym
    {
        return $this->gym === '' ? null : Gym::query()->find((int) $this->gym);
    }

    private function finalized(Gym $gym): ?GymUsageReport
    {
        return GymUsageReport::query()->where('gym_id', $gym->id)->where('period', $this->month)->first();
    }

    /** @return array{0: int, 1: int} */
    private function yearMonth(): array
    {
        return array_map('intval', explode('-', $this->month));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(): array
    {
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /** @return array<string, mixed> */
    private function report(Gym $gym, GymUsageReportBuilder $builder): array
    {
        if ($finalized = $this->finalized($gym)) {
            return $finalized->normalizedSnapshot();
        }

        [$year, $monthNumber] = $this->yearMonth();

        return $builder->build($gym, $year, $monthNumber);
    }

    public function render(GymUsageReportBuilder $builder)
    {
        $gyms = Gym::query()->orderByDesc('active')->orderBy('name')->get();
        $gym = $this->currentGym();

        return view('livewire.reports.gym-usage', [
            'gyms' => $gyms,
            'currentGym' => $gym,
            'report' => $gym ? $this->report($gym, $builder) : null,
            'finalizedReport' => $gym ? $this->finalized($gym) : null,
            'label' => Carbon::createFromFormat('Y-m', $this->month)->format('F Y'),
        ]);
    }
}
