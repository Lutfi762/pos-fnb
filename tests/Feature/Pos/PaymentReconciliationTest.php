<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Gateway\FakeGateway;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Gateway\SettlementLine;
use App\Domain\Sales\Models\GatewaySettlement;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentCharge;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\QrisPayments;
use App\Domain\Sales\ReceiveWebhook;
use App\Domain\Sales\ReconcileSettlement;
use App\Domain\Sales\StaticQrisReview;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();

    $this->qris = PaymentMethod::create([
        'name' => 'QRIS', 'type' => 'qris', 'mode' => PaymentMethod::GATEWAY_QRIS, 'opens_cash_drawer' => false, 'needs_reference' => false, 'fee_percent' => 0.7,
    ]);
    $this->standee = PaymentMethod::create([
        'name' => 'QRIS Statis', 'type' => 'qris', 'mode' => PaymentMethod::STATIC_QRIS, 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 0.7,
    ]);

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);
});

function billFor(object $test, int $total): Order
{
    return Order::factory()->for($test->outlet)->create(['user_id' => $test->cashier->id, 'subtotal' => $total, 'grand_total' => $total]);
}

function paidByQris(object $test, int $total): PaymentCharge
{
    $charge = app(QrisPayments::class)->start(billFor($test, $total), $test->qris, $test->cashier, $total);
    app(ReceiveWebhook::class)->handle('fake', app(PaymentGateway::class)->pay($charge));

    return $charge->refresh();
}

it('marks a static QRIS payment as waiting for a manager', function (): void {
    $payment = app(TakePayment::class)->handle(billFor($this, 30_000), $this->standee, $this->cashier, 30_000, reference: 'RRN 1234');

    expect($payment->verification)->toBe(Payment::UNVERIFIED);

    $cash = PaymentMethod::create(['name' => 'Tunai', 'type' => 'cash', 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0]);

    expect(app(TakePayment::class)->handle(billFor($this, 10_000), $cash, $this->cashier, 10_000)->verification)->toBeNull();
});

it('keeps the photo of a static QRIS payment off the public disk and shows it to managers', function (): void {
    Storage::fake('local');
    $order = billFor($this, 30_000);

    Livewire::test('pages::pos.payment', ['order' => $order])
        ->set('methodId', $this->standee->id)
        ->assertSee(__('qris.static_hint'))
        ->set('reference', 'RRN 998877')
        ->set('proof', UploadedFile::fake()->image('bukti.jpg', 600, 1200))
        ->call('take')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();

    expect($payment->proof_path)->toStartWith('payment-proofs/')
        ->and($payment->verification)->toBe(Payment::UNVERIFIED);
    Storage::disk('local')->assertExists($payment->proof_path);

    $this->get(route('payments.proof', $payment))->assertForbidden();
    $this->actingAs($this->manager)->get(route('payments.proof', $payment))->assertOk();
});

it('lets a manager confirm or reject static QRIS payments, with a reason to reject', function (): void {
    $found = app(TakePayment::class)->handle(billFor($this, 30_000), $this->standee, $this->cashier, 30_000, reference: 'RRN 1');
    $missing = app(TakePayment::class)->handle(billFor($this, 18_000), $this->standee, $this->cashier, 18_000, reference: 'RRN 2');

    $this->actingAs($this->manager);

    Livewire::test('pages::payments.review')
        ->assertSee('RRN 1')
        ->call('verify', $found->id)
        ->call('reject', $missing->id)
        ->assertHasErrors('notes.'.$missing->id)
        ->set('notes.'.$missing->id, 'Tidak ada di mutasi BCA')
        ->call('reject', $missing->id)
        ->assertSee(__('qris.review.static_empty'));

    expect($found->refresh()->verification)->toBe(Payment::VERIFIED)
        ->and($found->verified_by)->toBe($this->manager->id)
        ->and($missing->refresh()->verification)->toBe(Payment::REJECTED)
        ->and($missing->verification_note)->toBe('Tidak ada di mutasi BCA')
        ->and(AuditLog::query()->whereIn('action', ['payment.verified', 'payment.rejected'])->count())->toBe(2);

    expect(fn () => app(StaticQrisReview::class)->verify($found->refresh(), $this->manager))->toThrow(HttpException::class);
});

it('keeps the review page from cashiers', function (): void {
    $this->get(route('payments.review'))->assertForbidden();
});

it('matches the gateway settlement to the tills and records what was paid out', function (): void {
    $first = paidByQris($this, 50_000);
    $second = paidByQris($this, 20_000);

    $summary = app(ReconcileSettlement::class)->run(CarbonImmutable::today());

    expect($summary)->toMatchArray([
        'lines' => 2, 'matched' => 2, 'mismatch' => 0, 'unknown' => 0, 'missing' => 0,
        'gross' => 70_000, 'fees' => 350 + 140, 'net' => 70_000 - 490,
    ]);

    $payment = $first->payment()->sole();

    expect($payment->settled_amount)->toBe(49_650)
        ->and($payment->settled_fee)->toBe(350)
        ->and($payment->settled_at)->not->toBeNull();

    // Twice is the same as once.
    app(ReconcileSettlement::class)->run(CarbonImmutable::today());

    expect(GatewaySettlement::query()->count())->toBe(2);
});

it('flags a different amount, money the tills never saw, and payments the gateway never reported', function (): void {
    $matched = paidByQris($this, 50_000);
    $short = paidByQris($this, 20_000);
    $unreported = paidByQris($this, 15_000);

    app()->instance(PaymentGateway::class, new class((array) config('payments.fake'), [$matched, $short]) extends FakeGateway
    {
        public function __construct(array $config, private readonly array $charges)
        {
            parent::__construct($config);
        }

        public function settlements(CarbonInterface $from, CarbonInterface $to): array
        {
            [$matched, $short] = $this->charges;

            return [
                new SettlementLine('tx-1', $matched->reference, 50_000, 350, 49_650, 'SETTLED', now()),
                new SettlementLine('tx-2', $short->reference, 19_000, 133, 18_867, 'SETTLED', now()),
                new SettlementLine('tx-3', 'SOMEONE-ELSE', 12_000, 84, 11_916, 'SETTLED', now()),
            ];
        }
    });

    $summary = app(ReconcileSettlement::class)->run(CarbonImmutable::today());

    expect($summary)->toMatchArray(['lines' => 3, 'matched' => 1, 'mismatch' => 1, 'unknown' => 1, 'missing' => 1])
        ->and(GatewaySettlement::query()->where('provider_transaction_id', 'tx-2')->sole()->result)->toBe(GatewaySettlement::AMOUNT_MISMATCH)
        ->and(app(ReconcileSettlement::class)->missing(CarbonImmutable::today())->sole()->id)->toBe($unreported->id)
        ->and($short->payment()->sole()->settled_at)->toBeNull();

    $this->actingAs($this->manager);

    Livewire::test('pages::payments.review')
        ->set('date', now()->toDateString())
        ->assertSee(__('qris.review.results.amount_mismatch'))
        ->assertSee(__('qris.review.results.unknown'))
        ->assertSee($unreported->reference);
});

it('runs the reconciliation from the page and the command', function (): void {
    paidByQris($this, 50_000);

    $this->actingAs($this->manager);

    Livewire::test('pages::payments.review')
        ->set('date', now()->toDateString())
        ->call('runSettlement')
        ->assertSee(__('qris.review.ran', ['lines' => 1, 'matched' => 1, 'mismatch' => 0, 'unknown' => 0, 'missing' => 0]));

    $this->artisan('meja:reconcile-settlements', ['date' => now()->toDateString()])->assertSuccessful();
});
