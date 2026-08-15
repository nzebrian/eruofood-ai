<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optimistic locking for the delivery lifecycle.
 *
 * Before M26 a delivery's journey was advanced from one place — the rider
 * calling Marketplace's status endpoint — and a lost update there would at
 * worst skip a status nobody was watching. M26 adds a second writer: the
 * dispatch bridge, which advances the delivery when a rider accepts and when
 * they are reassigned away.
 *
 * Two writers on one row is exactly the situation the version column exists
 * for. Without it a rider marking "picked up" at the same instant an operator
 * reassigns the delivery would produce whichever write landed last, silently,
 * and the losing decision would simply vanish.
 *
 * The column lives in Dispatch's migration directory because Dispatch is why it
 * is needed, and lives *on Marketplace's table* because that is where the
 * delivery is — M26 decision 1 keeps the delivery in Marketplace rather than
 * copying it. It is a nullable-safe additive change: existing rows default to
 * 1 and nothing has to be rewritten.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_deliveries')) {
            return;
        }

        if (! Schema::hasColumn('marketplace_deliveries', 'version')) {
            Schema::table('marketplace_deliveries', function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }

        $this->widenStatusColumn();
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketplace_deliveries', 'version')) {
            Schema::table('marketplace_deliveries', function (Blueprint $table): void {
                $table->dropColumn('version');
            });
        }
    }

    /**
     * Make sure the new status names fit.
     *
     * `en_route_pickup` is fifteen characters. The column is a default-length
     * varchar and has ample room, but M25 shipped a bug of exactly this shape —
     * a key that SQLite truncated silently and PostgreSQL rejected outright,
     * which would have meant a 100% cache miss in production and nowhere else.
     * Asserting the width rather than assuming it costs one statement.
     */
    private function widenStatusColumn(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE marketplace_deliveries ALTER COLUMN status TYPE varchar(32)');
    }
};
