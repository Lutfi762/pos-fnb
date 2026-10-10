<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exports too big to build while someone waits on the page. The file
        // is kept for a day, then removed; the row stays as the record.
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('format');
            $table->date('from_date');
            $table->date('to_date');
            $table->json('filters')->nullable();
            $table->string('status')->default('queued');
            $table->unsignedInteger('rows')->default(0);
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
