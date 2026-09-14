<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            // Default routing for the whole category. A product may override it,
            // so a hot chocolate can leave the drinks category for the kitchen.
            $table->string('station')->nullable()->after('color');

            // Target time to serve, in minutes. The kitchen timer counts down
            // to this, and the slowest line on a ticket sets the whole ticket.
            $table->unsignedSmallInteger('prep_minutes')->default(10)->after('station');
        });

        Schema::table('outlets', function (Blueprint $table): void {
            // Outlets without a screen fall back to printing a docket instead.
            $table->boolean('has_kitchen_display')->default(true)->after('cash_rounding');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            // The three moments the kitchen is measured on. Kept as separate
            // columns rather than a log: the board only ever asks for the last
            // one, and a ticket that goes back a column keeps its first send.
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('sent_at');
            $table->timestamp('ready_at')->nullable()->after('started_at');

            // Snapshot, like every other name on the line: recategorising a
            // product must not move a ticket that is already on the pass.
            $table->unsignedSmallInteger('prep_minutes')->default(10)->after('station');

            // The board reads by outlet and station, never by order.
            $table->index(['station', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['station', 'status']);
            $table->dropColumn(['sent_at', 'started_at', 'ready_at', 'prep_minutes']);
        });

        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn('has_kitchen_display');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['station', 'prep_minutes']);
        });
    }
};
