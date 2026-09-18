<?php

namespace App\Livewire\Invoices;

use App\Enums\TransactionType;
use App\Livewire\Concerns\ManagesInvoices;
use App\Models\Invoice;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every payment request in one place, open ones first, where the trainer can chase,
 * settle, void or delete them without hunting for the client.
 */
#[Title('Invoices')]
class Index extends Component
{
    use ManagesInvoices, WithPagination;

    public const STATUSES = [
        'open' => 'Open',
        'overdue' => 'Overdue',
        'paid' => 'Paid',
        'void' => 'Void',
        'all' => 'All',
    ];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'open';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** ManagesInvoices: any of this trainer's invoices, via the trainer scope. */
    protected function findInvoice(int $invoiceId): Invoice
    {
        return Invoice::query()->findOrFail($invoiceId);
    }

    public function render()
    {
        $status = array_key_exists($this->status, self::STATUSES) ? $this->status : 'open';

        $invoices = Invoice::query()
            ->with('client')
            ->withPaidAmount()
            ->withPaidOn()
            ->search($this->search)
            ->tap(fn (Builder $q) => match ($status) {
                'open' => $q->outstanding(),
                'overdue' => $q->overdue(),
                'paid' => $q->settled(),
                'void' => $q->voided(),
                default => $q,
            })
            // Still owed: soonest due first, so the urgent ones lead. Otherwise newest first.
            ->when(in_array($status, ['open', 'overdue'], true), fn (Builder $q) => $q
                ->orderByRaw('invoices.due_on is null')
                ->orderBy('invoices.due_on')
                ->orderBy('invoices.id'))
            ->unless(in_array($status, ['open', 'overdue'], true), fn (Builder $q) => $q
                ->orderByDesc('invoices.issued_on')
                ->orderByDesc('invoices.id'))
            ->paginate(25);

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'statuses' => self::STATUSES,
            'summary' => $this->summary(),
            'paymentMethods' => auth()->user()->enabledPaymentMethods(),
            'markPaidInvoice' => $this->markPaidInvoice(),
        ]);
    }

    /**
     * @return array{outstanding: float, open: int, overdue: float, overdueCount: int, receivedThisMonth: float}
     */
    private function summary(): array
    {
        // A trainer has tens of open invoices, not thousands: sum them in PHP rather than
        // repeat the paid-amount subquery inside an aggregate.
        $open = Invoice::query()->outstanding()->withPaidAmount()->get();
        $overdue = $open->filter(fn (Invoice $i) => $i->due_on?->isBefore(today()));

        return [
            'outstanding' => round($open->sum(fn (Invoice $i) => $i->outstandingAmount()), 2),
            'open' => $open->count(),
            'overdue' => round($overdue->sum(fn (Invoice $i) => $i->outstandingAmount()), 2),
            'overdueCount' => $overdue->count(),
            'receivedThisMonth' => round((float) WalletTransaction::query()->active()
                ->ofType(TransactionType::Payment)
                ->whereNotNull('invoice_id')
                ->inPeriod(today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString())
                ->sum('amount'), 2),
        ];
    }
}
