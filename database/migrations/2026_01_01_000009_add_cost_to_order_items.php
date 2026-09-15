<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // What the ingredients on this line cost, worked out from the
            // recipe at ring-up time. A snapshot like the name and the price:
            // restocking at a new price must not rewrite last month's margin.
            $table->bigInteger('cost_total')->default(0)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('cost_total');
        });
    }
};
