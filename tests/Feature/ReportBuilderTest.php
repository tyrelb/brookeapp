<?php

use App\Actions\CompleteTrainingSession;
use App\Actions\PostAdjustment;
use App\Actions\PostMonthlyFee;
use App\Actions\RecordPayment;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Livewire\Reports\Annual;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\ReportBuilder;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5]);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $wallet = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $wallet->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $wallet->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 30]);
    $monthly = Plan::factory()->monthly(300, 1)->create(['user_id' => $this->trainer->id]);

    $this->ann = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $wallet->id]);
    $this->bob = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $wallet->id]);
    $this->cat = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $monthly->id, 'started_at' => '2026-01-01']);

    // September 2026: deposits, one partner session, one single session, a monthly fee and its payment, a refund.
    app(RecordPayment::class)->handle($this->ann, 300, PaymentMethod::ETransfer, '2026-09-01');
    app(RecordPayment::class)->handle($this->bob, 100, PaymentMethod::Cash, '2026-09-02');

    $partner = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-03 09:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $partner->id, 'client_id' => $this->ann->id]);
    SessionAttendee::factory()->create(['training_session_id' => $partner->id, 'client_id' => $this->bob->id]);
    app(CompleteTrainingSession::class)->handle($partner);

    $single = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-10 09:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $single->id, 'client_id' => $this->ann->id]);
    SessionAttendee::factory()->create(['training_session_id' => $single->id, 'client_id' => $this->cat->id, 'attended' => false]);
    app(CompleteTrainingSession::class)->handle($single);

    app(PostMonthlyFee::class)->handle($this->cat, '2026-09-01');
    app(RecordPayment::class)->handle($this->cat, 315, PaymentMethod::Cheque, '2026-09-05', '0042');
    app(PostAdjustment::class)->handle($this->bob, 21, 'Refund', TransactionType::Refund, '2026-09-20', PaymentMethod::Cash);

    // October: one more single session so annual totals differ from September.
    $oct = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-10-01 09:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $oct->id, 'client_id' => $this->bob->id]);
    app(CompleteTrainingSession::class)->handle($oct);

    // Noise from another trainer must not leak in.
    $other = User::factory()->create();
    $otherPlan = Plan::factory()->wallet()->create(['user_id' => $other->id]);
    $otherClient = Client::factory()->create(['user_id' => $other->id, 'plan_id' => $otherPlan->id]);
    app(RecordPayment::class)->handle($otherClient, 999, PaymentMethod::Cash, '2026-09-15');
});

it('builds the september monthly report', function () {
    $r = app(ReportBuilder::class)->monthly($this->trainer, 2026, 9);

    expect($r['label'])->toBe('September 2026')
        ->and($r['sessions']['count'])->toBe(2)
        ->and($r['sessions']['attendances'])->toBe(3)
        ->and($r['sessions']['by_service']['Personal Training']['sessions'])->toBe(2)
        ->and($r['sessions']['by_service']['Personal Training']['tiers'])->toBe(['Partner' => 1, 'Single' => 1])
        ->and($r['sessions']['by_service']['Personal Training']['revenue'])->toBe(120.00) // 30 + 30 + 60
        ->and($r['revenue']['sessions'])->toBe(120.00)
        ->and($r['revenue']['monthly_fees'])->toBe(300.00)
        ->and($r['revenue']['total'])->toBe(420.00)
        ->and($r['revenue']['gst'])->toBe(21.00) // 1.5 + 1.5 + 3 + 15
        ->and($r['revenue']['total_with_gst'])->toBe(441.00)
        ->and($r['payments']['by_method'])->toBe(['cash' => 100.00, 'cheque' => 315.00, 'etransfer' => 300.00])
        ->and($r['payments']['total'])->toBe(715.00)
        ->and($r['payments']['refunds'])->toBe(-21.00)
        ->and($r['payments']['net'])->toBe(694.00)
        ->and($r['payments']['gst_embedded'])->toBe(33.05) // 14.29 + 4.76 + 15.00 - 1.00
        ->and($r['balances']['prepaid'])->toBe(253.00) // ann 300-31.5-63 = 205.5 ; bob 100-31.5-21 = 47.5
        ->and($r['balances']['owing'])->toBe(0.0);
});

it('builds the annual report with per-month rows and totals', function () {
    $r = app(ReportBuilder::class)->annual($this->trainer, 2026);

    expect($r['months'])->toHaveCount(12)
        ->and($r['months'][8]['label'])->toBe('September 2026')
        ->and($r['months'][9]['revenue']['sessions'])->toBe(60.00)
        ->and($r['months'][9]['balances']['prepaid'])->toBe(205.50) // bob now 47.5 - 63 = -15.5 (owing); ann 205.5
        ->and($r['months'][9]['balances']['owing'])->toBe(-15.50)
        ->and($r['totals']['sessions']['count'])->toBe(3)
        ->and($r['totals']['revenue']['total'])->toBe(480.00)
        ->and($r['totals']['revenue']['gst'])->toBe(24.00)
        ->and($r['totals']['payments']['net'])->toBe(694.00)
        ->and($r['months'][0]['revenue']['total'])->toBe(0.0);
});

it('renders the report pages and exports csv', function () {
    $this->actingAs($this->trainer);

    $this->get(route('reports.monthly', ['month' => '2026-09']))
        ->assertOk()
        ->assertSee('September 2026')
        ->assertSee('$420.00');

    $this->get(route('reports.annual', ['year' => 2026]))
        ->assertOk()
        ->assertSee('Total 2026')
        ->assertSee('$480.00');

    $response = Livewire\Livewire::test(Annual::class, ['year' => 2026])
        ->call('exportCsv');

    $response->assertFileDownloaded('brookeapp-annual-report-2026.csv');
});
