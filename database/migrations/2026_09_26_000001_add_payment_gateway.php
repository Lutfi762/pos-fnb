<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            // manual: the cashier keys a reference. gateway_qris: the server
            // asks the gateway for a code and waits for it to be paid.
            // static_qris: the printed standee, checked later by a manager.
            $table->string('mode')->default('manual')->after('type');
        });

        // A QRIS method without a gateway was always the printed standee.
        DB::table('payment_methods')->where('type', 'qris')->update(['mode' => 'static_qris']);

        // One dynamic QRIS code shown for one bill.
        Schema::create('payment_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider');

            // What the gateway knows the code by, and what we sent it.
            $table->string('reference')->unique();
            $table->string('provider_charge_id')->nullable();
            $table->string('provider_payment_id')->nullable();

            $table->bigInteger('amount');
            $table->bigInteger('paid_amount')->default(0);

            // Money that arrived but could not go on the bill, because the
            // bill was settled another way while the code was still showing.
            $table->bigInteger('unapplied_amount')->default(0);

            $table->string('status')->default('pending');
            $table->text('qr_string')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_charge_id']);
            $table->index(['order_id', 'status']);
            $table->index(['outlet_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUlid('payment_charge_id')->nullable()->after('payment_method_id')->constrained()->nullOnDelete();

            // Static QRIS: the photo of the guest's banking app, and the
            // manager's check of it against the bank statement.
            $table->string('proof_path')->nullable()->after('reference');
            $table->string('verification')->nullable()->after('proof_path');
            $table->foreignId('verified_by')->nullable()->after('verification')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('verification_note')->nullable()->after('verified_at');

            // What the gateway actually paid out, from its settlement report.
            $table->bigInteger('settled_amount')->nullable()->after('fee_amount');
            $table->bigInteger('settled_fee')->nullable()->after('settled_amount');
            $table->timestamp('settled_at')->nullable()->after('settled_fee');

            $table->index('verification');
        });

        // Every webhook as it arrived, before anything reads it. When a guest
        // says "I paid" and the bill says otherwise, this is the evidence.
        Schema::create('gateway_webhooks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider');
            $table->string('event_id')->nullable();
            $table->string('event_type')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->longText('payload');
            $table->json('headers')->nullable();
            $table->string('status')->default('received');
            $table->string('error')->nullable();
            $table->foreignUlid('payment_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['status', 'created_at']);
        });

        // The gateway's own record of what it collected, one line per payment,
        // matched against ours by the reference we sent it.
        Schema::create('gateway_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->date('transaction_date');
            $table->string('provider_transaction_id');
            $table->string('reference')->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('fee');
            $table->bigInteger('net_amount');
            $table->string('settlement_status')->nullable();
            $table->timestamp('transacted_at')->nullable();
            $table->foreignUlid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result');
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['transaction_date', 'result']);
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignUlid('payment_charge_id')->nullable()->after('payment_method_id')->constrained()->nullOnDelete();
            $table->string('gateway_reference')->nullable()->after('payment_charge_id');
            $table->string('gateway_status')->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_charge_id');
            $table->dropColumn(['gateway_reference', 'gateway_status']);
        });

        Schema::dropIfExists('gateway_settlements');
        Schema::dropIfExists('gateway_webhooks');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['verification']);
            $table->dropConstrainedForeignId('payment_charge_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['proof_path', 'verification', 'verified_at', 'verification_note', 'settled_amount', 'settled_fee', 'settled_at']);
        });

        Schema::dropIfExists('payment_charges');

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
    }
};
