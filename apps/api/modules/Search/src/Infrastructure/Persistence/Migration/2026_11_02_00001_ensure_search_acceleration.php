<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Forward migration for search acceleration (M38-DB-001, M38-VECTOR-001).
 *
 * ## Why this exists rather than an edit to the original
 *
 * `2026_11_01_00001_create_search_documents_table` creates the pgvector column,
 * the ivfflat index and the pg_trgm index inside
 * `try { … } catch (\Throwable) { }`. Rewriting history would not help anyone
 * whose database has already run it — their table exists, the acceleration may
 * not, and nothing would ever re-attempt it. So this runs forward, on installs
 * old and new alike, and is idempotent.
 *
 * ## What changed about failure
 *
 * The original swallowed the exception and continued as though the optimisation
 * existed. This one still does not abort the migration — a database without the
 * extension must remain deployable on the documented portable path — but it
 * **records the outcome loudly** with a stable code, and the runtime answer now
 * comes from {@see \EruoFood\Search\Infrastructure\Capability\SearchCapabilityProbe}
 * asking `pg_extension` and `pg_indexes` directly, not from the absence of an
 * exception during a migration that may have run months ago.
 *
 * The difference that matters: nothing downstream can now believe the
 * acceleration exists because this file did not throw.
 */
return new class () extends Migration {
    /**
     * `CREATE EXTENSION` can fail on a managed database, and on PostgreSQL a
     * failed statement aborts the surrounding transaction. Running outside one
     * keeps a partial success partial instead of rolling everything back.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite/MySQL have neither extension. The portable PHP path is the
            // documented behaviour there, not a degradation.
            return;
        }

        $dims = (int) config('search.embedding_dimensions', 64);

        $this->attempt('SEARCH_CAP_VECTOR_EXTENSION', 'CREATE EXTENSION IF NOT EXISTS vector');

        // Only meaningful if the extension took. Adding the column without it
        // fails, and that failure is reported rather than hidden.
        if ($this->hasExtension('vector')) {
            $this->attempt(
                'SEARCH_CAP_VECTOR_COLUMN',
                "ALTER TABLE search_documents ADD COLUMN IF NOT EXISTS embedding_vec vector({$dims})",
            );
            $this->attempt(
                'SEARCH_CAP_VECTOR_INDEX',
                'CREATE INDEX IF NOT EXISTS search_documents_embedding_vec_idx '
                .'ON search_documents USING ivfflat (embedding_vec vector_cosine_ops) WITH (lists = 100)',
            );
        }

        $this->attempt('SEARCH_CAP_TRGM_EXTENSION', 'CREATE EXTENSION IF NOT EXISTS pg_trgm');

        if ($this->hasExtension('pg_trgm')) {
            $this->attempt(
                'SEARCH_CAP_TRGM_INDEX',
                'CREATE INDEX IF NOT EXISTS search_documents_search_text_trgm_idx '
                .'ON search_documents USING gin (search_text gin_trgm_ops)',
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Drop only what this migration adds. The extensions are database-wide
        // and may be in use elsewhere, so they are deliberately left alone.
        DB::statement('DROP INDEX IF EXISTS search_documents_embedding_vec_idx');
        DB::statement('DROP INDEX IF EXISTS search_documents_search_text_trgm_idx');
    }

    /**
     * Run one provisioning statement, and say plainly what happened.
     *
     * This is not a swallowed exception: the outcome is logged with a stable
     * code an operator can alert on, and the authority on whether the
     * capability exists is the runtime probe, not this method's silence.
     */
    private function attempt(string $code, string $sql): void
    {
        try {
            DB::statement($sql);
            Log::info($code, ['code' => $code, 'status' => 'provisioned']);
        } catch (\Throwable $e) {
            Log::warning($code, [
                'code' => $code,
                'status' => 'unavailable',
                'reason' => $e->getMessage(),
                'consequence' => 'search runs the documented portable path; '
                    .'SearchCapabilityProbe reports this capability as unavailable',
            ]);
        }
    }

    private function hasExtension(string $name): bool
    {
        try {
            return DB::select('SELECT 1 FROM pg_extension WHERE extname = ? LIMIT 1', [$name]) !== [];
        } catch (\Throwable) {
            return false;
        }
    }
};
