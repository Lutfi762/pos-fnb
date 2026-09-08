<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('area')->nullable();
            $table->unsignedSmallInteger('seats')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['outlet_id', 'name']);
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('type');
            $table->boolean('opens_cash_drawer')->default(false);
            $table->boolean('needs_reference')->default(false);

            // Merchant fee withheld by the provider, as a percent of the amount.
            $table->decimal('fee_percent', 5, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('discounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->string('type');
            $table->bigInteger('value')->default(0);
            $table->bigInteger('min_subtotal')->default(0);
            $table->bigInteger('max_deduction')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->bigInteger('opening_cash')->default(0);
            $table->bigInteger('closing_cash')->nullable();

            // Filled at close so the variance survives even if orders change later.
            $table->bigInteger('expected_cash')->nullable();
            $table->bigInteger('cash_difference')->nullable();

            $table->text('note')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'closed_at']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('number');
            $table->string('type');
            $table->string('status');
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->string('customer_name')->nullable();
            $table->text('note')->nullable();

            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('service_charge_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('rounding')->default(0);
            $table->bigInteger('grand_total')->default(0);
            $table->bigInteger('paid_total')->default(0);
            $table->bigInteger('change_total')->default(0);

            // Snapshot of the outlet settings at ring-up time, so changing a
            // setting later never rewrites an order that is already closed.
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('service_charge_percent', 5, 2)->default(0);
            $table->boolean('price_includes_tax')->default(true);

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->index(['outlet_id', 'status']);
            $table->index(['outlet_id', 'paid_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Names and prices are copied, never joined: menus get renamed and
            // repriced, but a receipt must stay what the guest actually saw.
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('station')->nullable();

            $table->decimal('quantity', 12, 3)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('modifier_total')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('line_total')->default(0);

            $table->text('note')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('modifier_name');
            $table->string('group_name')->nullable();
            $table->bigInteger('price')->default(0);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();
        });

        Schema::create('order_discounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type');
            $table->bigInteger('value')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method_name');
            $table->bigInteger('amount');
            $table->bigInteger('tendered')->nullable();
            $table->bigInteger('change')->default(0);
            $table->bigInteger('fee_amount')->default(0);
            $table->string('reference')->nullable();
            $table->string('status')->default('captured');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cashier_shifts');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('dining_tables');
    }
};
