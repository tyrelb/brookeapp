<?php

namespace App\Livewire\Clients;

use App\Actions\PostAdjustment;
use App\Actions\PostMonthlyFee;
use App\Actions\RecordPayment;
use App\Actions\UpdateTransaction;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Mail\WalletLinkMail;
use App\Models\Client;
use App\Models\WalletTransaction;
use App\Models\WalletTransactionRevision;
use Flux\Flux;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    public Client $client;

    // Record payment modal
    public string $paymentAmount = '';

    public string $paymentMethod = '';

    public string $paymentDate = '';

    public string $paymentReference = '';

    public string $paymentNote = '';

    // Adjustment / refund modal
    public string $adjustmentKind = 'credit'; // credit | debit | refund

    public string $adjustmentAmount = '';

    public string $adjustmentNote = '';

    public string $adjustmentDate = '';

    public string $adjustmentMethod = '';

    // Edit transaction modal
    public ?int $editingId = null;

    public string $editAmount = '';

    public string $editKind = 'credit'; // adjustments only: credit | debit

    public string $editDate = '';

    public string $editMethod = '';

    public string $editReference = '';

    public string $editDescription = '';

    public string $editReason = '';

    // History modal
    public ?int $historyId = null;

    public function mount(Client $client): void
    {
        $this->authorize('view', $client);
        $this->client = $client;
        $this->paymentDate = today()->toDateString();
        $this->adjustmentDate = today()->toDateString();
        $this->paymentMethod = auth()->user()->enabledPaymentMethods()[0]->value ?? '';
        $this->adjustmentMethod = $this->paymentMethod;
    }

    public function recordPayment(RecordPayment $recordPayment): void
    {
        $this->authorize('update', $this->client);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'paymentDate' => ['required', 'date'],
            'paymentReference' => ['nullable', 'string', 'max:100'],
            'paymentNote' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $recordPayment->handle(
                $this->client,
                (float) $this->paymentAmount,
                PaymentMethod::from($this->paymentMethod),
                $this->paymentDate,
                $this->paymentReference,
                $this->paymentNote,
            );
        } catch (BillingException $e) {
            $this->addError('paymentAmount', $e->getMessage());

            return;
        }

        $this->reset('paymentAmount', 'paymentReference', 'paymentNote');
        $this->paymentDate = today()->toDateString();
        Flux::modal('record-payment')->close();
        Flux::toast('Payment recorded.', variant: 'success');
    }

    public function postAdjustment(PostAdjustment $postAdjustment): void
    {
        $this->authorize('update', $this->client);

        $this->validate([
            'adjustmentKind' => ['required', Rule::in(['credit', 'debit', 'refund'])],
            'adjustmentAmount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'adjustmentNote' => ['required', 'string', 'max:255'],
            'adjustmentDate' => ['required', 'date'],
            'adjustmentMethod' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $amount = (float) $this->adjustmentAmount;

        try {
            match ($this->adjustmentKind) {
                'credit' => $postAdjustment->handle($this->client, $amount, $this->adjustmentNote, TransactionType::Adjustment, $this->adjustmentDate),
                'debit' => $postAdjustment->handle($this->client, -$amount, $this->adjustmentNote, TransactionType::Adjustment, $this->adjustmentDate),
                'refund' => $postAdjustment->handle(
                    $this->client, $amount, $this->adjustmentNote, TransactionType::Refund, $this->adjustmentDate,
                    $this->adjustmentMethod ? PaymentMethod::from($this->adjustmentMethod) : null,
                ),
            };
        } catch (BillingException $e) {
            $this->addError('adjustmentAmount', $e->getMessage());

            return;
        }

        $this->reset('adjustmentAmount', 'adjustmentNote');
        $this->adjustmentKind = 'credit';
        $this->adjustmentDate = today()->toDateString();
        Flux::modal('post-adjustment')->close();
        Flux::toast('Adjustment posted.', variant: 'success');
    }

    public function postMonthlyFee(PostMonthlyFee $postMonthlyFee): void
    {
        $this->authorize('update', $this->client);

        try {
            $transaction = $postMonthlyFee->handle($this->client, today());
        } catch (BillingException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast(
            $transaction->wasRecentlyCreated
                ? "Posted {$transaction->description}."
                : "This month's fee was already posted on {$transaction->transacted_on->format('M j')}.",
            variant: $transaction->wasRecentlyCreated ? 'success' : 'warning',
        );
    }

    public function emailWalletLink(): void
    {
        $this->authorize('update', $this->client);

        if (! $this->client->email) {
            Flux::toast('Add an email address for this client first.', variant: 'warning');

            return;
        }

        $this->client->loadMissing('trainer');
        Mail::to($this->client->email, $this->client->full_name)->queue(new WalletLinkMail($this->client));
        Flux::toast("Fitness Wallet link emailed to {$this->client->email}.", variant: 'success');
    }

    public function resetWalletLink(): void
    {
        $this->authorize('update', $this->client);
        $this->client->regeneratePortalToken();
        Flux::toast('New link created. The old link no longer works.', variant: 'success');
    }

    public function voidTransaction(int $transactionId): void
    {
        $this->authorize('update', $this->client);

        $transaction = $this->transaction($transactionId);

        if ($transaction->type === TransactionType::SessionCharge) {
            Flux::toast('Session charges are voided by reopening the session.', variant: 'warning');

            return;
        }

        if ($transaction->isVoided()) {
            return;
        }

        $transaction->update(['voided_at' => now()]);
        $transaction->recordRevision(WalletTransactionRevision::ACTION_VOIDED);
        Flux::toast('Transaction voided.', variant: 'success');
    }

    public function editTransaction(int $transactionId): void
    {
        $this->authorize('update', $this->client);

        $transaction = $this->transaction($transactionId);

        if (! $transaction->isEditable()) {
            Flux::toast(
                $transaction->isVoided()
                    ? 'Voided entries cannot be edited.'
                    : 'Session charges are corrected by reopening the session.',
                variant: 'warning',
            );

            return;
        }

        $this->resetValidation();
        $this->editingId = $transaction->id;
        // Monthly fees are entered before GST; everything else is entered as the amount that moved.
        $this->editAmount = (string) ($transaction->type === TransactionType::MonthlyFee
            ? (float) $transaction->subtotal
            : abs((float) $transaction->amount));
        $this->editKind = (float) $transaction->amount < 0 ? 'debit' : 'credit';
        $this->editDate = $transaction->transacted_on->toDateString();
        $this->editMethod = $transaction->payment_method?->value ?? '';
        $this->editReference = (string) $transaction->reference;
        $this->editDescription = (string) $transaction->description;
        $this->editReason = '';

        Flux::modal('edit-transaction')->show();
    }

    public function updateTransaction(UpdateTransaction $updateTransaction): void
    {
        $this->authorize('update', $this->client);

        $transaction = $this->transaction((int) $this->editingId);

        $this->validate([
            'editAmount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'editDate' => ['required', 'date'],
            'editMethod' => ['nullable', Rule::enum(PaymentMethod::class)],
            'editReference' => ['nullable', 'string', 'max:100'],
            'editDescription' => ['nullable', 'string', 'max:255'],
            'editReason' => ['required', 'string', 'max:255'],
        ]);

        $amount = (float) $this->editAmount;

        if ($transaction->type === TransactionType::Adjustment && $this->editKind === 'debit') {
            $amount = -$amount;
        }

        try {
            $updateTransaction->handle(
                $transaction,
                $amount,
                $this->editDate,
                $this->editReason,
                $this->editDescription,
                $this->editMethod ? PaymentMethod::from($this->editMethod) : null,
                $this->editReference,
            );
        } catch (BillingException $e) {
            $this->addError('editAmount', $e->getMessage());

            return;
        }

        $this->editingId = null;
        $this->reset('editAmount', 'editReference', 'editDescription', 'editReason');
        Flux::modal('edit-transaction')->close();
        Flux::toast('Transaction updated.', variant: 'success');
    }

    public function showHistory(int $transactionId): void
    {
        $this->authorize('view', $this->client);

        $this->historyId = $this->transaction($transactionId)->id;
        Flux::modal('transaction-history')->show();
    }

    private function transaction(int $transactionId): WalletTransaction
    {
        return WalletTransaction::query()
            ->where('client_id', $this->client->id)
            ->findOrFail($transactionId);
    }

    public function render()
    {
        $this->client->load(['plan', 'gym', 'activeMembers']);

        return view('livewire.clients.show', [
            'balance' => $this->client->balance(),
            'transactions' => $this->client->transactions()
                ->with('trainingSession.service')
                ->withCount(['revisions as edit_count' => fn ($q) => $q->where('action', '!=', WalletTransactionRevision::ACTION_CREATED)])
                ->limit(200)
                ->get(),
            'editing' => $this->editingId ? $this->transaction($this->editingId) : null,
            'history' => $this->historyId
                ? $this->transaction($this->historyId)->load('revisions.changedBy')
                : null,
            'attendances' => $this->client->attendances()
                ->with('trainingSession.service', 'trainingSession.attendees')
                ->get()
                ->sortByDesc(fn ($a) => $a->trainingSession->starts_at)
                ->values(),
            'paymentMethods' => auth()->user()->enabledPaymentMethods(),
            'walletUrl' => $this->client->portalUrl(),
        ])->title($this->client->full_name);
    }
}
