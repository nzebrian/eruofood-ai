<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimistic-concurrency marker for the wallet aggregate.
 *
 * Row locking is the primary defence against a lost update, but it only works
 * where a developer remembered to take the lock. The version column turns a
 * missed lock from a silent overwrite into a loud {@see
 * \EruoFood\Shared\Domain\Exception\ConcurrencyConflict}: a write whose expected
 * version no longer matches the stored one affects zero rows and is rejected.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('payments_wallets', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payments_wallets', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
