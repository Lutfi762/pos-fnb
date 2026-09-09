<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table): void {
            // Grid position on the floor plan. Kept as small integers rather
            // than pixels so the same plan renders on a phone and a laptop.
            $table->unsignedSmallInteger('pos_x')->default(0)->after('area');
            $table->unsignedSmallInteger('pos_y')->default(0)->after('pos_x');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Set when two tables are merged: this bill was absorbed by another.
            $table->foreignUlid('merged_into_order_id')->nullable()->after('dining_table_id')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_order_id');
        });

        Schema::table('dining_tables', function (Blueprint $table): void {
            $table->dropColumn(['pos_x', 'pos_y']);
        });
    }
};
