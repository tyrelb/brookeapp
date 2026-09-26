<?php

namespace App\Livewire\Reports;

use App\Enums\PaymentMethod;
use App\Services\ReportBuilder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Annual report')]
class Annual extends Component
{
    #[Url]
    public int $year = 0;

    public function mount(): void
    {
        if ($this->year < 2000 || $this->year > 2100) {
            $this->year = (int) now()->format('Y');
        }
    }

    public function previous(): void
    {
        $this->year--;
    }

    public function next(): void
    {
        $this->year++;
    }

    public function exportCsv(ReportBuilder $reports): StreamedResponse
    {
        $report = $reports->annual(auth()->user(), $this->year);
        $business = auth()->user()->displayName();

        return response()->streamDownload(function () use ($report, $business) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [csv_text("{$business} — annual report {$report['year']}")]);
            fputcsv($out, ['Generated', now()->toDateTimeString()]);
            fputcsv($out, []);

            $headers = ['Month', 'Sessions', 'Attendances', 'Session revenue (before GST)', 'Monthly fees (before GST)', 'Gym cover fees (before GST)', 'Revenue (before GST)', 'GST charged', 'Revenue incl. GST'];
            foreach (PaymentMethod::cases() as $method) {
                $headers[] = "Received: {$method->label()}";
            }
            $headers = array_merge($headers, ['Payments received', 'Refunds', 'Net received', 'GST in payments', 'Prepaid wallet liability (month end)', 'Owing (month end)']);
            fputcsv($out, $headers);

            $rows = $report['months'];
            $rows[] = $report['totals'];

            foreach ($rows as $row) {
                $line = [
                    $row['label'],
                    $row['sessions']['count'],
                    $row['sessions']['attendances'],
                    $row['revenue']['sessions'],
                    $row['revenue']['monthly_fees'],
                    $row['revenue']['cover_fees'],
                    $row['revenue']['total'],
                    $row['revenue']['gst'],
                    $row['revenue']['total_with_gst'],
                ];
                foreach (PaymentMethod::cases() as $method) {
                    $line[] = $row['payments']['by_method'][$method->value] ?? 0;
                }
                $line = array_merge($line, [
                    $row['payments']['total'],
                    $row['payments']['refunds'],
                    $row['payments']['net'],
                    $row['payments']['gst_embedded'],
                    $row['balances']['prepaid'],
                    $row['balances']['owing'],
                ]);
                fputcsv($out, $line);
            }

            fclose($out);
        }, "brookeapp-annual-report-{$this->year}.csv", ['Content-Type' => 'text/csv']);
    }

    public function render(ReportBuilder $reports)
    {
        return view('livewire.reports.annual', [
            'report' => $reports->annual(auth()->user(), $this->year),
            'methods' => PaymentMethod::cases(),
        ]);
    }
}
