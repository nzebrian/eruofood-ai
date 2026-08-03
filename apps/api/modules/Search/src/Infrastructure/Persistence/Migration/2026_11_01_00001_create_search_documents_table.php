<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run outside a transaction: the best-effort pgvector/pg_trgm setup below
     * may fail when the extension is unavailable, and on PostgreSQL a failed
     * statement aborts the surrounding transaction — which would roll back the
     * table creation itself. Without a wrapping transaction the table is
     * committed first and the optional acceleration is skipped cleanly.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('search_documents', function (Blueprint $table): void {
            // id is deterministic: "<type>:<sourceId>" so re-index upserts.
            $table->string('id')->primary();
            $table->string('type')->index();
            $table->uuid('source_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('search_text')->nullable(); // lower-cased title+desc+keywords
            $table->jsonb('keywords')->default('[]');
            $table->string('url')->nullable();
            $table->string('image')->nullable();
            $table->string('locale', 8)->default('en');
            $table->jsonb('facets')->default('{}');

            // Denormalised, indexed filter/sort columns (pushed down to SQL).
            $table->string('region')->nullable()->index();
            $table->string('cuisine')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->string('difficulty')->nullable();
            $table->uuid('restaurant_id')->nullable()->index();
            $table->uuid('vendor_id')->nullable()->index();
            $table->integer('popularity')->default(0)->index();
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->integer('prep_time_minutes')->nullable();
            $table->integer('calories')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Portable semantic vector (float list). Postgres additionally gets a
            // native pgvector column below for accelerated KNN recall.
            $table->jsonb('embedding')->default('[]');

            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['type', 'source_id']);
        });

        // Postgres acceleration: pgvector column + ivfflat index, and a GIN
        // trigram index for full-text prefiltering. Best-effort — if the
        // extension is unavailable the portable PHP path is used instead.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $dims = (int) config('search.embedding_dimensions', 64);
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
                DB::statement("ALTER TABLE search_documents ADD COLUMN embedding_vec vector({$dims})");
                DB::statement('CREATE INDEX search_documents_embedding_vec_idx ON search_documents USING ivfflat (embedding_vec vector_cosine_ops) WITH (lists = 100)');
            } catch (\Throwable) {
                // pgvector not installed — native vector search is disabled.
            }
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                DB::statement('CREATE INDEX search_documents_search_text_trgm_idx ON search_documents USING gin (search_text gin_trgm_ops)');
            } catch (\Throwable) {
                // pg_trgm not installed — LIKE prefiltering still works.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_documents');
    }
};
