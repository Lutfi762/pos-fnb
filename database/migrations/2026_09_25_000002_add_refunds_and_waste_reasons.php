<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money given back on a bill that was already settled. A refund is a
        // record of its own, dated the day it happened: yesterday's report
        // keeps yesterday's numbers, and today's shows the money going out.
        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method_name');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('reason');
            $table->boolean('restock')->default(false);
            $table->timestamp('refunded_at');
            $table->timestamps();
            $table->index(['outlet_id', 'refunded_at']);
        });

        Schema::create('refund_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('amount');
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->bigInteger('refunded_total')->default(0)->after('change_total');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            // Why stock was written off: spilled, expired, made wrong.
            $table->string('reason')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('refunded_total');
        });

        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
    }
};
