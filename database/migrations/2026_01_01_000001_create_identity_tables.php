<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            // Tax and service charge are per outlet: a mall branch is not a kiosk.
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('service_charge_percent', 5, 2)->default(0);
            $table->boolean('price_includes_tax')->default(true);

            // Rounds the payable total to the nearest N rupiah. 0 disables it.
            $table->unsignedInteger('cash_rounding')->default(0);

            $table->string('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table): void {
            // Six digit PIN for fast cashier switching, hashed like a password.
            $table->string('pin_hash')->nullable()->after('password');
            $table->boolean('is_active')->default(true)->after('pin_hash');
            $table->foreignUlid('last_outlet_id')->nullable()->after('is_active')
                ->constrained('outlets')->nullOnDelete();
            $table->softDeletes();
        });

        Schema::create('outlet_user', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['outlet_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_user');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_outlet_id');
            $table->dropColumn(['pin_hash', 'is_active', 'deleted_at']);
        });

        Schema::dropIfExists('outlets');
    }
};
