<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('unit_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku')->nullable()->unique();
            $table->string('category')->nullable();

            // Weighted average cost per base unit, in whole rupiah.
            $table->bigInteger('average_cost')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recipe_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();

            $table->unique(['product_variant_id', 'inventory_item_id'], 'recipe_variant_item_unique');
        });

        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained()->cascadeOnDelete();

            // Running balance kept alongside the movement ledger so the POS can
            // check availability without summing every movement ever recorded.
            $table->decimal('quantity', 12, 3)->default(0);

            $table->decimal('reorder_point', 12, 3)->default(0);
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'inventory_item_id'], 'stock_outlet_item_unique');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');

            // Signed: negative for usage and waste, positive for receipts.
            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->bigInteger('unit_cost')->default(0);

            // Points back at whatever caused it (an order, a purchase, a count).
            $table->nullableUlidMorphs('source');

            $table->string('note')->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->index(['outlet_id', 'inventory_item_id', 'moved_at'], 'stock_movement_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('units');
    }
};
