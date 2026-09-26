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
            // What the server answered the first time, so a replayed command
            // gets the same answer instead of a second side effect.
            $table->json('result')->nullable()->after('payload');
            // Applied, but something a manager should look at, such as a paid
            // total that no longer matches the server's price for the menu.
            $table->text('warning')->nullable()->after('error');
            $table->foreignId('resolved_by')->nullable()->after('processed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            $table->text('resolution_note')->nullable()->after('resolved_at');
        });

        // Money put into or taken out of the drawer without a sale: the change
        // float topped up, a gallon of water bought, parking paid.
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('client_key')->nullable()->unique();
            $table->string('direction');
            $table->bigInteger('amount');
            $table->string('reason');
            $table->timestamp('moved_at');
            $table->timestamps();
            $table->index(['cashier_shift_id', 'direction']);
        });

        // Who did what to money, stock, and settings. Read by an owner after
        // the fact, so every row keeps the values before and after.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('note')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['outlet_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('cash_movements');

        Schema::table('sync_operations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['result', 'warning', 'resolved_at', 'resolution_note']);
        });
    }
};
