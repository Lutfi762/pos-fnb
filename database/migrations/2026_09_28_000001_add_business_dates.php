<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that get a business date, and the moment it is read from.
     */
    private const DATED = [
        'orders' => 'paid_at',
        'refunds' => 'refunded_at',
        'stock_movements' => 'moved_at',
        'cashier_shifts' => 'opened_at',
    ];

    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            // The outlet's own clock, and the hour its day starts. A bar that
            // closes at 02:00 still counts those two hours as the night before.
            $table->string('timezone')->default('Asia/Jakarta')->after('cash_rounding');
            $table->unsignedTinyInteger('day_starts_at')->default(4)->after('timezone');
        });

        foreach (array_keys(self::DATED) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->date('business_date')->nullable()->after('outlet_id');
                $table->index(['outlet_id', 'business_date'], $name.'_outlet_business_date_index');
            });
        }

        Schema::create('daily_sales_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');

            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('guests')->default(0);
            $table->decimal('items_sold', 12, 3)->default(0);

            // Whole rupiah, the same definitions as SalesReport.
            $table->bigInteger('gross')->default(0);
            $table->bigInteger('net_sales')->default(0);
            $table->bigInteger('discounts')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('service_charge')->default(0);
            $table->bigInteger('cost')->default(0);

            $table->bigInteger('cash')->default(0);
            $table->bigInteger('non_cash')->default(0);
            $table->bigInteger('fees')->default(0);

            $table->unsignedInteger('refunds')->default(0);
            $table->bigInteger('refunded')->default(0);
            $table->bigInteger('refunded_net')->default(0);

            $table->bigInteger('waste')->default(0);

            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'business_date']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sales_summaries');

        foreach (array_keys(self::DATED) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_outlet_business_date_index');
                $table->dropColumn('business_date');
            });
        }

        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'day_starts_at']);
        });
    }

    /**
     * Date what is already there with the defaults every outlet starts with.
     * Summaries are not written here: reports build any missing day the
     * first time it is asked for, and `meja:rebuild-summaries` does it in one go.
     */
    private function backfill(): void
    {
        $appZone = (string) config('app.timezone');

        foreach (self::DATED as $name => $column) {
            DB::table($name)
                ->whereNull('business_date')
                ->whereNotNull($column)
                ->select(['id', $column])
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($name, $column, $appZone): void {
                    foreach ($rows as $row) {
                        // Stored in the app's timezone; outlets start in Jakarta at 04:00.
                        $date = CarbonImmutable::parse((string) $row->{$column}, $appZone)
                            ->setTimezone('Asia/Jakarta')
                            ->subHours(4)
                            ->toDateString();

                        DB::table($name)->where('id', $row->id)->update(['business_date' => $date]);
                    }
                });
        }
    }
};
