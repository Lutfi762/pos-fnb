<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->string('client_shift_key')->nullable()->unique()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table): void {
            $table->dropUnique(['client_shift_key']);
            $table->dropColumn('client_shift_key');
        });
    }
};
