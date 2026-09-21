<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Minted by the browser. It lets a retry find the order created by
            // an earlier request whose response was lost.
            $table->string('client_order_key')->nullable()->unique()->after('id');

            // A queued edit must name the revision it was based on. The server
            // rejects an old revision rather than silently overwriting a till.
            $table->unsignedInteger('revision')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['client_order_key']);
            $table->dropColumn(['client_order_key', 'revision']);
        });
    }
};
