<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_operations', function (Blueprint $table): void {
            // Per-device order is part of idempotency: a later cash mutation
            // must never overtake a failed earlier mutation from this terminal.
            $table->unsignedBigInteger('sequence')->nullable()->after('operation_key');
            $table->unique(['device_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_operations', function (Blueprint $table): void {
            $table->dropUnique(['device_id', 'sequence']);
            $table->dropColumn('sequence');
        });
    }
};
