<?php

namespace App\Livewire\Reports;

use App\Services\ReportBuilder;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Monthly report')]
class Monthly extends Component
{
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if ($this->month === '' || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
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

    public function render(ReportBuilder $reports)
    {
        [$year, $month] = array_map('intval', explode('-', $this->month));

        return view('livewire.reports.monthly', [
            'report' => $reports->monthly(auth()->user(), $year, $month),
            'gstRegistered' => auth()->user()->gst_registered,
        ]);
    }
}
