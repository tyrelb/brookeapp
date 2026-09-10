<div class="space-y-6">
    <x-page-header :title="$client->full_name">
        <x-slot:subtitle>
            @if ($client->plan)
                {{ $client->plan->name }}
                <flux:badge size="sm" class="ml-1" :color="$client->plan->isMonthly() ? 'purple' : 'teal'">{{ $client->plan->isMonthly() ? 'Monthly' : 'Fitness Wallet' }}</flux:badge>
            @else
                No plan assigned
            @endif
            <flux:badge size="sm" class="ml-1" :color="$client->isActive() ? 'green' : 'zinc'">{{ $client->status->label() }}</flux:badge>
        </x-slot:subtitle>
        <x-slot:actions>
            <flux:modal.trigger name="record-payment">
                <flux:button icon="banknotes" variant="primary">Record payment</flux:button>
            </flux:modal.trigger>
            <flux:modal.trigger name="post-adjustment">
                <flux:button icon="adjustments-horizontal">Adjustment</flux:button>
            </flux:modal.trigger>
            @if ($client->isOnMonthlyPlan())
                <flux:button icon="calendar" wire:click="postMonthlyFee" wire:confirm="Post this month's fee of {{ money($client->plan->monthly_fee) }} + GST to {{ $client->first_name }}'s account?">Post monthly fee</flux:button>
            @endif
            <flux:button :href="route('sessions.log')" icon="plus" wire:navigate>Log session</flux:button>
            <flux:button :href="route('clients.edit', $client)" icon="pencil-square" wire:navigate>Edit</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $client->isOnMonthlyPlan() ? 'Account balance' : 'Fitness Wallet balance' }}</div>
            <div class="mt-1 text-3xl font-semibold tracking-tight"><x-money :amount="$balance" /></div>
            <div class="mt-1 text-xs text-zinc-500">
                @if ($balance < 0) Amount owing @elseif ($balance > 0) Prepaid credit (GST included) @else Settled @endif
            </div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-zinc-500 dark:text-zinc-400">Contact</div>
            <div class="mt-1">{{ $client->email ?: '—' }}</div>
            <div>{{ $client->phone ?: '—' }}</div>
            <div class="mt-2 text-xs text-zinc-500">Client since {{ $client->started_at?->format('M j, Y') ?? '—' }}</div>
            @if ($client->gym)
                <div class="mt-1 text-xs text-zinc-500">Usually trains at {{ $client->gym->name }}</div>
            @endif
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-zinc-500 dark:text-zinc-400">Notes</div>
            <div class="mt-1 whitespace-pre-line">{{ $client->notes ?: '—' }}</div>
        </div>
    </div>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" x-data="{ copied: false }">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="min-w-0">
                <flux:heading>Client wallet link</flux:heading>
                <flux:subheading>{{ $client->first_name }} can open this private page any time to see their balance, sessions and bookings. No password needed.</flux:subheading>
                <div class="mt-2 truncate rounded-md bg-zinc-50 px-3 py-1.5 font-mono text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" x-ref="url">{{ $walletUrl }}</div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText($refs.url.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Copied!' : 'Copy link'">Copy link</span></flux:button>
                <flux:button size="sm" icon="envelope" wire:click="emailWalletLink" wire:confirm="Email the wallet link to {{ $client->email ?: 'this client (no email on file)' }}?">Email link</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="resetWalletLink" wire:confirm="Create a new link? The current link will stop working immediately.">Reset link</flux:button>
            </div>
        </div>
    </section>

    <section>
        <flux:heading size="lg" class="mb-3">Ledger</flux:heading>
        @if ($transactions->isEmpty())
            <x-empty-state title="No transactions yet" description="Record a deposit or log a session to start this ledger." />
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                    <flux:table.column>Method / ref</flux:table.column>
                    <flux:table.column align="end">Before GST</flux:table.column>
                    <flux:table.column align="end">GST</flux:table.column>
                    <flux:table.column align="end">Amount</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($transactions as $tx)
                        <flux:table.row :key="$tx->id" class="{{ $tx->isVoided() ? 'opacity-50 line-through' : '' }}">
                            <flux:table.cell>{{ $tx->transacted_on->format('M j, Y') }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm">{{ $tx->type->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                @if ($tx->trainingSession)
                                    <flux:link :href="route('sessions.show', $tx->trainingSession)" wire:navigate>{{ $tx->description }}</flux:link>
                                @else
                                    {{ $tx->description }}
                                @endif
                                @if ($tx->isVoided())
                                    <span class="ml-1 text-xs">(voided {{ $tx->voided_at->format('M j') }})</span>
                                @endif
                                @if ($tx->edit_count > 0)
                                    <button type="button" wire:click="showHistory({{ $tx->id }})" class="ml-1 text-xs text-zinc-500 underline decoration-dotted underline-offset-2 hover:text-zinc-700 dark:hover:text-zinc-300">edited</button>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-xs">{{ $tx->payment_method?->label() }} {{ $tx->reference }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $tx->type->isMoneyMovement() || $tx->type->isCharge() ? money($tx->subtotal) : '' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ (float) $tx->gst_amount > 0 ? money($tx->gst_amount) : '' }}</flux:table.cell>
                            <flux:table.cell align="end"><x-money :amount="$tx->amount" signed class="font-medium" /></flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-1">
                                    @if ($tx->isEditable())
                                        <flux:button size="xs" variant="ghost" wire:click="editTransaction({{ $tx->id }})">Edit</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="voidTransaction({{ $tx->id }})" wire:confirm="Void this {{ strtolower($tx->type->label()) }} of {{ money($tx->amount) }}? It will be excluded from balances and reports.">Void</flux:button>
                                    @endif
                                    <flux:button size="xs" variant="ghost" icon="clock" wire:click="showHistory({{ $tx->id }})" title="Change history" aria-label="Change history" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>

    <section>
        <flux:heading size="lg" class="mb-3">Training history</flux:heading>
        @if ($attendances->isEmpty())
            <x-empty-state title="No sessions yet" />
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Service</flux:table.column>
                    <flux:table.column>Headcount</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column align="end">Charge</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($attendances as $attendance)
                        @php($session = $attendance->trainingSession)
                        <flux:table.row :key="$attendance->id">
                            <flux:table.cell><flux:link :href="route('sessions.show', $session)" wire:navigate>{{ $session->starts_at->format('D M j, Y g:i a') }}</flux:link></flux:table.cell>
                            <flux:table.cell>{{ $session->service->name }}</flux:table.cell>
                            <flux:table.cell>{{ \App\Models\Plan::headcountLabel($session->headcount()) }} ({{ $session->headcount() }})</flux:table.cell>
                            <flux:table.cell>
                                @if (! $attendance->attended)
                                    <flux:badge size="sm" color="zinc">No-show</flux:badge>
                                @else
                                    <flux:badge size="sm" :color="$session->status->color()">{{ $session->status->label() }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($session->isCompleted() && $attendance->attended)
                                    {{ (float) $attendance->total > 0 ? money($attendance->total) : 'Included' }}
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>

    <flux:modal name="record-payment" class="md:w-[28rem]">
        <form wire:submit="recordPayment" class="space-y-5">
            <div>
                <flux:heading size="lg">Record payment</flux:heading>
                <flux:subheading>{{ $client->isOnMonthlyPlan() ? 'Payment towards the monthly fee.' : 'Deposit into the Fitness Wallet. Amounts include GST.' }}</flux:subheading>
            </div>
            <flux:input wire:model="paymentAmount" label="Amount received" type="number" step="0.01" min="0.01" placeholder="0.00" />
            <flux:select wire:model="paymentMethod" label="Payment method">
                @foreach ($paymentMethods as $method)
                    <flux:select.option value="{{ $method->value }}">{{ $method->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="paymentDate" label="Date received" type="date" />
            <flux:input wire:model="paymentReference" label="Reference" placeholder="Cheque # or e-Transfer reference" />
            <flux:input wire:model="paymentNote" label="Note" placeholder="Optional" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Record payment</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="post-adjustment" class="md:w-[28rem]">
        <form wire:submit="postAdjustment" class="space-y-5">
            <div>
                <flux:heading size="lg">Adjustment or refund</flux:heading>
                <flux:subheading>Manual corrections to the ledger. Always leave a note.</flux:subheading>
            </div>
            <flux:radio.group wire:model.live="adjustmentKind" label="Kind">
                <flux:radio value="credit" label="Credit (add to balance)" />
                <flux:radio value="debit" label="Debit (deduct from balance)" />
                <flux:radio value="refund" label="Refund (money returned to client)" />
            </flux:radio.group>
            <flux:input wire:model="adjustmentAmount" label="Amount" type="number" step="0.01" min="0.01" placeholder="0.00" />
            @if ($adjustmentKind === 'refund')
                <flux:select wire:model="adjustmentMethod" label="Refunded by">
                    @foreach ($paymentMethods as $method)
                        <flux:select.option value="{{ $method->value }}">{{ $method->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:input wire:model="adjustmentDate" label="Date" type="date" />
            <flux:input wire:model="adjustmentNote" label="Note" placeholder="Why is this being posted?" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Post</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="edit-transaction" class="md:w-[30rem]">
        @if ($editing)
            <form wire:submit="updateTransaction" class="space-y-5">
                <div>
                    <flux:heading size="lg">Edit {{ strtolower($editing->type->label()) }}</flux:heading>
                    <flux:subheading>Posted {{ $editing->transacted_on->format('M j, Y') }}. Corrections are kept in this entry's change history.</flux:subheading>
                </div>

                @if ($editing->type === \App\Enums\TransactionType::Adjustment)
                    <flux:radio.group wire:model="editKind" label="Kind">
                        <flux:radio value="credit" label="Credit (add to balance)" />
                        <flux:radio value="debit" label="Debit (deduct from balance)" />
                    </flux:radio.group>
                @endif

                <flux:input
                    wire:model="editAmount"
                    :label="match ($editing->type) {
                        \App\Enums\TransactionType::MonthlyFee => 'Fee before GST',
                        \App\Enums\TransactionType::Refund => 'Amount refunded (GST included)',
                        \App\Enums\TransactionType::Payment => 'Amount received (GST included)',
                        default => 'Amount',
                    }"
                    type="number" step="0.01" min="0.01" placeholder="0.00"
                />

                @if ($editing->type->isMoneyMovement())
                    <flux:select wire:model="editMethod" :label="$editing->type === \App\Enums\TransactionType::Refund ? 'Refunded by' : 'Payment method'">
                        @foreach ($paymentMethods as $method)
                            <flux:select.option value="{{ $method->value }}">{{ $method->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="editReference" label="Reference" placeholder="Cheque # or e-Transfer reference" />
                @endif

                <flux:input wire:model="editDate" label="Date" type="date" />
                <flux:input wire:model="editDescription" label="Description" placeholder="Shown on the ledger and the client's wallet" />
                <flux:input wire:model="editReason" label="Reason for this change" placeholder="e.g. Client sent a corrected invoice" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save changes</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="transaction-history" class="md:w-[32rem]">
        @if ($history)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Change history</flux:heading>
                    <flux:subheading>{{ $history->description ?: $history->type->label() }} — {{ money($history->amount, true) }}</flux:subheading>
                </div>
                <ol class="space-y-4 text-sm">
                    @if ($history->revisions->firstWhere('action', \App\Models\WalletTransactionRevision::ACTION_CREATED) === null)
                        <li>
                            <div class="font-medium">Created</div>
                            <div class="text-xs text-zinc-500">{{ $history->created_at->format('M j, Y g:i a') }}</div>
                        </li>
                    @endif
                    @foreach ($history->revisions as $revision)
                        <li>
                            <div class="font-medium">
                                {{ $revision->actionLabel() }}
                                @if ($revision->changedBy)
                                    <span class="font-normal text-zinc-500">by {{ $revision->changedBy->name }}</span>
                                @endif
                            </div>
                            <div class="text-xs text-zinc-500">{{ $revision->created_at->format('M j, Y g:i a') }}</div>
                            @foreach ($revision->summaryLines() as $line)
                                <div class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $line }}</div>
                            @endforeach
                            @if ($revision->reason)
                                <div class="mt-1 text-zinc-500">Reason: {{ $revision->reason }}</div>
                            @endif
                        </li>
                    @endforeach
                </ol>
                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="ghost">Close</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
